<?php
// dashboard_non_administrateur.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Empêcher le cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Timeout d'inactivité (10 min)
$timeout = 600;
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header("Location: logout.php?timeout=1");
        exit;
    }
}
$_SESSION['last_activity'] = time();

// Includes
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/role_check.php';

// Vérification session
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Redirection administrateur
if (isset($_SESSION['nom_role']) && strtolower($_SESSION['nom_role']) === 'administrateur') {
    header('Location: dashboard.php');
    exit;
}

// Récupérer infos utilisateur
$user_id = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT u.user_id, u.username, u.user_nom, u.user_prenom, u.role_id, u.status_user_account, r.nom_role
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE u.user_id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$role_id = (int)($user['role_id'] ?? 0);
$role_name = strtolower($user['nom_role'] ?? '');
$today = date('Y-m-d');

// Fonctions utilitaires
function sumByStatusLike($pdo, $pattern)
{
    $sql = "SELECT COALESCE(SUM(total), 0) FROM ventes WHERE LOWER(status) LIKE ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([mb_strtolower($pattern, 'UTF-8')]);
    return (float)$stmt->fetchColumn();
}
function sumNotAnnule($pdo)
{
    $sql = "SELECT COALESCE(SUM(total), 0) FROM ventes WHERE LOWER(status) NOT LIKE 'ann%'";
    return (float)$pdo->query($sql)->fetchColumn();
}

// Dashboard items selon rôle
$dashboard_items = [];
switch ($role_id) {
    case 2: // Vendeur
        // Nombre total de ventes aujourd'hui (toutes ventes, tous utilisateurs)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventes WHERE DATE(date_vente) = ?");
        $stmt->execute([$today]);
        $nbVentes = (int)$stmt->fetchColumn();

        // Montant total des ventes payées aujourd'hui
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM ventes WHERE DATE(date_vente) = ? AND LOWER(status) LIKE ?");
        $stmt->execute([$today, 'pay%']);
        $montantPayees = (float)$stmt->fetchColumn();

        // Produits à faible stock (<10)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM produits WHERE quantite < ?");
        $stmt->execute([10]);
        $nbProduitsFaibles = (int)$stmt->fetchColumn();

        // Dashboard items
        $dashboard_items[] = ['title' => 'Ventes du jour', 'value' => $nbVentes, 'icon' => '🛒'];
        $dashboard_items[] = ['title' => 'Montant total payé', 'value' => number_format($montantPayees, 2), 'icon' => '💵'];
        $dashboard_items[] = ['title' => 'Produits en stock faible', 'value' => $nbProduitsFaibles, 'icon' => '⚠️'];
        break;
    case 3: // Caissier
        // Total de la caisse pour toutes les ventes payées aujourd'hui
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM ventes WHERE DATE(date_vente) = ? AND LOWER(status) LIKE ?");
        $stmt->execute([$today, 'pay%']);
        $totalCaisse = (float)$stmt->fetchColumn();

        // Nombre total de ventes aujourd'hui (toutes, quel que soit le status)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventes WHERE DATE(date_vente) = ?");
        $stmt->execute([$today]);
        $nbTrans = (int)$stmt->fetchColumn();

        // Dashboard items
        $dashboard_items[] = ['title' => 'Caisse du jour', 'value' => number_format($totalCaisse, 2), 'icon' => '💵'];
        $dashboard_items[] = ['title' => 'Nombre de ventes du jour', 'value' => $nbTrans, 'icon' => '📄'];
        break;
    case 4: // Superviseur
        $totalVentesAll = sumNotAnnule($pdo);
        $totalVentesPayees = sumByStatusLike($pdo, 'pay%');
        $totalVentesCredit = sumByStatusLike($pdo, 'cr%');

        $sql = "SELECT c.client_id, c.nom, c.prenom, COALESCE(SUM(v.total),0) AS total_credit
                FROM ventes v
                JOIN clients c ON v.client_id = c.client_id
                WHERE LOWER(v.status) LIKE 'cr%'
                GROUP BY c.client_id, c.nom, c.prenom
                ORDER BY total_credit DESC
                LIMIT 10";
        $clientsCredit = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $sql = "SELECT v.vente_id, v.client_id, v.total, v.date_vente, c.nom, c.prenom
                FROM ventes v
                JOIN clients c ON v.client_id = c.client_id
                WHERE LOWER(v.status) LIKE 'cr%'
                ORDER BY v.date_vente DESC
                LIMIT 20";
        $ventesNonPayees = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT produit_id, nom, quantite FROM produits WHERE quantite < ? ORDER BY quantite ASC");
        $stmt->execute([10]);
        $produitsFaiblesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $dashboard_items[] = ['title' => 'Ventes totales valides', 'value' => number_format($totalVentesPayees + $totalVentesCredit, 2), 'icon' => '📊'];
        $dashboard_items[] = ['title' => 'Ventes payées', 'value' => number_format($totalVentesPayees, 2), 'icon' => '💵'];
        $dashboard_items[] = ['title' => 'Ventes à crédit', 'value' => number_format($totalVentesCredit, 2), 'icon' => '💳'];
        break;

    default:
        $dashboard_items[] = ['title' => 'Bienvenue', 'value' => '', 'icon' => '👋'];
        break;
}

// ---------- Ventes du jour (toutes) ----------
$perPage = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

$ventesStmt = $pdo->prepare("
    SELECT v.vente_id, v.total, v.status, v.date_vente, 
           u.username,
           c.nom AS client_nom, c.prenom AS client_prenom, c.groupe
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    LEFT JOIN users u ON v.user_id = u.user_id
    WHERE v.date_vente BETWEEN :todayStart AND :todayEnd
    ORDER BY v.date_vente DESC
    LIMIT :perPage OFFSET :offset
");
$ventesStmt->bindValue(':todayStart', $todayStart);
$ventesStmt->bindValue(':todayEnd', $todayEnd);
$ventesStmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
$ventesStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$ventesStmt->execute();
$ventes = $ventesStmt->fetchAll(PDO::FETCH_ASSOC);

$totalVentesStmt = $pdo->prepare("
    SELECT COALESCE(SUM(total),0)
    FROM ventes
    WHERE date_vente BETWEEN ? AND ? AND LOWER(status) NOT LIKE 'ann%'
");
$totalVentesStmt->execute([$todayStart, $todayEnd]);
$totalVentes = (float)$totalVentesStmt->fetchColumn();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ventes WHERE date_vente BETWEEN ? AND ?");
$countStmt->execute([$todayStart, $todayEnd]);
$totalVentesCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalVentesCount / $perPage));

// ---------- Clients créés aujourd'hui (tous utilisateurs) ----------
$clientsStmt = $pdo->prepare("SELECT client_id, nom, prenom, groupe, created_at FROM clients WHERE DATE(created_at) = ? ORDER BY created_at DESC");
$clientsStmt->execute([$today]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// ---------- Total ventes payées du jour ----------
$ventesPayeesStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM ventes WHERE DATE(date_vente) BETWEEN ? AND ? AND LOWER(status) LIKE ?");
$ventesPayeesStmt->execute([$todayStart, $todayEnd, 'pay%']);
$totalVentesPayees = (float)$ventesPayeesStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Tableau de bord utilisateur</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 flex">

    <?php include __DIR__ . '/includes/menu_lateral.php'; ?>

    <main class="flex-1 ml-64 p-8">
        <h1 class="text-3xl font-bold text-blue-700 mb-6">
            Bonjour <?= htmlspecialchars($user['user_prenom'] ?? $user['username']) ?> 👋
        </h1>

        <!-- Dashboard cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
            <?php foreach ($dashboard_items as $item): ?>
                <div class="bg-white shadow-md rounded-lg p-6 flex items-center space-x-4">
                    <div class="text-4xl"><?= $item['icon'] ?></div>
                    <div>
                        <div class="text-gray-500 font-semibold"><?= $item['title'] ?></div>
                        <div class="text-2xl font-bold"><?= $item['value'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Ventes de la journée -->
        <?php
        $date = new DateTime();
        $jours = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
        $mois = ['Jan.', 'Fév.', 'Mar.', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sep.', 'Oct.', 'Nov.', 'Déc.'];
        $formattedDate = $jours[(int)$date->format('w')] . ' ' . $date->format('d') . ' ' . $mois[(int)$date->format('n') - 1] . ' ' . $date->format('Y');
        ?>
        <div class="bg-white shadow-md rounded-lg p-4 mb-6">
            <h2 class="text-xl font-semibold mb-4">🛒 Ventes du <?= $formattedDate ?></h2>

            <table class="min-w-full text-left border">
                <thead class="bg-blue-600 text-white uppercase text-xs">
                    <tr>
                        <th class="p-2">ID Vente</th>
                        <th class="p-2">Vendu Par</th>
                        <th class="p-2">Nom</th>
                        <th class="p-2">Prénom</th>
                        <th class="p-2">Groupe</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Heure</th>
                        <th class="p-2 text-right">Montant</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ventes)): ?>
                        <tr>
                            <td class="p-4 text-center" colspan="8">Aucune vente aujourd'hui.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($ventes as $v): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-2">#<?= $v['vente_id'] ?></td>
                                <td class="p-2">@<?= htmlspecialchars($v['username'] ?? 'Inconnu') ?></td>
                                <td class="p-2"><?= htmlspecialchars($v['client_nom'] ?? 'Inconnu') ?></td>
                                <td class="p-2"><?= htmlspecialchars($v['client_prenom'] ?? 'Inconnu') ?></td>
                                <td class="p-2"><?= htmlspecialchars($v['groupe'] ?? 'Inconnu') ?></td>
                                <td class="p-2">
                                    <?php
                                    $s = $v['status'] ?? '';
                                    $lc = mb_strtolower($s, 'UTF-8');
                                    if (strpos($lc, 'pay') === 0) $cls = 'text-green-600 bg-green-100';
                                    elseif (strpos($lc, 'cr') === 0) $cls = 'text-yellow-600 bg-yellow-100';
                                    elseif (strpos($lc, 'ann') === 0) $cls = 'text-red-600 bg-red-100';
                                    else $cls = 'text-gray-600 bg-gray-100';
                                    ?>
                                    <span class="px-2 py-1 rounded <?= $cls ?>"><?= htmlspecialchars($s) ?></span>
                                </td>
                                <td class="p-2"><?= date('H:i', strtotime($v['date_vente'])) ?></td>
                                <td class="p-2 text-right"><?= number_format($v['total'], 2) ?> HTG</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="bg-white p-4 rounded-lg shadow flex justify-between items-center mt-4">
                <h2 class="text-lg font-semibold text-gray-700">Montant total (valide) :</h2>
                <span class="text-green-600 font-bold text-xl"><?= number_format($totalVentes, 2) ?> HTG</span>
            </div>

            <!-- Pagination -->
            <div class="mt-4 flex justify-center space-x-2">
                <?php
                $maxButtons = 5;
                $start = max(1, $page - floor($maxButtons / 2));
                $end = min($totalPages, $start + $maxButtons - 1);
                if ($end - $start + 1 < $maxButtons) {
                    $start = max(1, $end - $maxButtons + 1);
                }
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page=<?= $i ?>" class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Clients créés aujourd'hui -->
        <?php if (!empty($clients)): ?>
            <div class="bg-white shadow-md rounded-lg p-4 mb-6">
                <h2 class="text-xl font-semibold mb-4">👥 Clients enregistrés aujourd'hui</h2>
                <table class="min-w-full text-left border">
                    <thead class="bg-blue-600 text-white uppercase text-xs">
                        <tr>
                            <th class="p-2">ID Client</th>
                            <th class="p-2">Nom</th>
                            <th class="p-2">Prénom</th>
                            <th class="p-2">Groupe</th>
                            <th class="p-2">Heure</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $c): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-2"><?= htmlspecialchars($c['client_id']) ?></td>
                                <td class="p-2"><?= htmlspecialchars($c['nom']) ?></td>
                                <td class="p-2"><?= htmlspecialchars($c['prenom']) ?></td>
                                <td class="p-2"><?= htmlspecialchars($c['groupe'] ?? 'Inconnu') ?></td>
                                <td class="p-2"><?= date('H:i', strtotime($c['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php
        if ($role_id === 4):

            // ----------------- Pagination Clients crédit -----------------
            $clientsPerPage = 4;
            $clientsPage = max(1, intval($_GET['clients_page'] ?? 1));
            $clientsOffset = ($clientsPage - 1) * $clientsPerPage;
            $totalClients = count($clientsCredit);
            $totalClientsPages = max(1, ceil($totalClients / $clientsPerPage));
            $clientsPageList = array_slice($clientsCredit, $clientsOffset, $clientsPerPage);

            // ----------------- Pagination Ventes non payées -----------------
            $ventesPerPage = 4;
            $ventesPage = max(1, intval($_GET['ventes_page'] ?? 1));
            $ventesOffset = ($ventesPage - 1) * $ventesPerPage;
            $totalVentesNonPayees = count($ventesNonPayees);
            $totalVentesPages = max(1, ceil($totalVentesNonPayees / $ventesPerPage));
            $ventesPageList = array_slice($ventesNonPayees, $ventesOffset, $ventesPerPage);

            // ----------------- Pagination Produits faibles -----------------
            $prodPerPage = 5;
            $prodPage = max(1, intval($_GET['prod_page'] ?? 1));
            $prodOffset = ($prodPage - 1) * $prodPerPage;
            $totalProduits = count($produitsFaiblesList);
            $totalProdPages = max(1, ceil($totalProduits / $prodPerPage));
            $produitsPageList = array_slice($produitsFaiblesList, $prodOffset, $prodPerPage);
        ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Clients crédit -->
                <div class="bg-white p-4 rounded shadow">
                    <h3 class="text-lg font-semibold mb-2">Clients ayant payé leur crédit</h3>
                    <?php if (!empty($clientsPageList)): ?>
                        <ul class="divide-y max-h-48 overflow-y-auto">
                            <?php foreach ($clientsPageList as $cc): ?>
                                <li class="py-2 flex justify-between">
                                    <div><?= htmlspecialchars($cc['nom'] . ' ' . $cc['prenom']) ?></div>
                                    <div class="text-green-600 font-semibold"><?= number_format($cc['total_credit'], 2) ?> HTG</div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <!-- Pagination -->
                        <div class="mt-2 flex justify-center space-x-2">
                            <?php
                            $maxButtons = 5;
                            $start = max(1, $clientsPage - floor($maxButtons / 2));
                            $end = min($totalClientsPages, $start + $maxButtons - 1);
                            if ($end - $start + 1 < $maxButtons) $start = max(1, $end - $maxButtons + 1);
                            for ($i = $start; $i <= $end; $i++): ?>
                                <a href="?clients_page=<?= $i ?>" class="px-3 py-1 rounded <?= $i === $clientsPage ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php else: ?>
                        <div>Aucun client à crédit trouvé.</div>
                    <?php endif; ?>
                </div>

                <!-- Ventes à crédit non payées -->
                <div class="bg-white p-4 rounded shadow">
                    <h3 class="text-lg font-semibold mb-2">Ventes à crédit non payées</h3>
                    <?php if (!empty($ventesPageList)): ?>
                        <ul class="divide-y max-h-48 overflow-y-auto">
                            <?php foreach ($ventesPageList as $vnp): ?>
                                <li class="py-2 flex justify-between">
                                    <div><?= htmlspecialchars($vnp['nom'] . ' ' . $vnp['prenom']) ?> (Reçu #<?= $vnp['vente_id'] ?>)</div>
                                    <div class="text-yellow-600 font-semibold"><?= number_format($vnp['total'], 2) ?> HTG</div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <!-- Pagination -->
                        <div class="mt-2 flex justify-center space-x-2">
                            <?php
                            $start = max(1, $ventesPage - floor($maxButtons / 2));
                            $end = min($totalVentesPages, $start + $maxButtons - 1);
                            if ($end - $start + 1 < $maxButtons) $start = max(1, $end - $maxButtons + 1);
                            for ($i = $start; $i <= $end; $i++): ?>
                                <a href="?ventes_page=<?= $i ?>" class="px-3 py-1 rounded <?= $i === $ventesPage ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php else: ?>
                        <div>Aucune vente à crédit non payée.</div>
                    <?php endif; ?>
                </div>

                <!-- Produits faibles -->
                <div class="bg-white p-4 rounded shadow col-span-1 md:col-span-2">
                    <h3 class="text-lg font-semibold mb-2">Produits avec stock faible</h3>
                    <?php if (!empty($produitsPageList)): ?>
                        <ul class="divide-y max-h-48 overflow-y-auto">
                            <?php foreach ($produitsPageList as $p): ?>
                                <li class="py-2 flex justify-between">
                                    <div><?= htmlspecialchars($p['nom']) ?></div>
                                    <div class="text-red-600 font-semibold"><?= (int)$p['quantite'] ?></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <!-- Pagination -->
                        <div class="mt-2 flex justify-center space-x-2">
                            <?php
                            $start = max(1, $prodPage - floor($maxButtons / 2));
                            $end = min($totalProdPages, $start + $maxButtons - 1);
                            if ($end - $start + 1 < $maxButtons) $start = max(1, $end - $maxButtons + 1);
                            for ($i = $start; $i <= $end; $i++): ?>
                                <a href="?prod_page=<?= $i ?>" class="px-3 py-1 rounded <?= $i === $prodPage ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border' ?>"><?= $i ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php else: ?>
                        <div>Aucun produit avec stock faible.</div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>
    </main>
</body>
<script>
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
</html>