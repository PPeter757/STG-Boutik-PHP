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

// Récupération du rôle de l'utilisateur connecté
$nom_role = $_SESSION['nom_role'] ?? '';


$total_marge = 0;

// === 0) Paramètres de recherche et pagination ===
$perPage = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$search_id = $_GET['vente_id'] ?? '';
$search_date = $_GET['date_vente'] ?? '';
$search_status = $_GET['status'] ?? '';

function updateVenteStatus($vente_id, $new_status, $pdo)
{
    // 1️⃣ Mettre à jour le statut de la vente
    $stmt = $pdo->prepare("UPDATE ventes SET status=? WHERE vente_id=?");
    $stmt->execute([$new_status, $vente_id]);

    // 2️⃣ Recalculer la récupération et le stock_actuel de tous les produits
    $update_stock = $pdo->prepare("
        UPDATE produits p
        LEFT JOIN (
            SELECT vi.produit_id, SUM(vi.quantite) AS qte_recuperee
            FROM vente_items vi
            JOIN ventes v ON vi.vente_id = v.vente_id
            WHERE v.status = 'Annulée'
            GROUP BY vi.produit_id
        ) AS t ON p.produit_id = t.produit_id
        SET 
            p.recuperation = COALESCE(t.qte_recuperee, 0),
            p.stock_actuel = p.quantite + COALESCE(t.qte_recuperee, 0)
    ");
    $update_stock->execute();
}


// === 1) Modification d'une vente ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'modifier') {
    $id = $_POST['vente_id'];
    $status = $_POST['status'];
    $total = $_POST['total'];

    $stmt = $pdo->prepare("UPDATE ventes SET status=?, total=? WHERE vente_id=?");
    $stmt->execute([$status, $total, $id]);
    header("Location: liste_ventes.php?success=1");
    exit;
}

// === 2) Annulation d'une vente ===
if (isset($_GET['action'], $_GET['vente_id']) && $_GET['action'] === 'annuler') {
    $vente_id = intval($_GET['vente_id']);
    if ($vente_id > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE ventes SET status = 'Annulée' WHERE vente_id = ?");
            $stmt->execute([$vente_id]);
            header("Location: liste_ventes.php?annulee=1");
            exit;
        } catch (PDOException $e) {
            header("Location: liste_ventes.php?annulee=0&err=" . urlencode($e->getMessage()));
            exit;
        }
    }
}

// === 3) Construire la requête filtrée ===
$sql = "SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom, u.username AS user_name
        FROM ventes v
        LEFT JOIN clients c ON v.client_id = c.client_id
        LEFT JOIN users u ON v.user_id = u.user_id
        WHERE 1=1";

$params = [];

if (!empty($search_id)) {
    $sql .= " AND v.vente_id = :vente_id";
    $params[':vente_id'] = $search_id;
}

if (!empty($search_date)) {
    $sql .= " AND DATE(v.date_vente) = :date_vente";
    $params[':date_vente'] = $search_date;
}

if (!empty($search_status)) {
    $sql .= " AND v.status = :status";
    $params[':status'] = $search_status;
}

// === 4) Compter total ventes pour pagination ===
$count_sql = "SELECT COUNT(*) FROM ventes v
              LEFT JOIN clients c ON v.client_id = c.client_id
              WHERE 1=1";

if (!empty($search_id)) $count_sql .= " AND v.vente_id = :vente_id";
if (!empty($search_date)) $count_sql .= " AND DATE(v.date_vente) = :date_vente";
if (!empty($search_status)) $count_sql .= " AND v.status = :status";

$stmt_count = $pdo->prepare($count_sql);
foreach ($params as $key => $val) {
    $stmt_count->bindValue($key, $val);
}
$stmt_count->execute();
$totalVentes = $stmt_count->fetchColumn();
$totalPages = ceil($totalVentes / $perPage);

// === 5) Ajouter pagination à la requête principale ===
// ⚠ LIMIT et OFFSET doivent être injectés directement dans la requête (MariaDB n’accepte pas :lim/:off)
$sql .= " ORDER BY v.date_vente DESC LIMIT $perPage OFFSET $offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Liste des ventes</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>

<body class="bg-gray-100 font-sans">
    <?php include 'includes/menu_lateral.php'; ?>

    <main class="flex-1 ml-64 p-8 space-y-6">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-700">📋 Liste des ventes</h1>
            <a href="ventes.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">➕ Nouvelle vente</a>
        </div>

        <!-- Barre de recherche -->
        <form method="get" class="flex flex-wrap items-center gap-4 bg-white p-4 rounded-lg shadow">
            <div>
                <label class="text-gray-600 text-sm">ID Vente :</label>
                <input type="number" name="vente_id" value="<?= htmlspecialchars($search_id) ?>" class="border rounded px-2 py-1">
            </div>
            <div>
                <label class="text-gray-600 text-sm">Date :</label>
                <input type="date" name="date_vente" value="<?= htmlspecialchars($search_date) ?>" class="border rounded px-2 py-1">
            </div>
            <div>
                <label class="text-gray-600 text-sm">Status :</label>
                <select name="status" class="border rounded px-2 py-1">
                    <option value="">Tous</option>
                    <option value="Payée" <?= $search_status === 'Payée' ? 'selected' : '' ?>>Payée</option>
                    <option value="Annulée" <?= $search_status === 'Annulée' ? 'selected' : '' ?>>Annulée</option>
                    <option value="Crédit" <?= $search_status === 'Crédit' ? 'selected' : '' ?>>Crédit</option>
                </select>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">🔍 Rechercher</button>
            <a href="liste_ventes.php" class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">Réinitialiser</a>
        </form>
        <?php // Calculer le montant total des ventes filtrées
        $totalMontant = 0;
        foreach ($ventes as $v) {
            $totalMontant += $v['total'];
        } ?>
        <!-- Tableau -->
        <div class="bg-white rounded-xl shadow overflow-x-auto">
            <table class="bg-gray-50 text-gray-600 uppercase text-xs w-full border rounded-lg">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="p-3 text-left"># Recu</th>
                        <th class="p-3 text-left">Nom</th>
                        <th class="p-3 text-left">Prenom</th>
                        <th class="p-3 text-left">Groupe</th>
                        <th class="p-3 text-left">Date</th>
                        <th class="p-3 text-right">Total</th>
                        <th class="p-3 text-left">Status</th>
                        <th class="p-3 text-left">Username</th>
                        <th class="p-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventes as $v): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 text-right font-semibold"><?= $v['vente_id'] ?></td>
                            <td class="p-3"><?= htmlspecialchars($v['client_nom'] ?? 'Inconnu') ?></td>
                            <td class="p-3"><?= htmlspecialchars($v['client_prenom'] ?? 'Inconnu') ?></td>
                            <td class="p-3"><?= htmlspecialchars($v['groupe'] ?? 'Inconnu') ?></td>
                            <td class="p-3"><?= htmlspecialchars($v['date_vente']) ?></td>
                            <td class="p-3 text-right text-green-600 font-bold"><?= number_format($v['total'], 2) ?> HTG</td>
                            <td class="p-3">
                                <?php
                                $color = match ($v['status']) {
                                    'Payée' => 'text-green-600 bg-green-100',
                                    'Crédit' => 'text-yellow-600 bg-yellow-100',
                                    'Annulée' => 'text-red-600 bg-red-100',
                                    default => 'text-gray-600 bg-gray-100'
                                };
                                ?>
                                <span class="px-2 py-1 rounded <?= $color ?>"><?= htmlspecialchars($v['status']) ?></span>
                            </td>
                            <td class="p-3">@<?= htmlspecialchars($v['user_name'] ?? '—') ?></td>
                            <td class="p-3 flex justify-center space-x-2">
                                <?php if ($nom_role === 'administrateur' || $nom_role === 'superviseur'): ?>
                                    <!-- Bouton Modifier (Administrateur + Superviseur uniquement) -->
                                    <button onclick="openModal('editModal<?= $v['vente_id'] ?>')"
                                        class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600">
                                        ✏️ Modifier
                                    </button>

                                    <!-- Bouton Annuler -->
                                    <?php if ($v['status'] !== 'Annulée'): ?>
                                        <button onclick="confirmCancel(<?= $v['vente_id'] ?>)"
                                            class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600">
                                            ❌ Annuler
                                        </button>
                                    <?php else: ?>
                                        <button disabled
                                            class="bg-gray-300 text-white px-3 py-1 rounded cursor-not-allowed">
                                            ❌ Annulée
                                        </button>
                                    <?php endif; ?>

                                <?php endif; ?>

                                <!-- Bouton Voir reçu (toujours visible pour tous) -->
                                <a href="recu_vente.php?vente_id=<?= $v['vente_id'] ?>" target="_blank"
                                    class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm font-medium shadow">
                                    🧾 Voir reçu
                                </a>
                            </td>
                        </tr>
                        <!-- Modale -->
                        <div id="editModal<?= $v['vente_id'] ?>" class="hidden fixed inset-0 flex items-center justify-center bg-black bg-opacity-50">
                            <div class="bg-white rounded-lg shadow-lg w-96 p-6">
                                <h2 class="text-lg font-bold mb-4">Modifier la vente #<?= $v['vente_id'] ?></h2>
                                <form method="post" action="liste_ventes.php">
                                    <input type="hidden" name="action" value="modifier">
                                    <input type="hidden" name="vente_id" value="<?= $v['vente_id'] ?>">
                                    <label class="block mb-2 text-sm text-gray-700">Status :</label>
                                    <select name="status" class="border rounded w-full p-2 mb-3">
                                        <option <?= $v['status'] == 'Payée' ? 'selected' : '' ?>>Payée</option>
                                        <option <?= $v['status'] == 'Crédit' ? 'selected' : '' ?>>Crédit</option>
                                    </select>
                                    <label class="block mb-2 text-sm text-gray-700">Total (HTG) :</label>
                                    <input type="number" step="0.01" name="total" value="<?= $v['total'] ?>"
                                        class="border rounded w-full p-2 mb-4" readonly>

                                    <div class="flex justify-end space-x-2">
                                        <button type="button" onclick="closeModal('editModal<?= $v['vente_id'] ?>')" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Fermer</button>
                                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Enregistrer</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="bg-white p-4 rounded-lg shadow flex justify-between items-center">
                <h2 class="text-lg font-semibold text-gray-700">Montant total :</h2>
                <span class="text-green-600 font-bold text-xl"><?= number_format($totalMontant, 2) ?> HTG</span>
            </div>

        </div>
        <!-- Pagination -->
        <div class="flex justify-center mt-4 gap-2">
            <?php
            $maxButtons = 5;
            $start = max(1, $page - floor($maxButtons / 2));
            $end = min($totalPages, $start + $maxButtons - 1);
            if ($end - $start + 1 < $maxButtons) {
                $start = max(1, $end - $maxButtons + 1);
            }

            // Construire la query string des filtres
            $qs = http_build_query([
                'vente_id' => $search_id,
                'date_vente' => $search_date,
                'status' => $search_status
            ]);

            // Bouton Précédent
            if ($page > 1) echo '<a href="?page=' . ($page - 1) . '&' . $qs . '" class="px-3 py-1 rounded bg-white text-blue-600 border hover:opacity-80">« Précédent</a>';

            // 1ère page
            if ($start > 1) {
                echo '<a href="?page=1&' . $qs . '" class="px-3 py-1 rounded bg-white text-blue-600 border hover:opacity-80">1</a>';
                if ($start > 2) echo '<span class="px-3 py-1">...</span>';
            }

            // Boutons centraux
            for ($i = $start; $i <= $end; $i++)
                echo '<a href="?page=' . $i . '&' . $qs . '" class="' . ($i == $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border') . ' px-3 py-1 rounded hover:opacity-80">' . $i . '</a>';

            // Dernière page
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span class="px-3 py-1">...</span>';
                echo '<a href="?page=' . $totalPages . '&' . $qs . '" class="px-3 py-1 rounded bg-white text-blue-600 border hover:opacity-80">' . $totalPages . '</a>';
            }

            // Bouton Suivant
            if ($page < $totalPages) echo '<a href="?page=' . ($page + 1) . '&' . $qs . '" class="px-3 py-1 rounded bg-white text-blue-600 border hover:opacity-80">Suivant »</a>';
            ?>
        </div>

    </main>

    <script>
        function confirmCancel(id) {
            if (confirm('⚠️ Êtes-vous sûr de vouloir annuler cette vente ?')) {
                window.location.href = 'liste_ventes.php?action=annuler&vente_id=' + id;
            }
        }

        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }

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