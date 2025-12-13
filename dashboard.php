<?php
if (session_status() === PHP_SESSION_NONE) session_start();

/* ============================
   TIMEOUT & PROTECTION PAGE
=============================== */

$timeout = 600; // 10 min

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: logout.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/role_check.php';
checkRole(['administrateur']);

/* ============================
   VARIABLES UTILISATEUR
=============================== */

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'];
$user_photo = $_SESSION['user_photo'] ?? 'avatar.png';

/* ============================
   MESSAGE DE SALUTATION
=============================== */

$hour = date('H');
$greeting = ($hour < 12) ? "Bonjour" : (($hour < 18) ? "Bon après-midi" : "Bonsoir");

/* ============================
   STATISTIQUES PRINCIPALES
=============================== */

$totalProduits = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
$totalClients  = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalVentes   = $pdo->query("SELECT COALESCE(SUM(total),0) FROM ventes")->fetchColumn();
$produitsFaibles = $pdo->query("SELECT COUNT(*) FROM produits WHERE quantite <= 5")->fetchColumn();

/* ============================
   DERNIERS ENREGISTREMENTS
=============================== */

$produitsDerniers = $pdo->query("
    SELECT * FROM produits ORDER BY produit_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$clientsDerniers = $pdo->query("
    SELECT * FROM clients ORDER BY client_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$ventesRecentes = $pdo->query("
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    ORDER BY v.vente_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

/* ============================
   1️⃣ VENTES MENSUELLES (POSTGRESQL)
=============================== */

$query = $pdo->query("
    SELECT EXTRACT(MONTH FROM date_vente) AS mois,
           SUM(total) AS total
    FROM ventes
    GROUP BY EXTRACT(MONTH FROM date_vente)
    ORDER BY mois
");

$labels = [];
$data   = [];

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $labels[] = date('F', mktime(0, 0, 0, $row['mois'], 1));
    $data[]   = (float)$row['total'];
}

/* ============================
   2️⃣ MARGES PAR PRODUIT
=============================== */

$produitsMarges = $pdo->query("
    SELECT nom,
           prix_vente,
           prix_achat,
           (prix_vente - prix_achat) AS marge
    FROM produits
    ORDER BY nom
")->fetchAll(PDO::FETCH_ASSOC);

$labelsMarges = [];
$marges = [];

foreach ($produitsMarges as $p) {
    $labelsMarges[] = $p['nom'];
    $marges[]       = (float)$p['marge'];
}

/* ============================
   3️⃣ VENTES PAR PRODUIT (POSTGRES)
=============================== */

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
        JOIN produits p    ON v.produit_id = p.produit_id
        WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
          AND LOWER(ve.status) NOT IN ('annulée')
        GROUP BY p.produit_id
    ");
    $stmt->execute(['mois' => $moisNum]);

    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ventesParMois[]   = array_sum(array_column($result, 'total'));
    $produitsParMois[] = array_column($result, 'produit');
}

/* ============================
   4️⃣ TOTAL DU MOIS COURANT
=============================== */

$moisActuel     = date('m');
$anneeActuelle  = date('Y');

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(v.quantite * v.prix_vente), 0)
    FROM ventes ve
    JOIN vente_items v ON ve.vente_id = v.vente_id
    WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
      AND EXTRACT(YEAR  FROM ve.date_vente) = :annee
      AND LOWER(ve.status) != 'annulée'
");
$stmt->execute([
    'mois'  => $moisActuel,
    'annee' => $anneeActuelle
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

<!-- MENU -->
<?php include 'includes/menu_lateral.php'; ?>

<main class="flex-1 ml-64 p-8 space-y-6">

<?php
/* ============================
   INFO UTILISATEUR
=============================== */

$user_stmt = $pdo->prepare("SELECT user_prenom, user_nom, civilite, photo 
                            FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$user_prenom = $user['user_prenom'] ?? 'Utilisateur';
$user_nom    = $user['user_nom'] ?? '';
$photo       = !empty($user['photo']) ? 'uploads/' . $user['photo'] : 'uploads/avatar.png';
$civilite    = $user['civilite'] ?? 'M.';

/* Calcul ventes mois (PostgreSQL) */
$mois_actuel = date('Y-m');
$stmtVentes = $pdo->prepare("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE TO_CHAR(date_vente, 'YYYY-MM') = ?
");
$stmtVentes->execute([$mois_actuel]);
$moisActuel = $stmtVentes->fetchColumn() ?: 0;

/* Stock faible */
$stmtFaibles = $pdo->query("
    SELECT nom, quantite 
    FROM produits 
    WHERE quantite <= 5
");
$produitsFaiblesList = $stmtFaibles->fetchAll(PDO::FETCH_ASSOC);
$produitsFaibles = count($produitsFaiblesList);

/* Notifications */
$notifications = [];
if ($totalVentesMois > 100000) {
    $notifications[] = [
        'type' => 'success',
        'message' => "🎉 Félicitations $civilite $user_prenom ! Vos ventes dépassent 100 000 HTG."
    ];
}
if ($produitsFaibles > 0) {
    $notifications[] = [
        'type' => 'danger',
        'message' => "⚠️ $produitsFaibles produit(s) sont en rupture de stock."
    ];
}
?>

<!-- MESSAGE DE BIENVENUE -->
<div class="bg-blue-100 border border-blue-300 rounded-lg shadow p-6 flex items-center gap-6">
    <img src="<?= $photo ?>" class="w-20 h-20 rounded-full border-2 border-blue-500 shadow-lg" alt="Profil" onerror="this.src='uploads/avatar.png'">
    <div>
        <h2 class="text-2xl font-bold text-blue-700"><?= "$greeting $civilite $user_prenom" ?> 👋</h2>
        <p class="text-blue-600 text-sm">Bienvenue sur votre tableau de bord.</p>
        <a href="profil.php" class="mt-2 inline-block bg-blue-200 hover:bg-blue-300 text-blue-800 px-3 py-1 rounded">
            Modifier mon profil
        </a>
    </div>
</div>

<!-- NOTIFICATIONS -->
<div id="notifications" class="space-y-2 relative h-16 overflow-hidden mt-3">
<?php foreach ($notifications as $n): ?>
    <div class="absolute w-full transition-all p-4 rounded shadow 
        <?= $n['type']==='danger' ? 'bg-red-100 border-l-4 border-red-500 text-red-700' 
                                 : 'bg-green-100 border-l-4 border-green-500 text-green-700' ?>">
        <?= $n['message'] ?>
    </div>
<?php endforeach; ?>
</div>

<!-- STATISTIQUES -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-6">

    <!-- STOCK PRODUITS -->
    <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h2 class="text-gray-500 text-sm">Produits en Stock</h2>
        <p class="text-2xl font-bold text-purple-600"><?= $totalProduits ?> Produits</p>
        <p class="text-sm text-gray-500 mt-1">
            <?php
            function calculerValeurStock($pdo, $type='achat') {
                $field = ($type==='vente') ? 'prix_vente' : 'prix_achat';
                $stmt = $pdo->query("SELECT COALESCE(SUM($field * quantite),0) FROM produits");
                return $stmt->fetchColumn();
            }
            echo "La valeur du Stock : " . number_format(calculerValeurStock($pdo,'vente'),2) . " HTG";
            ?>
        </p>
    </div>

    <!-- VENTES MOIS -->
    <?php
    $anneeActuelle = date('Y');

    $totalVentesMois = $pdo->query("
        SELECT COALESCE(SUM(total),0)
        FROM ventes
        WHERE EXTRACT(MONTH FROM date_vente) = $moisActuel
          AND EXTRACT(YEAR  FROM date_vente) = $anneeActuelle
          AND LOWER(status) NOT IN ('annulée')
    ")->fetchColumn();

    $totalVentesAnnee = $pdo->query("
        SELECT COALESCE(SUM(total),0)
        FROM ventes
        WHERE EXTRACT(YEAR FROM date_vente) = $anneeActuelle
          AND LOWER(status) NOT IN ('annulée')
    ")->fetchColumn();
    ?>

    <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition relative group">
        <h2 class="text-gray-500 text-sm">Ventes du mois (<?= date('F Y') ?>)</h2>
        <p class="text-2xl font-bold text-green-600"><?= number_format($totalVentesMois,2) ?> HTG</p>

        <div class="absolute top-full left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs rounded px-2 py-1 opacity-0 group-hover:opacity-100 transition">
            Total annuel : <?= number_format($totalVentesAnnee,2) ?> HTG
        </div>
    </div>

    <!-- CLIENTS À CRÉDIT -->
    <?php
    $startMonth = date('Y-m-01 00:00:00');
    $endMonth   = date('Y-m-t 23:59:59');

    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT client_id)
        FROM ventes
        WHERE date_vente BETWEEN :d1 AND :d2
          AND LOWER(status) LIKE 'cr%'
    ");
    $stmt->execute(['d1'=>$startMonth,'d2'=>$endMonth]);
    $clientsCreditMois = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total),0)
        FROM ventes
        WHERE date_vente BETWEEN :d1 AND :d2
          AND LOWER(status) LIKE 'cr%'
    ");
    $stmt->execute(['d1'=>$startMonth,'d2'=>$endMonth]);
    $totalCredit = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total),0)
        FROM ventes
        WHERE date_vente BETWEEN :d1 AND :d2
          AND LOWER(status) LIKE 'pay%'
    ");
    $stmt->execute(['d1'=>$startMonth,'d2'=>$endMonth]);
    $totalPayes = $stmt->fetchColumn();
    ?>

    <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition">
        <h2 class="text-gray-500 text-sm">Clients à crédit (<?= date('F Y') ?>)</h2>
        <p class="text-2xl font-bold text-blue-600"><?= $clientsCreditMois ?></p>
        <p class="text-sm text-gray-500">Montant crédit : <b class="text-yellow-600"><?= number_format($totalCredit,2) ?> HTG</b></p>
        <p class="text-sm text-gray-500">Montant payé : <b class="text-green-600"><?= number_format($totalPayes,2) ?> HTG</b></p>
    </div>

    <!-- STOCK FAIBLE -->
    <div class="bg-white p-5 rounded-xl shadow hover:shadow-lg transition relative group">
        <h2 class="text-gray-500 text-sm">Stock faible</h2>
        <p class="text-2xl font-bold text-red-500"><?= $produitsFaibles ?></p>

        <div class="absolute top-full left-1/2 -translate-x-1/2 bg-blue-600 text-white text-xs rounded-lg p-2 opacity-0 group-hover:opacity-100 transition mt-2 w-56">
            <ul class="list-disc ml-3 max-h-40 overflow-y-auto">
                <?php foreach ($produitsFaiblesList as $p): ?>
                    <li><?= $p['nom'] ?> (<?= $p['quantite'] ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

</div>

<!-- ACTIONS RAPIDES, LISTES, GRAPHES… (inchangés car valides) -->

</main>

<script>
/* Animation notifications */
const notifContainer = document.getElementById('notifications');
const notifs = Array.from(notifContainer.children);
notifs.forEach((n, i) => n.style.top = `${i * 100}%`);
setInterval(() => {
    notifs.forEach((n, i) => {
        n.style.top = `${(i - 1) * 100}%`;
        n.style.transition = "top 0.5s";
    });
    const first = notifs.shift();
    first.style.transition = "none";
    first.style.top = `${notifs.length * 100}%`;
    notifs.push(first);
}, 4000);
</script>

</body>
</html>
