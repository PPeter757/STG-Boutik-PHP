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

$groupe = $_GET['groupe'] ?? '';
$recherche = $_GET['recherche'] ?? '';
$perPage = 8;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$params = [];
$sqlBase = "FROM clients c
            LEFT JOIN ventes v ON c.client_id = v.client_id AND v.payment_method = 'Vente à crédit'
            WHERE 1=1";

if (!empty($groupe)) { $sqlBase .= " AND c.groupe = :groupe"; $params[':groupe'] = $groupe; }
if (!empty($recherche)) { $sqlBase .= " AND (c.nom LIKE :recherche OR c.prenom LIKE :recherche)"; $params[':recherche'] = "%$recherche%"; }

$countSql = "SELECT COUNT(*) " . $sqlBase;
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$totalClients = (int)$stmt->fetchColumn();
$totalPages = max(1, ceil($totalClients / $perPage));

$sql = "SELECT c.client_id, c.nom, c.prenom,
               COUNT(v.vente_id) AS nb_ventes,
               COALESCE(SUM(v.total),0) AS total_achats,
               MAX(v.date_vente) AS derniere_vente
        " . $sqlBase . "
        GROUP BY c.client_id, c.nom, c.prenom
        ORDER BY total_achats DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v, PDO::PARAM_STR); }
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$aujourdhui = new DateTime();
?>

<table class="w-full text-left border">
    <thead class="bg-blue-600 text-white">
        <tr>
            <th>Nom</th>
            <th>Nombre d’achats</th>
            <th>Total à crédit</th>
            <th>Dernière vente</th>
            <th>Alerte > 30 jours</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($clients as $c):
            $alerte = '';
            $ligne_class = '';
            if ($c['derniere_vente']) {
                $dernier = new DateTime($c['derniere_vente']);
                $diff = $dernier->diff($aujourdhui)->days;
                if ($diff > 30) {
                    $alerte = "⚠️ $diff jours";
                    $ligne_class = 'bg-red-100';
                }
            }
        ?>
        <tr class="<?= $ligne_class ?>">
            <td><?= htmlspecialchars($c['nom'] . ' ' . $c['prenom']) ?></td>
            <td><?= $c['nb_ventes'] ?></td>
            <td>HTG <?= number_format($c['total_achats'], 2) ?></td>
            <td><?= $c['derniere_vente'] ?? '-' ?></td>
            <td class="text-yellow-700 font-bold"><?= $alerte ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<div class="mt-4 flex justify-center space-x-2">
    <?php
    $queryBase = [];
    if ($groupe) $queryBase['groupe'] = $groupe;
    if ($recherche) $queryBase['recherche'] = $recherche;
    $queryStr = http_build_query($queryBase);

    for ($i = 1; $i <= $totalPages; $i++):
        $url = "ajax_cients.php?page=$i" . ($queryStr ? "&$queryStr" : "");
    ?>
        <a href="#" class="px-3 py-1 rounded border text-blue-600 <?= $i === $page ? 'bg-blue-600 text-white' : '' ?>" data-page="<?= $i ?>">
            <?= $i ?>
        </a>
    <?php endfor; ?>
</div>
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
