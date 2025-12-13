<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Timeout automatique (ex : 10 minutes)
$timeout = 600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
  session_unset();
  session_destroy();
  header("Location: logout.php?timeout=1");
  exit;
}
$_SESSION['last_activity'] = time();

// Protection page connectée
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

// Empêcher le cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Vérification rôle si nécessaire
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/role_check.php';
checkRole(['administrateur']);

// --- Récupération des infos utilisateur ---
$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'];
$user_photo = $_SESSION['user_photo'] ?? 'avatar.png';

// --- Détermination du message de salutation ---
$hour = date('H');
if ($hour < 12) {
  $greeting = 'Bonjour';
} elseif ($hour < 18) {
  $greeting = 'Bon après-midi';
} else {
  $greeting = 'Bonsoir';
}

// --- Statistiques ---
$totalProduits   = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
$totalClients    = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalVentes     = $pdo->query("SELECT IFNULL(SUM(total),0) FROM ventes")->fetchColumn();
$produitsFaibles = $pdo->query("SELECT COUNT(*) FROM produits WHERE quantite <= 5")->fetchColumn();

// --- Derniers produits ---
$produitsDerniers = $pdo->query("
    SELECT * FROM produits ORDER BY produit_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// --- Derniers clients ---
$clientsDerniers = $pdo->query("
    SELECT * FROM clients ORDER BY client_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// --- Ventes récentes ---
$ventesRecentes = $pdo->query("
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    ORDER BY v.vente_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* =====================
   1️⃣ Ventes mensuelles 
   ===================== */
$query = $pdo->query("
    SELECT MONTH(date_vente) AS mois, SUM(total) AS total
    FROM ventes
    GROUP BY MONTH(date_vente)
    ORDER BY mois
");

$labels = [];   // utilisé pour le graphique principal des ventes
$data   = [];

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
  $labels[] = date('F', mktime(0, 0, 0, $row['mois'], 1)); // nom du mois
  $data[]   = (float)$row['total'];
}

/* ================================
   2️⃣ Marges par produit 
   ================================ */
$produitsMarges = $pdo->query("
    SELECT nom,
           prix_vente,
           prix_achat,
           (prix_vente - prix_achat) AS marge
    FROM produits
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

$labelsMarges = [];
$marges       = [];

foreach ($produitsMarges as $p) {
  $labelsMarges[] = $p['nom'];
  $marges[]       = (float)$p['marge'];
}

/* ================================
   3️⃣ Ventes mensuelles par produit 
   ================================ */

$moisLabels       = ["October", "November"]; // comme tu avais
$ventesParMois    = []; // total par mois
$produitsParMois  = []; // liste de produits par mois

foreach ($moisLabels as $mois) {

  $stmt = $pdo->prepare("
        SELECT p.nom AS produit,
               SUM(v.quantite * v.prix_vente) AS total
        FROM ventes ve
        JOIN vente_items v ON ve.vente_id = v.vente_id
        JOIN produits p ON v.produit_id = p.produit_id
        WHERE MONTH(ve.date_vente) = MONTH(STR_TO_DATE(:mois, '%M'))
          AND LOWER(ve.status) NOT IN ('annulée')
        GROUP BY p.produit_id
    ");
  $stmt->execute(['mois' => $mois]);
  $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $totalMois         = array_sum(array_column($result, 'total'));
  $listeProduitsMois = array_column($result, 'produit');

  $ventesParMois[]   = $totalMois;
  $produitsParMois[] = $listeProduitsMois;
}

/* ================================
   4️⃣ Total ventes du mois courant
   ================================ */
$moisActuel   = date('m');
$anneeActuelle = date('Y');

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(v.quantite * v.prix_vente), 0) AS total_mois
    FROM ventes ve
    JOIN vente_items v ON ve.vente_id = v.vente_id
    WHERE MONTH(ve.date_vente) = :mois
      AND YEAR(ve.date_vente)  = :annee
      AND LOWER(ve.status)    != 'annulée'
");
$stmt->execute([
  'mois'   => $moisActuel,
  'annee'  => $anneeActuelle
]);
$totalVentesMois = (float)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
  <meta charset="UTF-8">
  <title>Tableau de bord - Gestion Boutique</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 font-sans flex">

  <!-- Menu latéral -->
  <?php include 'includes/menu_lateral.php'; ?>

  <main class="flex-1 ml-64 p-8 space-y-6">

    <?php
    require_once 'includes/db.php';
    require_once 'includes/auth.php';


    // Récupération des infos utilisateur
    $user_id = $_SESSION['user_id'] ?? null;
    $user_stmt = $pdo->prepare("SELECT user_prenom, user_nom, civilite, photo FROM users WHERE user_id = ?");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    $user_prenom = $user['user_prenom'] ?? 'Utilisateur';
    $user_nom = $user['user_nom'] ?? '';
    $photo = !empty($user['photo']) ? 'uploads/' . $user['photo'] : 'uploads/avatar.png';
    $civilite = !empty($user['civilite']) ? $user['civilite'] : 'M.';

    // Calcul des ventes du mois
    $mois_actuel = date('Y-m');
    $stmtVentes = $pdo->prepare("SELECT SUM(total) AS total_mois FROM ventes WHERE DATE_FORMAT(date_vente, '%Y-%m') = ?");
    $stmtVentes->execute([$mois_actuel]);
    $moisActuel = $stmtVentes->fetchColumn() ?: 0;

    // Calcul des produits faibles
    $stmtFaibles = $pdo->query("SELECT nom, quantite FROM produits WHERE quantite <= 5");
    $produitsFaiblesList = $stmtFaibles->fetchAll(PDO::FETCH_ASSOC);
    $produitsFaibles = count($produitsFaiblesList);

    // Notifications dynamiques
    $notifications = [];

    // Message de félicitation si ventes > 100000
    if ($totalVentesMois > 100000) {
      $notifications[] = [
        'type' => 'success',
        'message' => "🎉 Félicitations $civilite $user_prenom ! Vos ventes de ce mois dépassent 100 000 HTG."
      ];
    }

    // Alerte stock faible
    if ($produitsFaibles > 0) {
      $liste = implode(', ', array_column($produitsFaiblesList, 'nom'));
      $notifications[] = [
        'type' => 'danger',
        'message' => "⚠️ Attention ! $produitsFaibles produit(s) sont en rupture de stock."
      ];
    }
    ?>
    <!-- Message de bienvenue -->
    <div class="bg-blue-100 border border-blue-300 rounded-lg shadow p-6 flex items-center gap-6">
      <img src="<?= $photo ?>"
        class="w-20 h-20 rounded-full border-2 border-blue-500 shadow-lg"
        alt="Profil"
        onerror="this.src='uploads/avatar.png'">

      <div>
        <h2 class="text-2xl font-bold text-blue-700">
          Bonjour <?= htmlspecialchars("$civilite $user_prenom") ?> 👋
        </h2>
        <p class="text-blue-600 text-sm">
          Bienvenue sur votre tableau de bord. Nous sommes ravis de vous revoir !
        </p>
        <a href="profil.php" class="mt-2 inline-block bg-blue-200 hover:bg-blue-300 text-blue-800 px-3 py-1 rounded">
          Modifier mon profil
        </a>
      </div>
    </div>
    <!-- Notifications dynamiques -->
    <div id="notifications" class="space-y-2 relative h-16 overflow-hidden mt-3">
      <?php foreach ($notifications as $n): ?>
        <div class="absolute w-full transition-all p-4 rounded shadow 
      <?= $n['type'] === 'danger'
          ? 'bg-red-100 border-l-4 border-red-500 text-red-700'
          : 'bg-green-100 border-l-4 border-green-500 text-green-700' ?>">
          <?= $n['message'] ?>
        </div>
      <?php endforeach; ?>
    </div>
    <!-- Cartes statistiques -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h2 class="text-gray-500 text-sm">Produits en Stock</h2>
        <p class="text-2xl font-bold text-purple-600"><?= $totalProduits ?> Produits</p>
        <p class="text-sm text-gray-500 mt-1">
          <?php
          // Fonction pour calculer la valeur du stock
          function calculerValeurStock($pdo, $type = 'achat')
          {
            if ($type !== 'achat' && $type !== 'vente') {
              throw new Exception("Le type doit être 'achat' ou 'vente'.");
            }

            $prixField = ($type === 'achat') ? 'prix_achat' : 'prix_vente';

            $sql = "SELECT SUM($prixField * quantite) AS valeur_stock FROM produits";
            $stmt = $pdo->query($sql);
            $result = $stmt->fetch();

            return $result['valeur_stock'];
          }

          // Calcul de la valeur du stock selon le prix de vente
          $valeurStockVente = calculerValeurStock($pdo, 'vente');
          echo "La valeur du Stock : " . number_format($valeurStockVente, 2) . " HTG";
          ?>
        </p>
      </div>
      <?php
      // 📅 Mois et année courants
      $moisActuel = date('m');
      $anneeActuelle = date('Y');

      // 💰 Total des ventes du mois (hors annulées)
      $totalVentesMois = $pdo->query(" SELECT COALESCE(SUM(total), 0) AS total
                                        FROM ventes
                                        WHERE MONTH(date_vente) = $moisActuel
                                        AND YEAR(date_vente) = $anneeActuelle
                                        AND status NOT IN ('annulée')
                                    ")->fetchColumn();

      // 💰 Total des ventes de l'année (hors annulées)
      $totalVentesAnnee = $pdo->query(" SELECT COALESCE(SUM(total), 0) AS total
                                        FROM ventes
                                        WHERE YEAR(date_vente) = $anneeActuelle
                                          AND status NOT IN ('annulée')
                                    ")->fetchColumn();
      ?>
      <!-- 💰 Bloc d’affichage -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition relative group">
        <h2 class="text-gray-500 text-sm">Les ventes du mois de (<?= date('F Y') ?>) s’élèvent à</h2>
        <p class="text-2xl font-bold text-green-600">
          <?= number_format($totalVentesMois, 2) ?> HTG
        </p>

        <!-- Tooltip -->
        <div class="rounded-xl absolute top-full mb-2 left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs rounded px-2 py-1 opacity-0 group-hover:opacity-100 transition">
          Total annuel : <?= number_format($totalVentesAnnee, 2) ?> HTG
        </div>
      </div>

      <?php
      // Date début et fin du mois courant
      $startMonth = date('Y-m-01 00:00:00');
      $endMonth   = date('Y-m-t 23:59:59');

      // 1️⃣ Nombre total de clients ayant acheté à crédit ce mois-ci
      $stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT v.client_id) 
    FROM ventes v
    WHERE v.date_vente BETWEEN :startMonth AND :endMonth
      AND LOWER(v.status) LIKE 'cr%'
");
      $stmt->execute([':startMonth' => $startMonth, ':endMonth' => $endMonth]);
      $clientsCreditMois = (int)$stmt->fetchColumn();

      // 2️⃣ Montant total des ventes à crédit ce mois-ci
      $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total),0) 
    FROM ventes 
    WHERE date_vente BETWEEN :startMonth AND :endMonth
      AND LOWER(status) LIKE 'cr%'
");
      $stmt->execute([':startMonth' => $startMonth, ':endMonth' => $endMonth]);
      $totalVentesCreditMois = (float)$stmt->fetchColumn();

      // 3️⃣ Montant total des ventes payées ce mois-ci
      $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total),0) 
    FROM ventes 
    WHERE date_vente BETWEEN :startMonth AND :endMonth
      AND LOWER(status) LIKE 'pay%'
");
      $stmt->execute([':startMonth' => $startMonth, ':endMonth' => $endMonth]);
      $totalVentesPayeesMois = (float)$stmt->fetchColumn();
      ?>
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition relative group">
        <h2 class="text-gray-500 text-sm">Clients à crédit (<?= date('F Y') ?>)</h2>

        <!-- Nombre total de clients affectés -->
        <p class="text-2xl font-bold text-blue-600">
          <?= $clientsCreditMois ?> clients
        </p>

        <!-- Montant total des ventes à crédit -->
        <p class="text-sm text-gray-500 mt-1">
          Montant à crédit : <span class="font-semibold text-yellow-600">
            <?= number_format($totalVentesCreditMois, 2) ?> HTG
          </span>
        </p>

        <!-- Montant total des ventes payées -->
        <p class="text-sm text-gray-500 mt-1">
          Montant payé : <span class="font-semibold text-green-600">
            <?= number_format($totalVentesPayeesMois, 2) ?> HTG
          </span>
        </p>
      </div>


      <!-- Stock faible -->
      <?php
      // 🔹 Récupérer les produits à stock faible (ex: moins de 10 unités)
      $stmt = $pdo->query("SELECT nom, quantite FROM produits WHERE quantite < 10 ORDER BY quantite ASC");
      $produitsFaiblesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
      $produitsFaibles = count($produitsFaiblesList);

      // 🔹 Construire le texte du tooltip
      $tooltipText = '';
      foreach ($produitsFaiblesList as $p) {
        $tooltipText .= "{$p['nom']} ({$p['quantite']})\n";
      }
      ?>
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition relative group">
        <h2 class="text-gray-500 text-sm">Stock faible</h2>
        <p class="text-2xl font-bold text-red-500"><?= $produitsFaibles ?> Produits</p>
        <div class="relative">
          <!-- Tooltip personnalisé en dessous -->
          <div class="absolute top-full left-1/2 -translate-x-1/2 mt-2 w-56 bg-blue-600 text-white text-xs rounded-lg p-2 opacity-0 group-hover:opacity-100 transition z-10">
            <ul class="list-disc ml-4 max-h-40 overflow-y-auto">
              <?php foreach ($produitsFaiblesList as $p): ?>
                <li><?= htmlspecialchars($p['nom']) ?> (<?= $p['quantite'] ?>)</li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <!-- Actions rapides -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition cursor-pointer">
        <h3 class="text-lg font-semibold text-gray-700 mb-2">👤 Mon profil</h3>
        <!-- Formulaire modification profil (caché par défaut) -->
        <div id="profilForm" class="bg-white p-5 rounded-xl shadow mt-4 hidden">
          <h3 class="text-lg font-semibold text-gray-700 mb-4">Modifier mon profil</h3>
          <form id="updateProfileForm" method="post" enctype="multipart/form-data">
            <div class="mb-3">
              <label class="block text-gray-600 mb-1">Nom</label>
              <input type="text" name="new_name" value="<?= htmlspecialchars($user_name) ?>" class="border p-2 w-full rounded" required>
            </div>
            <div class="mb-3">
              <label class="block text-gray-600 mb-1">Photo de profil</label>
              <input type="file" name="new_photo" accept="image/*" class="border p-2 w-full rounded" onchange="previewPhoto(event)">
              <img id="photoPreview" src="uploads/<?= htmlspecialchars($user_photo) ?>" class="w-20 h-20 mt-2 rounded-full border" alt="Prévisualisation">
            </div>
            <div class="flex gap-2">
              <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Enregistrer</button>
              <button type="button" onclick="document.getElementById('profilForm').classList.add('hidden')" class="bg-gray-400 text-white px-4 py-2 rounded hover:bg-gray-500">Annuler</button>
            </div>
          </form>
        </div>

        <script>
          function previewPhoto(event) {
            const reader = new FileReader();
            reader.onload = function() {
              document.getElementById('photoPreview').src = reader.result;
            }
            reader.readAsDataURL(event.target.files[0]);
          }
        </script>

        <p class="text-gray-500 mb-2">Modifier vos informations personnelles.</p>
        <a href="profil.php" class="text-blue-600 hover:underline">Accéder</a>
      </div>
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition cursor-pointer">
        <h3 class="text-lg font-semibold text-gray-700 mb-2">➕ Ajouter un client</h3>
        <p class="text-gray-500 mb-2">Ajouter rapidement un nouveau client.</p>
        <a href="clients.php" class="text-blue-600 hover:underline">Ajouter</a>
      </div>
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition cursor-pointer">
        <h3 class="text-lg font-semibold text-gray-700 mb-2">🛒 Nouvelle vente</h3>
        <p class="text-gray-500 mb-2">Enregistrer rapidement une nouvelle vente.</p>
        <a href="ventes.php" class="text-blue-600 hover:underline">Créer</a>
      </div>
    </div>
    <!-- Sections des derniers ajouts -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
      <!-- Derniers produits -->
      <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-semibold mb-4">5 Derniers produits ajoutés</h3>
        <ul class="divide-y divide-gray-200">
          <?php foreach ($produitsDerniers as $p): ?>
            <li class="py-2 flex justify-between">
              <span><?= htmlspecialchars($p['nom']) ?></span>
              <span class="text-gray-500">Qty: <?= $p['quantite'] ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <!-- Derniers clients -->
      <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-semibold mb-4">5 Derniers clients</h3>
        <ul class="divide-y divide-gray-200">
          <?php foreach ($clientsDerniers as $c): ?>
            <li class="py-2 flex justify-between">
              <span><?= htmlspecialchars($c['nom'] . ' ' . $c['prenom']) ?></span>
              <span class="text-gray-500"><?= htmlspecialchars($c['groupe']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <!-- Ventes récentes -->
      <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-semibold mb-4">5 Ventes récentes</h3>
        <ul class="divide-y divide-gray-200">
          <?php foreach ($ventesRecentes as $v): ?>
            <li class="py-2 flex justify-between">
              <span>#<?= $v['vente_id'] ?> - <?= htmlspecialchars($v['client_nom'] . ' ' . $v['client_prenom'] ?? 'Inconnu') ?></span>
              <span class="text-green-600"><?= number_format($v['total'], 2) ?> HTG</span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </main>
  <script>
    const notifContainer = document.getElementById('notifications');
    const notifs = Array.from(notifContainer.children);

    notifs.forEach((n, i) => n.style.top = `${i * 100}%`);

    setInterval(() => {
      notifs.forEach((n, i) => {
        n.style.transition = 'top 0.5s';
        n.style.top = `${(i - 1) * 100}%`;
      });
      const first = notifs.shift();
      first.style.transition = 'none';
      first.style.top = `${(notifs.length) * 100}%`;
      notifs.push(first);
    }, 4000);

    // Durée d'inactivité en millisecondes
    const timeout = <?php echo $timeout * 1000; ?>;

    let timer;

    // Réinitialiser le timer à chaque interaction
    function resetTimer() {
      clearTimeout(timer);
      timer = setTimeout(() => {
        // Redirige vers logout.php ou recharge la page
        window.location.href = 'logout.php?timeout=1';
      }, timeout);
    }

    // Événements pour détecter activité
    window.onload = resetTimer;
    document.onmousemove = resetTimer;
    document.onkeypress = resetTimer;
    document.onclick = resetTimer;
    document.onscroll = resetTimer;
  </script>

</body>

</html>