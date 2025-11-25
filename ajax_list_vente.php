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
checkRole(['administrateur', 'superviseur', 'vendeur', 'caissier']);// adapter selon la page

// Filtres
$sqlWhere = "1=1";
$params = [];

// Recherche par nom du client
if (!empty($_GET['search_name'])) {
    $sqlWhere .= " AND (c.nom LIKE :qname OR c.prenom LIKE :qname)";
    $params[':qname'] = "%" . $_GET['search_name'] . "%";
}

// Recherche par date
if (!empty($_GET['search_date'])) {
    $sqlWhere .= " AND DATE(v.date_vente) = :qdate";
    $params[':qdate'] = $_GET['search_date'];
}

// Recherche par statut
if (!empty($_GET['search_status'])) {
    $sqlWhere .= " AND v.status = :qstatus";
    $params[':qstatus'] = $_GET['search_status'];
}

// Pagination
$perPage = 5;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Compter le total
$totalSql = "SELECT COUNT(*) FROM ventes v LEFT JOIN clients c ON v.client_id = c.client_id WHERE $sqlWhere";
$totalStmt = $pdo->prepare($totalSql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Charger les ventes
$sql = "
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom, c.groupe AS client_groupe
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    WHERE $sqlWhere
    ORDER BY v.date_vente DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// HTML du tableau
ob_start();
?>
<table class="min-w-full text-sm border rounded-lg">
    <thead class="bg-blue-600 text-white uppercase text-xs border-b">
        <tr>
            <th class="p-3 text-center"># Reçu</th>
            <th class="p-3 text-left">Nom</th>
            <th class="p-3 text-left">Prénom</th>
            <th class="p-3 text-right">Groupe</th>
            <th class="p-3 text-right">Total</th>
            <th class="p-3 text-right">Date</th>
            <th class="p-3 text-right">Statut</th>
            <th class="p-3 text-center">Action</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ventes as $v): ?>
            <tr class="border-b hover:bg-gray-50">
                <td class="p-3 text-center"><?= $v['vente_id'] ?></td>
                <td class="p-3"><?= htmlspecialchars($v['client_nom'] ?? 'Inconnu') ?></td>
                <td class="p-3"><?= htmlspecialchars($v['client_prenom'] ?? 'Inconnu') ?></td>
                <td class="p-3 text-right"><?= htmlspecialchars($v['client_groupe'] ?? 'Inconnu') ?></td>
                <td class="p-3 text-right"><?= number_format($v['total'], 2) ?> HTG</td>
                <td class="p-3 text-right"><?= $v['date_vente'] ?></td>
                <td class="p-3 text-right"><?= htmlspecialchars($v['status']) ?></td>
                <td class="p-2 text-center space-x-2">
                    <a href="recu_vente.php?vente_id=<?= $v['vente_id'] ?>" target="_blank"
                        class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm font-medium shadow">
                        🧾 Voir reçu
                </td>

                </a>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Pagination AJAX -->
<?php
// Nombre maximum de pages à afficher à la fois
$maxVisible = 5;

// Calcul du groupe de pages actuel
$currentGroup = ceil($page / $maxVisible);

// Première et dernière page du groupe courant
$startPage = ($currentGroup - 1) * $maxVisible + 1;
$endPage = min($startPage + $maxVisible - 1, $totalPages);
?>

<div class="flex justify-center flex-wrap gap-2 mt-2 mb-2">

    <div class="flex justify-center mt-4 gap-2">
        <!-- Bouton "Précédent" -->
        <?php if ($page > 1): ?>
            <button
                class="ajax-page bg-white text-blue-600 border border-blue-400 hover:bg-blue-50 px-4 py-2 rounded-lg font-medium shadow-sm transition duration-200"
                data-page="<?= $page - 1 ?>">
                ◀ Précédent
            </button>
        <?php endif; ?>

        <?php
        $maxButtons = 5; // max de boutons centraux
        $start = max(1, $page - floor($maxButtons / 2));
        $end = min($totalPages, $start + $maxButtons - 1);
        if ($end - $start + 1 < $maxButtons) {
            $start = max(1, $end - $maxButtons + 1);
        }

        // Première page
        if ($start > 1) {
            echo '<button class="ajax-page bg-white text-blue-600 border border-blue-400 hover:bg-blue-50 px-4 py-2 rounded-lg" data-page="1">1</button>';
            if ($start > 2) echo '<span class="px-2 py-1">...</span>';
        }

        // Boutons centraux
        for ($i = $start; $i <= $end; $i++) {
            echo '<button class="ajax-page px-4 py-2 rounded-lg font-medium shadow-sm transition duration-200 ' .
                ($i == $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border border-blue-400 hover:bg-blue-50') .
                '" data-page="' . $i . '">' . $i . '</button>';
        }

        // Dernière page
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) echo '<span class="px-2 py-1">...</span>';
            echo '<button class="ajax-page bg-white text-blue-600 border border-blue-400 hover:bg-blue-50 px-4 py-2 rounded-lg" data-page="' . $totalPages . '">' . $totalPages . '</button>';
        }

        // Bouton "Suivant"
        if ($page < $totalPages): ?>
            <button
                class="ajax-page bg-white text-blue-600 border border-blue-400 hover:bg-blue-50 px-4 py-2 rounded-lg font-medium shadow-sm transition duration-200"
                data-page="<?= $page + 1 ?>">
                Suivant ▶
            </button>
        <?php endif; ?>
    </div>
</div>
<?php
echo ob_get_clean();
?>
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
