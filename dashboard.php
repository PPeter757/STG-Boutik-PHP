<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Timeout automatique (10 minutes)
$timeout = 600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
  session_unset();
  session_destroy();
  header("Location: logout.php?timeout=1");
  exit;
}
$_SESSION['last_activity'] = time();

// Protection : utilisateur connecté
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

// Empêcher le cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Chargement dépendances
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/role_check.php';
checkRole(['administrateur']);

// ==========================================
// 🔹 Informations utilisateur connecté
// ==========================================
$user_id = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'];
$user_photo = $_SESSION['user_photo'] ?? 'avatar.png';

// ==========================================
// 🔹 Message de salutation
// ==========================================
$hour = date('H');
$greeting = ($hour < 12) ? "Bonjour" : (($hour < 18) ? "Bon après-midi" : "Bonsoir");

// ==========================================
// 🔹 Statistiques globales
// ==========================================

// Total Produits
$totalProduits = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();

// Total Clients
$totalClients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();

// Total Ventes
$totalVentes = $pdo->query("SELECT COALESCE(SUM(total),0) FROM ventes")->fetchColumn();

// Produits faibles (<= 5)
$produitsFaibles = $pdo->query("SELECT COUNT(*) FROM produits WHERE quantite <= 5")->fetchColumn();

// ==========================================
// 🔹 Notifications dynamiques
// ==========================================

$notifications = [];

// Produits faibles → alerte
$pfaibles = $pdo->query("SELECT nom, quantite FROM produits WHERE quantite <= 5")
                 ->fetchAll(PDO::FETCH_ASSOC);

if (!empty($pfaibles)) {
    $notifications[] = [
        'type' => 'danger',
        'message' => "⚠️ Attention ! " . count($pfaibles) . " produit(s) sont en rupture ou presque."
    ];
}

// Ventes fortes du mois → félicitations
if ($totalVentesMois > 100000) {
    $notifications[] = [
        'type' => 'success',
        'message' => "🎉 Bravo ! Les ventes de ce mois dépassent 100 000 HTG."
    ];
}


// ==========================================
// 🔹 Derniers produits ajoutés
// ==========================================
$produitsDerniers = $pdo->query("
    SELECT * FROM produits ORDER BY produit_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 🔹 Derniers clients
// ==========================================
$clientsDerniers = $pdo->query("
    SELECT * FROM clients ORDER BY client_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 🔹 Dernières ventes
// ==========================================
$ventesRecentes = $pdo->query("
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    ORDER BY v.vente_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 🔹 1️⃣ Ventes mensuelles (graphique principal)
// ==========================================
$query = $pdo->query("
    SELECT EXTRACT(MONTH FROM date_vente) AS mois,
           SUM(total) AS total
    FROM ventes
    GROUP BY 1
    ORDER BY 1
");

$labels = [];
$data = [];

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
  $labels[] = date('F', mktime(0, 0, 0, $row['mois'], 1));
  $data[]   = (float)$row['total'];
}

// ==========================================
// 🔹 2️⃣ Marges par produit (graphique)
// ==========================================
$produitsMarges = $pdo->query("
    SELECT nom, prix_vente, prix_achat, (prix_vente - prix_achat) AS marge
    FROM produits ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

$labelsMarges = [];
$marges = [];

foreach ($produitsMarges as $p) {
  $labelsMarges[] = $p['nom'];
  $marges[] = (float)$p['marge'];
}

// ==========================================
// 🔹 3️⃣ Ventes mensuelles par produit (graphique)
// ==========================================

$moisLabels = ["October", "November"];
$ventesParMois = [];
$produitsParMois = [];

foreach ($moisLabels as $mois) {

  $moisNum = date('m', strtotime("1 $mois"));

  $stmt = $pdo->prepare("
        SELECT p.nom AS produit,
               SUM(v.quantite * v.prix_vente) AS total
        FROM ventes ve
        JOIN vente_items v ON ve.vente_id = v.vente_id
        JOIN produits p    ON p.produit_id = v.produit_id
        WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
          AND LOWER(ve.status) NOT IN ('annulée')
        GROUP BY p.produit_id
    ");

  $stmt->execute(['mois' => $moisNum]);
  $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $ventesParMois[]   = array_sum(array_column($result, 'total'));
  $produitsParMois[] = array_column($result, 'produit');
}

// ==========================================
// 🔹 4️⃣ Total des ventes du mois courant
// ==========================================
$moisActuel   = date('m');
$anneeActuelle = date('Y');

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(v.quantite * v.prix_vente), 0)
    FROM ventes ve
    JOIN vente_items v ON v.vente_id = ve.vente_id
    WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
      AND EXTRACT(YEAR  FROM ve.date_vente) = :annee
      AND LOWER(ve.status) != 'annulée'
");

$stmt->execute([
  'mois' => $moisActuel,
  'annee' => $anneeActuelle
]);

$totalVentesMois = (float)$stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
  <meta charset="UTF-8">
  <title>Tableau de bord - Gestion Boutique</title>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 font-sans flex">

  <!-- MENU LATÉRAL -->
  <?php include 'includes/menu_lateral.php'; ?>

  <main class="flex-1 ml-64 p-8 space-y-6">

    <!-- =======================
         MESSAGE BIENVENUE
    ======================== -->
    <?php
    $user_stmt = $pdo->prepare("
        SELECT user_prenom, user_nom, civilite, photo
        FROM users WHERE user_id = ?
    ");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    $prenom    = $user['user_prenom'] ?? 'Utilisateur';
    $nom       = $user['user_nom'] ?? '';
    $civilite  = $user['civilite'] ?? 'M.';
    $photo     = !empty($user['photo']) ? "uploads/" . $user['photo'] : "uploads/avatar.png";
    ?>

    <div class="bg-blue-100 border border-blue-300 rounded-lg shadow p-6 flex items-center gap-6">
      <img src="<?= $photo ?>"
        class="w-20 h-20 rounded-full border-2 border-blue-500 shadow-lg"
        onerror="this.src='uploads/avatar.png'">

      <div>
        <h2 class="text-2xl font-bold text-blue-700">
          <?= $greeting . " " . htmlspecialchars("$civilite $prenom") ?> 👋
        </h2>
        <p class="text-blue-600 text-sm">
          Bienvenue sur votre tableau de bord. Heureux de vous revoir !
        </p>
        <a href="profil.php"
          class="mt-2 inline-block bg-blue-200 hover:bg-blue-300 text-blue-800 px-3 py-1 rounded">
          Modifier mon profil
        </a>
      </div>
    </div>

    <!-- =======================
         NOTIFICATIONS
    ======================== -->
    <div id="notifications" class="space-y-2 relative h-16 overflow-hidden mt-3">

      <?php foreach ($notifications as $n): ?>
        <div class="absolute w-full transition-all p-4 rounded shadow 
          <?= ($n['type'] === 'danger')
            ? 'bg-red-100 border-l-4 border-red-500 text-red-700'
            : 'bg-green-100 border-l-4 border-green-500 text-green-700' ?>">
          <?= $n['message'] ?>
        </div>
      <?php endforeach; ?>

    </div>

    <!-- =======================
         STATISTIQUES
    ======================== -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

      <!-- PRODUITS EN STOCK -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h2 class="text-gray-500 text-sm">Produits en Stock</h2>
        <p class="text-2xl font-bold text-purple-600"><?= $totalProduits ?> Produits</p>
        <p class="text-sm text-gray-500 mt-1">
          <?php
          function valeurStock($pdo)
          {
            return $pdo->query("SELECT SUM(prix_vente * quantite) FROM produits")->fetchColumn();
          }
          echo "Valeur du Stock : " . number_format(valeurStock($pdo), 2) . " HTG";
          ?>
        </p>
      </div>

      <!-- VENTES MENSUELLES -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition relative group">
        <h2 class="text-gray-500 text-sm">Ventes du mois (<?= date('F Y') ?>)</h2>
        <p class="text-2xl font-bold text-green-600"><?= number_format($totalVentesMois, 2) ?> HTG</p>

        <div class="absolute top-full left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs rounded px-2 py-1 opacity-0 group-hover:opacity-100 transition">
          Total annuel : <?= number_format($totalVentesAnnee, 2) ?> HTG
        </div>
      </div>

      <!-- CLIENTS À CRÉDIT -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h2 class="text-gray-500 text-sm">Clients à crédit</h2>
        <p class="text-2xl font-bold text-blue-600"><?= $clientsCreditMois ?> Clients</p>
        <p class="text-sm text-gray-500 mt-1">
          Crédit total : <span class="text-yellow-600 font-bold">
            <?= number_format($totalVentesCreditMois, 2) ?> HTG
          </span>
        </p>
        <p class="text-sm text-gray-500 mt-1">
          Payé : <span class="text-green-600 font-bold">
            <?= number_format($totalVentesPayeesMois, 2) ?> HTG
          </span>
        </p>
      </div>

      <!-- STOCK FAIBLE -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition relative group">
        <h2 class="text-gray-500 text-sm">Stock faible</h2>
        <p class="text-2xl font-bold text-red-500"><?= $produitsFaibles ?> Produits</p>

        <div class="absolute top-full left-1/2 -translate-x-1/2 mt-2 w-56 bg-blue-600 text-white text-xs rounded-lg p-2 opacity-0 group-hover:opacity-100 transition z-10">
          <ul class="list-disc ml-4 max-h-40 overflow-y-auto">
            <?php
            $pfaibles = $pdo->query("SELECT nom, quantite FROM produits WHERE quantite < 10 ORDER BY quantite ASC")
              ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pfaibles as $p): ?>
              <li><?= htmlspecialchars($p['nom']) ?> (<?= $p['quantite'] ?>)</li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

    </div>

    <!-- =======================
         ACTIONS RAPIDES
    ======================== -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

      <!-- Profil -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h3 class="text-lg font-semibold text-gray-700 mb-2">👤 Mon profil</h3>
        <p class="text-gray-500 mb-2">Modifier vos informations personnelles.</p>
        <a href="profil.php" class="text-blue-600 hover:underline">Accéder</a>
      </div>

      <!-- Ajouter client -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h3 class="text-lg font-semibold text-gray-700 mb-2">➕ Ajouter un client</h3>
        <p class="text-gray-500 mb-2">Enregistrer rapidement un nouveau client.</p>
        <a href="clients.php" class="text-blue-600 hover:underline">Ajouter</a>
      </div>

      <!-- Nouvelle vente -->
      <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h3 class="text-lg font-semibold text-gray-700 mb-2">🛒 Nouvelle vente</h3>
        <p class="text-gray-500 mb-2">Créer une nouvelle vente.</p>
        <a href="ventes.php" class="text-blue-600 hover:underline">Créer</a>
      </div>

    </div>

    <!-- =======================
         LISTES (Produits / Clients / Ventes)
    ======================== -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

      <!-- Derniers produits -->
      <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-semibold mb-4">5 Derniers produits</h3>
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
              <span><?= htmlspecialchars($c['nom'] . " " . $c['prenom']) ?></span>
              <span class="text-gray-500"><?= htmlspecialchars($c['groupe']) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <!-- Dernières ventes -->
      <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="text-lg font-semibold mb-4">5 Ventes récentes</h3>
        <ul class="divide-y divide-gray-200">
          <?php foreach ($ventesRecentes as $v): ?>
            <li class="py-2 flex justify-between">
              <span>#<?= $v['vente_id'] ?> -
                <?= htmlspecialchars($v['client_nom'] . " " . $v['client_prenom']) ?>
              </span>
              <span class="text-green-600"><?= number_format($v['total'], 2) ?> HTG</span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

    </div>
  </main>

  <!-- =======================
       NOTIFICATIONS ANIMATION
  ======================== -->
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
  </script>
  <script>
    /* =====================
    DÉGRADÉS PREMIUM
===================== */
    function createGradient(ctx, color) {
      const gradient = ctx.createLinearGradient(0, 0, 0, 400);
      gradient.addColorStop(0, color + "99");
      gradient.addColorStop(1, color + "10");
      return gradient;
    }

    /* =====================
        GRAPH 1 — VENTES MENSUELLES
    ===================== */
    const ventesCtx = document.getElementById('chartVentes').getContext('2d');
    const ventesGradient = createGradient(ventesCtx, "#3b82f6");

    new Chart(ventesCtx, {
      type: 'line',
      data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
          label: "Montant des ventes (HTG)",
          data: <?= json_encode($data) ?>,
          fill: true,
          borderColor: "#2563eb",
          backgroundColor: ventesGradient,
          borderWidth: 3,
          pointBackgroundColor: "#1e40af",
          pointRadius: 5,
          tension: 0.35
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            display: false
          }
        },
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });

    /* =====================
        GRAPH 2 — MARGES PAR PRODUIT
    ===================== */
    const margesCtx = document.getElementById('chartMarges').getContext('2d');

    new Chart(margesCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($labelsMarges) ?>,
        datasets: [{
          label: "Marge (HTG)",
          data: <?= json_encode($marges) ?>,
          backgroundColor: "#10b981",
          borderRadius: 7
        }]
      },
      options: {
        indexAxis: 'y',
        plugins: {
          legend: {
            display: false
          }
        },
        scales: {
          x: {
            beginAtZero: true
          }
        }
      }
    });

    /* =====================
        GRAPH 3 — VENTES PAR MOIS PAR PRODUIT
    ===================== */
    const vpCtx = document.getElementById('chartVentesProduits').getContext('2d');

    new Chart(vpCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($moisLabels) ?>,
        datasets: [{
          label: "Ventes totales HTG",
          data: <?= json_encode($ventesParMois) ?>,
          backgroundColor: ["#f59e0b", "#3b82f6"],
          borderRadius: 10
        }]
      },
      options: {
        plugins: {
          legend: {
            display: false
          }
        },
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });
  </script>
</body>
</html>