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

// Protection page connectée
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Empêcher le cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/role_check.php';
checkRole(['administrateur']);

// Récupération infos utilisateur
$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'];
$user_photo = $_SESSION['user_photo'] ?? 'avatar.png';

// Message de salutation
$hour = date('H');
if ($hour < 12) $greeting = 'Bonjour';
elseif ($hour < 18) $greeting = 'Bon après-midi';
else $greeting = 'Bonsoir';

// ===============================
// STATISTIQUES PRINCIPALES
// ===============================
$totalProduits = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
$totalClients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$totalVentes = $pdo->query("SELECT COALESCE(SUM(total), 0) FROM ventes")->fetchColumn();
$produitsFaibles = $pdo->query("SELECT COUNT(*) FROM produits WHERE quantite <= 5")->fetchColumn();

// Derniers produits
$produitsDerniers = $pdo->query("
    SELECT * FROM produits ORDER BY produit_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Derniers clients
$clientsDerniers = $pdo->query("
    SELECT * FROM clients ORDER BY client_id DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Dernières ventes
$ventesRecentes = $pdo->query("
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    ORDER BY v.vente_id DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ===============================
// 1️⃣ VENTES PAR MOIS (GRAPHIQUE)
// ===============================
$query = $pdo->query("
    SELECT EXTRACT(MONTH FROM date_vente) AS mois,
           SUM(total) AS total
    FROM ventes
    GROUP BY EXTRACT(MONTH FROM date_vente)
    ORDER BY mois
");

$labels = [];
$data = [];

while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
    $labels[] = date('F', mktime(0, 0, 0, $row['mois'], 1));
    $data[]   = (float)$row['total'];
}

// ===============================
// 2️⃣ MARGES PAR PRODUIT
// ===============================
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

// ===============================
// 3️⃣ VENTES PAR PRODUIT PAR MOIS
// ===============================
$moisLabels = ["October", "November"];
$ventesParMois = [];
$produitsParMois = [];

foreach ($moisLabels as $mois) {

    // Convertit "October" → 10
    $moisNum = date('m', strtotime("1 $mois"));

    $stmt = $pdo->prepare("
        SELECT p.nom AS produit,
               SUM(v.quantite * v.prix_vente) AS total
        FROM ventes ve
        JOIN vente_items v ON ve.vente_id = v.vente_id
        JOIN produits p ON v.produit_id = p.produit_id
        WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
          AND LOWER(ve.status) NOT IN ('annulée')
        GROUP BY p.produit_id
    ");

    $stmt->execute(['mois' => $moisNum]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ventesParMois[]   = array_sum(array_column($result, 'total'));
    $produitsParMois[] = array_column($result, 'produit');
}

// ===============================
// 4️⃣ TOTAL VENTES MOIS COURANT
// ===============================
$moisActuel   = date('m');
$anneeActuelle = date('Y');

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(v.quantite * v.prix_vente), 0)
    FROM ventes ve
    JOIN vente_items v ON ve.vente_id = v.vente_id
    WHERE EXTRACT(MONTH FROM ve.date_vente) = :mois
      AND EXTRACT(YEAR  FROM ve.date_vente) = :annee
      AND LOWER(ve.status) != 'annulée'
");

$stmt->execute(['mois' => $moisActuel, 'annee' => $anneeActuelle]);
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
// Charge encore une fois les infos utilisateur pour affichage
$user_id = $_SESSION['user_id'] ?? null;
$user_stmt = $pdo->prepare("SELECT user_prenom, user_nom, civilite, photo FROM users WHERE user_id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$user_prenom = $user['user_prenom'] ?? 'Utilisateur';
$user_nom = $user['user_nom'] ?? '';
$photo = !empty($user['photo']) ? 'uploads/' . $user['photo'] : 'uploads/avatar.png';
$civilite = !empty($user['civilite']) ? $user['civilite'] : 'M.';

// Total ventes mois pour affichage
$mois_actuel = date('Y-m');
$stmtVentes = $pdo->prepare("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE TO_CHAR(date_vente, 'YYYY-MM') = ?
");
$stmtVentes->execute([$mois_actuel]);
$moisActuelTotal = $stmtVentes->fetchColumn() ?: 0;

// Produits faibles
$stmtFaibles = $pdo->query("SELECT nom, quantite FROM produits WHERE quantite <= 5");
$produitsFaiblesList = $stmtFaibles->fetchAll(PDO::FETCH_ASSOC);
$produitsFaibles = count($produitsFaiblesList);

// Notifications dynamiques
$notifications = [];

if ($totalVentesMois > 100000) {
    $notifications[] = [
        'type' => 'success',
        'message' => "🎉 Félicitations $civilite $user_prenom ! Vos ventes dépassent 100 000 HTG ce mois-ci."
    ];
}

if ($produitsFaibles > 0) {
    $notifications[] = [
        'type' => 'danger',
        'message' => "⚠️ $produitsFaibles produit(s) sont presque en rupture de stock."
    ];
}
?>

<!-- Message de bienvenue -->
<div class="bg-blue-100 border border-blue-300 rounded-lg shadow p-6 flex items-center gap-6">
    <img src="<?= $photo ?>"
         class="w-20 h-20 rounded-full border-2 border-blue-500 shadow-lg" alt="Profil"
         onerror="this.src='uploads/avatar.png'">

    <div>
        <h2 class="text-2xl font-bold text-blue-700">
            Bonjour <?= htmlspecialchars("$civilite $user_prenom") ?> 👋
        </h2>
        <p class="text-blue-600 text-sm">
            Heureux de vous revoir sur votre tableau de bord.
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

<!-- Le reste de TON HTML reste inchangé -->
<!-- 📌 IMPORTANT : Le code HTML est trop long (tu l'as envoyé en entier) -->
<!-- 👉 Je peux te renvoyer tout le fichier complet si tu veux, mais c'est identique -->

</main>

<script>
/* Script animations notifications + session timeout */
</script>

</body>
</html>
