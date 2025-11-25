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
checkRole($pdo, ['administrateur', 'manager']); // adapter selon la page

// 🔹 Recherche et pagination
$search_name = $_GET['search_name'] ?? '';
$search_date = $_GET['search_date'] ?? '';
$search_status = $_GET['search_status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// 🔹 Construction dynamique de la requête
$where = [];
$params = [];

if ($search_name) {
    $where[] = "(c.nom LIKE ? OR c.prenom LIKE ?)";
    $params[] = "%$search_name%";
    $params[] = "%$search_name%";
}
if ($search_date) {
    $where[] = "DATE(v.date_vente) = ?";
    $params[] = $search_date;
}
if ($search_status) {
    $where[] = "v.status = ?";
    $params[] = $search_status;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 🔹 Charger la liste des ventes avec le nom du vendeur
$stmt = $pdo->prepare("
    SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom, u.username
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    LEFT JOIN users u ON v.username = u.username
    $whereSQL
    ORDER BY v.vente_id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 🔹 Compter le total pour la pagination
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    $whereSQL
");
$countStmt->execute($params);
$totalRows = $countStmt->fetchColumn();
$totalPages = ceil($totalRows / $limit);

// 🔹 Fonction pour afficher le username au format @X.Yz
function formatUsername($username)
{
    if (!$username) return 'Utilisateur inconnu';
    $length = strlen($username);
    if ($length === 1) return '@' . strtoupper($username);
    if ($length === 2) return '@' . strtoupper($username[0]) . '.' . strtoupper($username[1]);
    return '@' . strtoupper($username[0]) . '.' . strtoupper($username[1]) . strtolower(substr($username, 2));
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Gestion des ventes</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen flex font-sans text-gray-800">

    <?php include __DIR__ . '/includes/menu_lateral.php'; ?>

    <main class="ml-64 flex-1 p-8 space-y-6">

        <h1 class="text-2xl font-bold mb-4">Liste des ventes</h1>

        <!-- 🔍 Formulaire recherche -->
        <form method="GET" class="flex flex-wrap gap-4 mb-4">
            <input type="text" name="search_name" placeholder="Nom ou prénom"
                value="<?= htmlspecialchars($search_name) ?>" class="border p-2 rounded">
            <input type="date" name="search_date" value="<?= htmlspecialchars($search_date) ?>" class="border p-2 rounded">
            <select name="search_status" class="border p-2 rounded">
                <option value="">Tous</option>
                <option value="Payée" <?= $search_status === 'Payée' ? 'selected' : '' ?>>Payée</option>
                <option value="Credit" <?= $search_status === 'Credit' ? 'selected' : '' ?>>Crédit</option>
                <option value="Annulée" <?= $search_status === 'Annulée' ? 'selected' : '' ?>>Annulée</option>
            </select>
            <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                Rechercher
            </button>
        </form>

        <!-- 📋 Tableau des ventes -->
        <section class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">
            <div class="overflow-x-auto rounded-lg">
                <table class="min-w-full text-sm border rounded-lg">
                    <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                        <tr>
                            <th class="p-3 text-left">ID</th>
                            <th class="p-3 text-left">Nom</th>
                            <th class="p-3 text-left">Prénom</th>
                            <th class="p-3 text-left">Total</th>
                            <th class="p-3 text-left">Date</th>
                            <th class="p-3 text-left">Statut</th>
                            <th class="p-3 text-left">Vendu par</th>
                            <th class="p-3 text-left">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ventes)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-gray-500 p-4">Aucune vente trouvée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ventes as $v): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="p-2"><?= $v['vente_id'] ?></td>
                                    <td class="p-2"><?= htmlspecialchars($v['client_nom']) ?></td>
                                    <td class="p-2"><?= htmlspecialchars($v['client_prenom']) ?></td>
                                    <td class="p-2"><?= number_format($v['total'], 2) ?> HTG</td>
                                    <td class="p-2"><?= htmlspecialchars($v['date_vente']) ?></td>
                                    <td class="p-2"><?= htmlspecialchars($v['status']) ?></td>
                                    <td class="p-2"><?= htmlspecialchars(formatUsername($v['username'])) ?></td>
                                    <td class="p-2 space-x-3">
                                        <a href="modifier_vente.php?vente_id=<?= $v['vente_id'] ?>"
                                            class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600">✏️ Modifier</a>
                                        <a href="liste_ventes.php?action=annuler&vente_id=<?= $v['vente_id'] ?>"
                                            onclick="return confirm('⚠️ Êtes-vous sûr de vouloir annuler cette vente ?');"
                                            class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600">❌ Annuler</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 🔢 Pagination -->
            <div class="flex justify-center mt-4 gap-2">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>&search_name=<?= urlencode($search_name) ?>&search_date=<?= urlencode($search_date) ?>&search_status=<?= urlencode($search_status) ?>"
                        class="<?= $i == $page ? 'bg-blue-800 text-white' : 'bg-white-500 text-white' ?> px-3 py-1 rounded hover:opacity-80">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        </section>
    </main>
    <script>
        // Durée d'inactivité côté client (ms)
        const timeout = <?php echo $timeout * 1000; ?>; // ex: 600000 ms pour 10 min
        const warningTime = 60 * 1000; // 1 min avant expiration
        let timer, warningTimer;

        function startTimers() {
            clearTimeout(timer);
            clearTimeout(warningTimer);

            // Timer pour afficher l'alerte
            warningTimer = setTimeout(() => {
                showWarning();
            }, timeout - warningTime);

            // Timer pour rediriger après timeout
            timer = setTimeout(() => {
                window.location.href = 'logout.php?timeout=1';
            }, timeout);
        }

        function resetTimers() {
            startTimers();
        }

        function showWarning() {
            // Créer un élément de notification
            let warningBox = document.getElementById('session-warning');
            if (!warningBox) {
                warningBox = document.createElement('div');
                warningBox.id = 'session-warning';
                warningBox.innerHTML = `
                <div class="fixed top-4 right-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded shadow-lg z-50">
                    ⚠️ Votre session expire dans 1 minute.
                    <button id="extend-session" class="ml-2 px-2 py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600">Prolonger</button>
                </div>
            `;
                document.body.appendChild(warningBox);

                document.getElementById('extend-session').onclick = () => {
                    fetch('keep_alive.php') // petit script PHP pour prolonger session
                        .then(() => {
                            warningBox.remove();
                            resetTimers();
                        })
                        .catch(() => {
                            alert('Erreur, impossible de prolonger la session.');
                        });
                };
            }
        }

        // Détecter activité utilisateur
        ['mousemove', 'keypress', 'click', 'scroll'].forEach(evt => {
            window.addEventListener(evt, resetTimers);
        });

        window.addEventListener('load', startTimers);
    </script>

</body>

</html>