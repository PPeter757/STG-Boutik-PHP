<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Durée d'inactivité avant fermeture automatique (en secondes)
$timeout = 600; // 10 minutes — ajustable

// Vérifier si l'utilisateur est inactif
if (isset($_SESSION['last_activity'])) {
  if ((time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: logout.php?timeout=1");
    exit;
  }
}

// Mettre à jour l’activité
$_SESSION['last_activity'] = time();

// Empêcher le cache du navigateur après déconnexion
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Vérification connexion
if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

// Vérification rôle (si nécessaire)
require_once 'includes/db.php';
require_once 'includes/role_check.php';
checkRole(['administrateur', 'superviseur', 'vendeur', 'caissier']); // adapter selon la page

// --- Initialisation ---
$client_id = $_GET['edit'] ?? null;
$nom = $prenom = $groupe = $telephone = $adresse = '';
$messageText = '';
$messageType = 'success'; // success | error | warning

// --- Charger les données du client si modification ---
if ($client_id) {
  $stmt = $pdo->prepare("SELECT * FROM clients WHERE client_id=?");
  $stmt->execute([$client_id]);
  $c = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($c) {
    $nom = $c['nom'];
    $prenom = $c['prenom'];
    $groupe = $c['groupe'];
    $telephone = $c['telephone'];
    $adresse = $c['adresse'];
  } else {
    $client_id = null;
  }
}

// --- Traitement du formulaire ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nom = $_POST['nom'] ?? '';
  $prenom = $_POST['prenom'] ?? '';
  $groupe = $_POST['groupe'] ?? '';
  $telephone = $_POST['telephone'] ?? '';
  $adresse = $_POST['adresse'] ?? '';

  if ($nom && $prenom && $groupe && $telephone && $adresse) {
    try {
      if (!empty($_POST['client_id'])) {
        // Modifier client
        $stmt = $pdo->prepare("UPDATE clients SET nom=?, prenom=?, groupe=?, telephone=?, adresse=? WHERE client_id=?");
        $stmt->execute([$nom, $prenom, $groupe, $telephone, $adresse, $_POST['client_id']]);

        $messageType = "success";
        $messageText = "✅ Client modifié avec succès !";
      } else {
        // Ajouter client
        $stmt = $pdo->prepare("INSERT INTO clients (nom, prenom, groupe, telephone, adresse) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$nom, $prenom, $groupe, $telephone, $adresse]);

        $messageType = "success";
        $messageText = "✅ Client ajouté avec succès !";
      }

      $nom = $prenom = $groupe = $telephone = $adresse = '';
      $client_id = null;
    } catch (PDOException $e) {
      if ($e->getCode() == 23000) {
        $messageType = "error";
        $messageText = "⚠️ Ce numéro de téléphone existe déjà.";
        $telephone = '';
      } else {
        $messageType = "error";
        $messageText = "❌ Erreur : " . htmlspecialchars($e->getMessage());
      }
    }
  } else {
    $messageType = "warning";
    $messageText = "⚠️ Veuillez remplir tous les champs.";
  }
}

// --- Supprimer un client ---
if (isset($_GET['delete'])) {
  $del_id = $_GET['delete'];
  try {
    $stmt = $pdo->prepare("DELETE FROM clients WHERE client_id=?");
    $stmt->execute([$del_id]);

    // Message de succès suppression
    $messageType = "success";
    $messageText = "✅ Client supprimé avec succès !";

    // Facultatif : réinitialiser la recherche et pagination
    $search = '';
    $page = 1;
  } catch (PDOException $e) {
    $messageType = "error";
    $messageText = "❌ Impossible de supprimer ce client, il est utilisé dans une vente.";
  }
}


// --- Recherche et pagination ---
$search = trim($_GET['search'] ?? '');
$perPage = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$params = [];
$where = "1";

if ($search !== '') {
  $where = "(nom LIKE :q OR prenom LIKE :q OR groupe LIKE :q OR telephone LIKE :q)";
  $params[':q'] = "%$search%";
}

// Compter total
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Récup clients
$sql = "SELECT * FROM clients WHERE $where ORDER BY nom ASC LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);

foreach ($params as $k => $c) {
  $stmt->bindValue($k, $c, PDO::PARAM_STR);
}
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);

$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">

<head>
  <meta charset="UTF-8">
  <title>Gestion des Clients</title>
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Styles Toast -->
  <style>
    #toastContainer {
      position: fixed;
      top: 20px;
      right: 20px;
      display: flex;
      flex-direction: column;
      gap: 12px;
      z-index: 9999;
    }

    .toast {
      min-width: 260px;
      padding: 14px 18px;
      border-radius: 10px;
      color: #fff;
      font-weight: 500;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      display: flex;
      align-items: center;
      gap: 10px;
      opacity: 0;
      transform: translateX(100%);
      animation: slideIn 0.4s ease forwards, fadeOut 0.4s ease 4s forwards;
    }

    @keyframes slideIn {
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    @keyframes fadeOut {
      to {
        opacity: 0;
        transform: translateX(100%);
      }
    }

    .toast-success {
      background-color: #16a34a;
    }

    .toast-error {
      background-color: #dc2626;
    }

    .toast-warning {
      background-color: #d97706;
    }
  </style>
</head>

<body class="bg-gray-100 flex">

  <!-- Conteneur Toast -->
  <div id="toastContainer"></div>

  <!-- Menu latéral -->
  <?php include 'includes/menu_lateral.php'; ?>

  <main class="flex-1 ml-64 p-8">
    <h1 class="text-3xl font-bold text-blue-700 mb-6">👥 Gestion des Clients</h1>

    <!-- Formulaire ajout/modification -->
    <div class="bg-white p-6 rounded-lg shadow-md mb-6">
      <h2 class="text-xl font-semibold mb-4"><?= $client_id ? 'Modifier' : 'Ajouter' ?> un client</h2>
      <form method="post" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <input type="text" name="nom" placeholder="Nom" value="<?= htmlspecialchars($nom) ?>" class="border p-2 rounded" required>
        <input type="text" name="prenom" placeholder="Prénom" value="<?= htmlspecialchars($prenom) ?>" class="border p-2 rounded" required>
        <input type="text" name="groupe" placeholder="Groupe" value="<?= htmlspecialchars($groupe) ?>" class="border p-2 rounded" required>
        <input type="text" name="telephone" placeholder="Téléphone" value="<?= htmlspecialchars($telephone) ?>" class="border p-2 rounded" required>
        <input type="text" name="adresse" placeholder="Adresse" value="<?= htmlspecialchars($adresse) ?>" class="border p-2 rounded" required>

        <input type="hidden" name="client_id" value="<?= $client_id ?>">

        <div class="col-span-full flex justify-end gap-2 mt-2">
          <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded">💾 <?= $client_id ? 'Modifier' : 'Enregistrer' ?></button>
          <button type="button" onclick="window.location.href='clients.php'" class="bg-gray-400 text-white px-6 py-2 rounded">❌ Annuler</button>
        </div>
      </form>
    </div>

    <!-- Section recherche + tableau -->
    <section class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">

      <form method="GET" class="mb-6 flex flex-wrap gap-4 items-end bg-white p-4 rounded-lg shadow">
        <input type="text" name="search" placeholder="🔍 Rechercher..." value="<?= htmlspecialchars($search) ?>" class="w-full md:w-1/3 p-2 border rounded">
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Rechercher</button>
        <?php if ($search): ?>
          <a href="clients.php" class="bg-gray-400 text-white px-4 py-2 rounded">Réinitialiser</a>
        <?php endif; ?>
      </form>

      <div class="overflow-x-auto rounded-lg">
        <table class="min-w-full text-sm border rounded-lg">
          <thead class="bg-blue-600 text-white border-b uppercase text-xs">
            <tr>
              <th class="p-3 text-left">Nom</th>
              <th class="p-3 text-left">Prénom</th>
              <th class="p-3 text-left">Groupe</th>
              <th class="p-3 text-left">Téléphone</th>
              <th class="p-3 text-left">Adresse</th>
              <th class="p-3 text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($clients as $c): ?>
              <tr class="border-b hover:bg-gray-50">
                <td class="p-2"><?= htmlspecialchars($c['nom']) ?></td>
                <td class="p-2"><?= htmlspecialchars($c['prenom']) ?></td>
                <td class="p-2"><?= htmlspecialchars($c['groupe']) ?></td>
                <td class="p-2"><?= htmlspecialchars($c['telephone']) ?></td>
                <td class="p-2"><?= htmlspecialchars($c['adresse']) ?></td>
                <td class="p-2 flex justify-center space-x-3">
                  <a href="?edit=<?= $c['client_id'] ?>" class="bg-green-500 text-white px-3 py-1 rounded">✏️ Modifier</a>
                  <a href="?delete=<?= $c['client_id'] ?>" onclick="return confirm('Voulez-vous vraiment supprimer ce client ?')" class="bg-red-500 text-white px-3 py-1 rounded">🗑️ Supprimer</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <div class="mt-4 flex justify-center space-x-2">

        <?php
        $maxButtons = 5;
        $start = max(1, $page - 2);
        $end = min($totalPages, $start + $maxButtons - 1);

        // Ajuster le start si proche de la fin
        if ($end - $start + 1 < $maxButtons) {
          $start = max(1, $end - $maxButtons + 1);
        }

        // Bouton Précédent
        if ($page > 1) {
          echo '<a href="clients.php?page=' . ($page - 1) . '" class="px-3 py-1 rounded bg-white text-blue-600 border">&laquo; Précédent</a>';
        }

        // Première page + "..." si nécessaire
        if ($start > 1) {
          echo '<a href="clients.php?page=1" class="px-3 py-1 rounded bg-white text-blue-600 border">1</a>';
          if ($start > 2) {
            echo '<span class="px-3 py-1">…</span>';
          }
        }

        // Pages visibles
        for ($i = $start; $i <= $end; $i++) {
          $active = $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border';
          echo '<a href="clients.php?page=' . $i . '" class="px-3 py-1 rounded ' . $active . '">' . $i . '</a>';
        }

        // Dernière page + "..." si nécessaire
        if ($end < $totalPages) {
          if ($end < $totalPages - 1) {
            echo '<span class="px-3 py-1">…</span>';
          }
          echo '<a href="clients.php?page=' . $totalPages . '" class="px-3 py-1 rounded bg-white text-blue-600 border">' . $totalPages . '</a>';
        }

        // Bouton Suivant
        if ($page < $totalPages) {
          echo '<a href="clients.php?page=' . ($page + 1) . '" class="px-3 py-1 rounded bg-white text-blue-600 border">Suivant &raquo;</a>';
        }
        ?>

      </div>

    </section>
  </main>

  <!-- Scripts -->
  <script>
    function showToast(message, type = 'success') {
      const container = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = 'toast toast-' + type;
      toast.textContent = message;
      container.appendChild(toast);
      setTimeout(() => toast.remove(), 5000);
    }

    // Afficher le message PHP automatiquement
    <?php if (!empty($messageText)): ?>
      showToast(`<?= addslashes($messageText) ?>`, '<?= $messageType ?>');
    <?php endif; ?>

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