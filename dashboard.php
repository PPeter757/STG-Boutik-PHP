<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/* ======================================================
   🔒 TIMEOUT (10 minutes)
====================================================== */
$timeout = 600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: logout.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

/* ======================================================
   🔒 PROTECTION UTILISATEUR
====================================================== */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

/* ======================================================
   🔌 DEPENDANCES
====================================================== */
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/role_check.php';
checkRole(['administrateur']);

/* ======================================================
   👤 INFO UTILISATEUR
====================================================== */
$user_id = $_SESSION['user_id'];
$user_stmt = $pdo->prepare("SELECT user_prenom, user_nom, civilite, photo FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$prenom    = $user['user_prenom'] ?? 'Utilisateur';
$nom       = $user['user_nom'] ?? '';
$civilite  = $user['civilite'] ?? 'M.';
$photo     = !empty($user['photo']) ? "uploads/" . $user['photo'] : "uploads/avatar.png";

$hour = date('H');
$greeting = ($hour < 12) ? "Bonjour" : (($hour < 18) ? "Bon après-midi" : "Bonsoir");

/* ======================================================
   📊 STATISTIQUES GLOBALES
====================================================== */
$totalProduits = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
$totalClients  = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalVentes   = $pdo->query("SELECT COALESCE(SUM(total),0) FROM ventes")->fetchColumn();
$produitsFaibles = $pdo->query("SELECT COUNT(*) FROM produits WHERE quantite <= 5")->fetchColumn();

/* ======================================================
   🧾 VENTES DU MOIS (NECESSAIRE AVANT NOTIFICATIONS)
====================================================== */
$moisActuel = date('m');
$anneeActuelle = date('Y');

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(v.quantite * v.prix_vente), 0)
    FROM ventes ve
    JOIN vente_items v ON v.vente_id = ve.vente_id
    WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
      AND EXTRACT(YEAR FROM ve.date_vente) = :annee
      AND LOWER(ve.status) != 'annulée'
");
$stmt->execute(['mois' => $moisActuel, 'annee' => $anneeActuelle]);
$totalVentesMois = (float)$stmt->fetchColumn();

/* ======================================================
   💳 CLIENTS À CRÉDIT
====================================================== */
$startMonth = date('Y-m-01 00:00:00');
$endMonth   = date('Y-m-t 23:59:59');

$clientsCreditMois = $pdo->prepare("
    SELECT COUNT(DISTINCT client_id)
    FROM ventes
    WHERE date_vente BETWEEN :d AND :f
      AND LOWER(status) LIKE 'cr%'
");
$clientsCreditMois->execute(['d'=>$startMonth,'f'=>$endMonth]);
$clientsCreditMois = (int)$clientsCreditMois->fetchColumn();

$totalVentesCreditMois = $pdo->prepare("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE date_vente BETWEEN :d AND :f
      AND LOWER(status) LIKE 'cr%'
");
$totalVentesCreditMois->execute(['d'=>$startMonth,'f'=>$endMonth]);
$totalVentesCreditMois = (float)$totalVentesCreditMois->fetchColumn();

$totalVentesPayeesMois = $pdo->prepare("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE date_vente BETWEEN :d AND :f
      AND LOWER(status) LIKE 'pay%'
");
$totalVentesPayeesMois->execute(['d'=>$startMonth,'f'=>$endMonth]);
$totalVentesPayeesMois = (float)$totalVentesPayeesMois->fetchColumn();

/* ======================================================
   🔔 NOTIFICATIONS
====================================================== */
$notifications = [];

$pfaibles = $pdo->query("SELECT nom, quantite FROM produits WHERE quantite <= 5")->fetchAll(PDO::FETCH_ASSOC);
if (!empty($pfaibles)) {
    $notifications[] = [
        'type' => 'danger',
        'message' => "⚠️ Attention : " . count($pfaibles) . " produit(s) en rupture ou presque."
    ];
}

if ($totalVentesMois > 100000) {
    $notifications[] = [
        'type' => 'success',
        'message' => "🎉 Félicitations ! Les ventes du mois dépassent 100 000 HTG."
    ];
}

/* ======================================================
   📌 DERNIERS ÉLÉMENTS
====================================================== */
$produitsDerniers = $pdo->query("SELECT * FROM produits ORDER BY produit_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$clientsDerniers = $pdo->query("SELECT * FROM clients ORDER BY client_id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

$ventesRecentes = $pdo->query("
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    ORDER BY v.vente_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ======================================================
   📈 GRAPH 1 : VENTES MENSUELLES
====================================================== */
$query = $pdo->query("
    SELECT EXTRACT(MONTH FROM date_vente) AS mois, SUM(total) AS total
    FROM ventes
    GROUP BY 1
    ORDER BY 1
");

$labels = [];
$data = [];
while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $labels[] = date('F', mktime(0,0,0,$row['mois'],1));
    $data[]   = (float)$row['total'];
}

/* ======================================================
   📉 GRAPH 2 : MARGES PAR PRODUIT
====================================================== */
$produitsMarges = $pdo->query("
    SELECT nom, (prix_vente - prix_achat) AS marge
    FROM produits ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

$labelsMarges = array_column($produitsMarges, 'nom');
$marges = array_map('floatval', array_column($produitsMarges, 'marge'));

/* ======================================================
   📊 GRAPH 3 : VENTES PAR MOIS / PRODUITS
====================================================== */
$moisLabels = ["October", "November"];
$ventesParMois = [];

foreach ($moisLabels as $mois) {
    $moisNum = date('m', strtotime("1 $mois"));

    $stmt = $pdo->prepare("
        SELECT SUM(v.quantite * v.prix_vente) AS total
        FROM ventes ve
        JOIN vente_items v ON ve.vente_id = v.vente_id
        WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
          AND LOWER(ve.status) != 'annulée'
    ");
    $stmt->execute(['mois' => $moisNum]);
    $ventesParMois[] = (float)$stmt->fetchColumn();
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Dashboard - Gestion Boutique</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 flex">

<?php include 'includes/menu_lateral.php'; ?>

<main class="flex-1 ml-64 p-8 space-y-6">

<!-- =====================================================
     👋 MESSAGE BIENVENUE
===================================================== -->
<div class="bg-blue-100 border border-blue-300 rounded-lg shadow p-6 flex items-center gap-6">
    <img src="<?= $photo ?>" class="w-20 h-20 rounded-full border-2 border-blue-500 shadow-lg">
    <div>
        <h2 class="text-2xl font-bold text-blue-700">
            <?= "$greeting $civilite $prenom" ?> 👋
        </h2>
        <p class="text-blue-600">Heureux de vous revoir.</p>
    </div>
</div>

<!-- =====================================================
     🔔 NOTIFICATIONS
===================================================== -->
<div id="notifications" class="space-y-2 relative h-16 overflow-hidden">
<?php foreach ($notifications as $n): ?>
    <div class="absolute w-full p-4 rounded shadow
        <?= $n['type'] === 'danger'
            ? 'bg-red-100 border-l-4 border-red-500 text-red-700'
            : 'bg-green-100 border-l-4 border-green-500 text-green-700' ?>">
        <?= $n['message'] ?>
    </div>
<?php endforeach; ?>
</div>

<!-- =====================================================
     📊 STATISTIQUES
===================================================== -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6">

<!-- PRODUITS -->
<div class="bg-white p-5 rounded-xl shadow">
    <h2 class="text-gray-500 text-sm">Produits en stock</h2>
    <p class="text-2xl font-bold text-purple-600"><?= $totalProduits ?></p>
</div>

<!-- VENTES DU MOIS -->
<div class="bg-white p-5 rounded-xl shadow">
    <h2 class="text-gray-500 text-sm">Ventes du mois</h2>
    <p class="text-2xl font-bold text-green-600"><?= number_format($totalVentesMois, 2) ?> HTG</p>
</div>

<!-- CLIENTS CRÉDIT -->
<div class="bg-white p-5 rounded-xl shadow">
    <h2 class="text-gray-500 text-sm">Clients à crédit</h2>
    <p class="text-2xl font-bold text-blue-600"><?= $clientsCreditMois ?></p>
</div>

<!-- STOCK FAIBLE -->
<div class="bg-white p-5 rounded-xl shadow">
    <h2 class="text-gray-500 text-sm">Stock faible</h2>
    <p class="text-2xl font-bold text-red-600"><?= $produitsFaibles ?></p>
</div>

</div>

<!-- =====================================================
     TABLES RÉCENTES
===================================================== -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">

<!-- PRODUITS -->
<div class="bg-white p-6 rounded-xl shadow">
    <h3 class="text-lg font-semibold mb-4">Derniers produits</h3>
    <ul class="divide-y divide-gray-200">
        <?php foreach ($produitsDerniers as $p): ?>
        <li class="py-2 flex justify-between">
            <span><?= htmlspecialchars($p['nom']) ?></span>
            <span class="text-gray-500">Qty: <?= $p['quantite'] ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- CLIENTS -->
<div class="bg-white p-6 rounded-xl shadow">
    <h3 class="text-lg font-semibold mb-4">Derniers clients</h3>
    <ul class="divide-y divide-gray-200">
        <?php foreach ($clientsDerniers as $c): ?>
        <li class="py-2 flex justify-between">
            <span><?= htmlspecialchars($c['nom']." ".$c['prenom']) ?></span>
            <span class="text-gray-500"><?= htmlspecialchars($c['groupe']) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- VENTES -->
<div class="bg-white p-6 rounded-xl shadow">
    <h3 class="text-lg font-semibold mb-4">5 dernières ventes</h3>
    <ul class="divide-y divide-gray-200">
        <?php foreach ($ventesRecentes as $v): ?>
        <li class="py-2 flex justify-between">
            <span>#<?= $v['vente_id'] ?> - <?= htmlspecialchars($v['client_nom']." ".$v['client_prenom']) ?></span>
            <span class="text-green-600"><?= number_format($v['total'], 2) ?> HTG</span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

</div>

<!-- =====================================================
     GRAPHIQUES
===================================================== -->
<div class="bg-white p-6 rounded-xl shadow mt-6">
    <canvas id="chartVentes" height="120"></canvas>
</div>

<div class="bg-white p-6 rounded-xl shadow mt-6">
    <canvas id="chartMarges" height="120"></canvas>
</div>

<div class="bg-white p-6 rounded-xl shadow mt-6">
    <canvas id="chartVentesProduits" height="120"></canvas>
</div>

</main>

<!-- =====================================================
     SCRIPT NOTIFICATIONS
===================================================== -->
<script>
const notifContainer = document.getElementById('notifications');
const notifs = Array.from(notifContainer.children);

notifs.forEach((n, i) => n.style.top = `${i * 100}%`);

setInterval(() => {
    notifs.forEach((n, i) => {
        n.style.transition = 'top .5s';
        n.style.top = `${(i - 1) * 100}%`;
    });
    const first = notifs.shift();
    first.style.transition = 'none';
    first.style.top = `${(notifs.length) * 100}%`;
    notifs.push(first);
}, 4000);
</script>

<!-- =====================================================
     CHARTS PREMIUM
===================================================== -->
<script>
function createGradient(ctx, color) {
    const gradient = ctx.createLinearGradient(0,0,0,300);
    gradient.addColorStop(0, color + "AA");
    gradient.addColorStop(1, color + "10");
    return gradient;
}

/*** GRAPH VENTES ***/
const ctx1 = document.getElementById("chartVentes").getContext("2d");
new Chart(ctx1, {
    type: "line",
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            label: "Ventes HTG",
            data: <?= json_encode($data) ?>,
            fill: true,
            backgroundColor: createGradient(ctx1, "#3b82f6"),
            borderColor: "#1e40af",
            tension: .3,
            borderWidth: 3,
            pointRadius: 5
        }]
    },
    options: {
        plugins: { legend: { display:false } },
        scales: { y: { beginAtZero:true } }
    }
});

/*** GRAPH MARGES ***/
const ctx2 = document.getElementById("chartMarges").getContext("2d");
new Chart(ctx2, {
    type: "bar",
    data: {
        labels: <?= json_encode($labelsMarges) ?>,
        datasets: [{
            label: "Marge HTG",
            data: <?= json_encode($marges) ?>,
            backgroundColor: "#10b981",
            borderRadius: 6
        }]
    },
    options: {
        indexAxis: "y",
        plugins: { legend: { display:false } }
    }
});

/*** GRAPH VENTES PAR MOIS ***/
const ctx3 = document.getElementById("chartVentesProduits").getContext("2d");
new Chart(ctx3, {
    type: "bar",
    data: {
        labels: <?= json_encode($moisLabels) ?>,
        datasets: [{
            label: "Total HTG",
            data: <?= json_encode($ventesParMois) ?>,
            backgroundColor: ["#f59e0b", "#3b82f6"],
            borderRadius: 10
        }]
    },
    options: {
        plugins: { legend: { display:false } },
        scales: { y: { beginAtZero:true } }
    }
});
</script>

</body>
</html>
