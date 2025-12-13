<?php
/* ======================================================
   SESSION & SÉCURITÉ (IDENTIQUE À TON ANCIEN CODE)
====================================================== */
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

// Protection page connectée
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Empêcher le cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Dépendances
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/role_check.php';
checkRole(['administrateur']);

/* ======================================================
   INFOS UTILISATEUR
====================================================== */
$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'];
$user_photo = $_SESSION['user_photo'] ?? 'avatar.png';

/* ======================================================
   MESSAGE DE SALUTATION
====================================================== */
$hour = date('H');
if ($hour < 12) {
    $greeting = 'Bonjour';
} elseif ($hour < 18) {
    $greeting = 'Bon après-midi';
} else {
    $greeting = 'Bonsoir';
}

/* ======================================================
   STATISTIQUES GLOBALES (POSTGRES OK)
====================================================== */
$totalProduits = (int)$pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
$totalClients  = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalVentes   = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM ventes")->fetchColumn();
$produitsFaibles = (int)$pdo->query("SELECT COUNT(*) FROM produits WHERE quantite <= 5")->fetchColumn();

/* ======================================================
   DERNIERS AJOUTS
====================================================== */
$produitsDerniers = $pdo->query("
    SELECT * FROM produits
    ORDER BY produit_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$clientsDerniers = $pdo->query("
    SELECT * FROM clients
    ORDER BY client_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$ventesRecentes = $pdo->query("
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom
    FROM ventes v
    LEFT JOIN clients c ON c.client_id = v.client_id
    ORDER BY v.vente_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ======================================================
   1️⃣ VENTES MENSUELLES (GRAPH PRINCIPAL)
====================================================== */
$query = $pdo->query("
    SELECT EXTRACT(MONTH FROM date_vente) AS mois,
           SUM(total) AS total
    FROM ventes
    GROUP BY mois
    ORDER BY mois
");

$labels = [];
$data   = [];

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $labels[] = date('F', mktime(0, 0, 0, (int)$row['mois'], 1));
    $data[]   = (float)$row['total'];
}

/* ======================================================
   2️⃣ MARGES PAR PRODUIT
====================================================== */
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

/* ======================================================
   3️⃣ VENTES MENSUELLES PAR PRODUIT
====================================================== */
$moisLabels      = ["October", "November"]; // IDENTIQUE
$ventesParMois   = [];
$produitsParMois = [];

foreach ($moisLabels as $mois) {

    $moisNum = date('m', strtotime("1 $mois"));

    $stmt = $pdo->prepare("
        SELECT p.nom AS produit,
               SUM(v.quantite * v.prix_vente) AS total
        FROM ventes ve
        JOIN vente_items v ON ve.vente_id = v.vente_id
        JOIN produits p ON p.produit_id = v.produit_id
        WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
          AND LOWER(ve.status) != 'annulée'
        GROUP BY p.nom
    ");
    $stmt->execute(['mois' => $moisNum]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ventesParMois[]   = array_sum(array_column($result, 'total'));
    $produitsParMois[] = array_column($result, 'produit');
}

/* ======================================================
   4️⃣ TOTAL VENTES MOIS COURANT
====================================================== */
$moisActuel  = date('m');
$anneeActuelle = date('Y');

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(v.quantite * v.prix_vente),0)
    FROM ventes ve
    JOIN vente_items v ON v.vente_id = ve.vente_id
    WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
      AND EXTRACT(YEAR  FROM ve.date_vente) = :annee
      AND LOWER(ve.status) != 'annulée'
");
$stmt->execute([
    'mois'  => $moisActuel,
    'annee'=> $anneeActuelle
]);
$totalVentesMois = (float)$stmt->fetchColumn();

/* ======================================================
   5️⃣ CLIENTS À CRÉDIT (MOIS COURANT)
====================================================== */
$startMonth = date('Y-m-01 00:00:00');
$endMonth   = date('Y-m-t 23:59:59');

$clientsCreditMois = (int)$pdo->query("
    SELECT COUNT(DISTINCT client_id)
    FROM ventes
    WHERE date_vente BETWEEN '$startMonth' AND '$endMonth'
      AND LOWER(status) LIKE 'cr%'
")->fetchColumn();

$totalVentesCreditMois = (float)$pdo->query("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE date_vente BETWEEN '$startMonth' AND '$endMonth'
      AND LOWER(status) LIKE 'cr%'
")->fetchColumn();

$totalVentesPayeesMois = (float)$pdo->query("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE date_vente BETWEEN '$startMonth' AND '$endMonth'
      AND LOWER(status) LIKE 'pay%'
")->fetchColumn();

/* ======================================================
   NOTIFICATIONS (CORRIGÉ)
====================================================== */
$notifications = [];

if ($produitsFaibles > 0) {
    $notifications[] = [
        'type' => 'danger',
        'message' => "⚠️ Attention ! $produitsFaibles produit(s) sont en stock faible."
    ];
}

if ($totalVentesMois > 100000) {
    $notifications[] = [
        'type' => 'success',
        'message' => "🎉 Félicitations ! Les ventes du mois dépassent 100 000 HTG."
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Tableau de bord - Gestion Boutique</title>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- Tailwind (comme avant) -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 font-sans flex">

<!-- MENU LATÉRAL -->
<?php include 'includes/menu_lateral.php'; ?>

<main class="flex-1 ml-64 p-8 space-y-6">

    <!-- =========================
         MESSAGE DE BIENVENUE
    ========================== -->
    <div class="bg-blue-100 border border-blue-300 rounded-lg shadow p-6 flex items-center gap-6">
        <img src="<?= htmlspecialchars($user_photo) ?>"
             class="w-20 h-20 rounded-full border-2 border-blue-500 shadow-lg"
             onerror="this.src='uploads/avatar.png'">

        <div>
            <h2 class="text-2xl font-bold text-blue-700">
                <?= $greeting ?> <?= htmlspecialchars($user_name) ?> 👋
            </h2>
            <p class="text-blue-600 text-sm">
                Bienvenue sur votre tableau de bord. Nous sommes ravis de vous revoir !
            </p>
            <a href="profil.php"
               class="mt-2 inline-block bg-blue-200 hover:bg-blue-300 text-blue-800 px-3 py-1 rounded">
                Modifier mon profil
            </a>
        </div>
    </div>

    <!-- =========================
         NOTIFICATIONS
    ========================== -->
    <?php if (!empty($notifications)): ?>
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
    <?php endif; ?>

    <!-- =========================
         CARTES STATISTIQUES
    ========================== -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">

        <div class="bg-white p-5 rounded-xl shadow">
            <h2 class="text-gray-500 text-sm">Produits en Stock</h2>
            <p class="text-2xl font-bold text-purple-600"><?= $totalProduits ?> Produits</p>
        </div>

        <div class="bg-white p-5 rounded-xl shadow">
            <h2 class="text-gray-500 text-sm">Ventes du mois</h2>
            <p class="text-2xl font-bold text-green-600">
                <?= number_format($totalVentesMois, 2) ?> HTG
            </p>
        </div>

        <div class="bg-white p-5 rounded-xl shadow">
            <h2 class="text-gray-500 text-sm">Clients à crédit</h2>
            <p class="text-2xl font-bold text-blue-600"><?= $clientsCreditMois ?> Clients</p>
        </div>

        <div class="bg-white p-5 rounded-xl shadow">
            <h2 class="text-gray-500 text-sm">Stock faible</h2>
            <p class="text-2xl font-bold text-red-500"><?= $produitsFaibles ?> Produits</p>
        </div>

    </div>

    <!-- =========================
         GRAPHIQUES (STRUCTURE ANCIENNE)
    ========================== -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="font-semibold mb-3">Ventes mensuelles</h3>
            <canvas id="chartVentes" height="120"></canvas>
        </div>

        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="font-semibold mb-3">Marges par produit</h3>
            <canvas id="chartMarges" height="120"></canvas>
        </div>

    </div>

    <div class="bg-white p-6 rounded-xl shadow">
        <h3 class="font-semibold mb-3">Ventes par mois</h3>
        <canvas id="chartVentesProduits" height="100"></canvas>
    </div>

    <!-- =========================
         LISTES (COMME AVANT)
    ========================== -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="font-semibold mb-4">Derniers produits</h3>
            <ul>
                <?php foreach ($produitsDerniers as $p): ?>
                    <li class="flex justify-between border-b py-2">
                        <span><?= htmlspecialchars($p['nom']) ?></span>
                        <span>Qty: <?= $p['quantite'] ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="font-semibold mb-4">Derniers clients</h3>
            <ul>
                <?php foreach ($clientsDerniers as $c): ?>
                    <li class="flex justify-between border-b py-2">
                        <span><?= htmlspecialchars($c['nom'].' '.$c['prenom']) ?></span>
                        <span><?= htmlspecialchars($c['groupe']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="bg-white p-6 rounded-xl shadow">
            <h3 class="font-semibold mb-4">Ventes récentes</h3>
            <ul>
                <?php foreach ($ventesRecentes as $v): ?>
                    <li class="flex justify-between border-b py-2">
                        <span>#<?= $v['vente_id'] ?> - <?= htmlspecialchars($v['client_nom'].' '.$v['client_prenom']) ?></span>
                        <span class="text-green-600"><?= number_format($v['total'], 2) ?> HTG</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

    </div>

</main>

<!-- =========================
     ANIMATION NOTIFICATIONS
========================== -->
<script>
const notifContainer = document.getElementById('notifications');
if (notifContainer) {
    const notifs = Array.from(notifContainer.children);
    notifs.forEach((n, i) => n.style.top = `${i * 100}%`);

    setInterval(() => {
        notifs.forEach((n, i) => {
            n.style.transition = 'top 0.5s';
            n.style.top = `${(i - 1) * 100}%`;
        });
        const first = notifs.shift();
        first.style.transition = 'none';
        first.style.top = `${notifs.length * 100}%`;
        notifs.push(first);
    }, 4000);
}
</script>
<script>
/* =========================
   GRAPH 1 — VENTES MENSUELLES
========================= */
const ctxVentes = document.getElementById('chartVentes');
if (ctxVentes) {
  new Chart(ctxVentes, {
    type: 'line',
    data: {
      labels: <?= json_encode($labels) ?>,
      datasets: [{
        label: 'Ventes mensuelles (HTG)',
        data: <?= json_encode($data) ?>,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37, 99, 235, 0.15)',
        borderWidth: 2,
        pointRadius: 4,
        fill: true,
        tension: 0.3
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: true }
      },
      scales: {
        y: { beginAtZero: true }
      }
    }
  });
}

/* =========================
   GRAPH 2 — MARGES PAR PRODUIT
========================= */
const ctxMarges = document.getElementById('chartMarges');
if (ctxMarges) {
  new Chart(ctxMarges, {
    type: 'bar',
    data: {
      labels: <?= json_encode($labelsMarges) ?>,
      datasets: [{
        label: 'Marge (HTG)',
        data: <?= json_encode($marges) ?>,
        backgroundColor: '#10b981'
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: true }
      },
      scales: {
        y: { beginAtZero: true }
      }
    }
  });
}

/* =========================
   GRAPH 3 — VENTES PAR MOIS
========================= */
const ctxVentesProduits = document.getElementById('chartVentesProduits');
if (ctxVentesProduits) {
  new Chart(ctxVentesProduits, {
    type: 'bar',
    data: {
      labels: <?= json_encode($moisLabels) ?>,
      datasets: [{
        label: 'Total des ventes (HTG)',
        data: <?= json_encode($ventesParMois) ?>,
        backgroundColor: ['#f59e0b', '#3b82f6']
      }]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: true }
      },
      scales: {
        y: { beginAtZero: true }
      }
    }
  });
}
</script>

</body>
</html>
