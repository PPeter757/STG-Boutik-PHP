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
checkRole(['administrateur']); // adapter selon la page

// Vérification rôle admin
if ($_SESSION['nom_role'] !== 'administrateur') {
    die('Accès refusé.');
}

// Messages toast
$message = '';
if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// Pagination & recherche
$search = trim($_GET['search'] ?? '');
$perPage = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$params = [];
$where = '1';

if ($search !== '') {
    $where = "(u.user_nom LIKE :q OR u.user_prenom LIKE :q OR u.email LIKE :q OR r.nom_role LIKE :q)";
    $params[':q'] = "%$search%";
}

// Total utilisateurs
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN roles r ON u.role_id = r.role_id WHERE $where");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// Récupération utilisateurs avec rôle
$sql = "SELECT u.*, r.nom_role 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE $where
        ORDER BY u.user_id DESC 
        LIMIT :lim OFFSET :off";

$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Liste des utilisateurs</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex">

    <?php include 'includes/menu_lateral.php'; ?>

    <main class="flex-1 ml-64 p-8">
        <h1 class="text-3xl font-bold text-blue-700 mb-6">👥 Gestion des utilisateurs</h1>

        <?php if ($message): ?>
            <div id="messageBox" class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= $message ?></div>
        <?php endif; ?>

        <form method="GET" class="mb-6 flex flex-wrap gap-4 items-end bg-white p-4 rounded-lg shadow">
            <input type="text" name="search" placeholder="🔍 Rechercher par nom, prénom, email ou rôle" value="<?= htmlspecialchars($search) ?>" class="w-full md:w-1/3 p-2 border rounded">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Rechercher</button>
            <?php if ($search): ?>
                <a href="users_list.php" class="bg-gray-400 text-white px-4 py-2 rounded">Réinitialiser</a>
            <?php endif; ?>
        </form>

        <div class="overflow-x-auto rounded-lg bg-white shadow-md p-4">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Liste des utilisateurs</h2>
                <a href="user_create.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    ➕ Ajouter un utilisateur
                </a>
            </div>

            <table class="min-w-full text-sm border rounded-lg">
                <thead class="bg-blue-600 text-white border-b uppercase text-xs">
                    <tr>
                        <th class="p-3 text-left">ID</th>
                        <th class="p-3 text-left">Username</th>
                        <th class="p-3 text-left">Nom</th>
                        <th class="p-3 text-left">Prénom</th>
                        <th class="p-3 text-left">Email</th>
                        <th class="p-3 text-left">Rôle</th>
                        <th class="p-3 text-left">Status Compte</th>
                        <th class="p-3 text-left">Créé le</th>
                        <th class="p-3 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-2"><?= $u['user_id'] ?></td>
                            <td class="p-2">@<?= $u['username'] ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['user_nom']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['user_prenom']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['email']) ?></td>
                            <td class="p-2"><?= ucfirst(htmlspecialchars($u['nom_role'] ?? '')) ?></td>
                            <td class="p-2 text-center"><?= ucfirst(htmlspecialchars($u['status_user_account'])) ?></td>
                            <td class="p-2"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                            <td class="p-2 flex justify-center space-x-2">
                                <a href="edit_user.php?id=<?= $u['user_id'] ?>" class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600">Modifier</a>
                                <a href="?delete=<?= $u['user_id'] ?>" onclick="return confirm('Voulez-vous vraiment supprimer cet utilisateur ?')" class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600">Supprimer</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4 flex justify-center space-x-2">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="users_list.php?page=<?= $i ?>" class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    </main>

    <script>
        setTimeout(() => {
            const msg = document.getElementById('messageBox');
            if (!msg) return;
            msg.style.opacity = '0';
            msg.style.transform = 'translateY(-10px)';
            setTimeout(() => msg.remove(), 600);
        }, 4000);
    </script>
</body>
</html>

<?php
// Gestion de la suppression
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
        $stmt->execute([$del_id]);
        header("Location: users_list.php?msg=" . urlencode("✅ Utilisateur supprimé avec succès !"));
        exit;
    } catch (PDOException $e) {
        header("Location: users_list.php?msg=" . urlencode("❌ Impossible de supprimer cet utilisateur, il est utilisé."));
        exit;
    }
}
?>
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

