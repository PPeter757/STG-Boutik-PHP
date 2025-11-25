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

$users = $pdo->query("SELECT u.user_id, u.username, u.role, COUNT(v.vente_id) AS nb_ventes, SUM(v.total) AS total_ventes
                      FROM users u
                      LEFT JOIN ventes v ON u.user_id = v.user_id
                      GROUP BY u.user_id
                      ORDER BY nb_ventes DESC")->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as &$u) {
    $stmt = $pdo->prepare("SELECT last_login FROM users WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $u['user_id']]);
    $u['last_login'] = $stmt->fetchColumn();
}
$sql = "SELECT 
    u.user_id,
    u.username,
    u.role,
    u.last_activity,
    u.last_login,

    -- Total des ventes (non annulées)
    COALESCE(SUM(CASE WHEN v.status != 'annule' THEN v.total END), 0) AS total_ventes,

    -- Ventes de la journée (non annulées)
    COALESCE(COUNT(CASE WHEN v.status != 'annule' AND DATE(v.date_vente) = CURDATE() THEN 1 END), 0) AS ventes_today,

    -- Total semaine (non annulées)
    COALESCE(SUM(CASE WHEN v.status != 'annule' AND DATE(v.date_vente) >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY) THEN v.total END), 0) AS ventes_week,

    -- Ventes annulées
    COALESCE(COUNT(CASE WHEN v.status = 'annule' THEN 1 END), 0) AS ventes_annulees

FROM users u
LEFT JOIN ventes v ON v.user_id = u.user_id
GROUP BY u.user_id
ORDER BY u.username
";
$users = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Rapport des utilisateurs</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php
    if (isset($_SESSION['user_id'])) {
        $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE user_id = :id")
            ->execute(['id' => $_SESSION['user_id']]);
    ?>
        <meta http-equiv="refresh" content="300"> <!-- Rafraîchit la page toutes les 5 minutes -->
    <?php

    }
    ?>
</head>

<body class="bg-gray-100 font-sans">
    <?php include 'includes/menu_lateral.php'; ?>

    <main class="ml-64 p-8 space-y-6">
        <h1 class="text-2xl font-bold text-gray-700">👤 Rapport des utilisateurs</h1>

        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold mb-4">Activité des utilisateurs</h2>
            <table class="w-full text-left border">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="p-2">Utilisateur</th>
                        <th class="p-2">Rôle</th>
                        <th class="p-2">Status</th>
                        <th class="p-2">Dernière connexion</th>
                        <th class="p-2">Nombre de ventes</th>
                        <th class="p-2">Total ventes du jour</th>
                        <th class="p-2 text-right">Ventes du jour</th>
                        <th class="p-2">Total de la semaine</th>
                    </tr>
                </thead>
                <!-- <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-2"><?= htmlspecialchars($u['username']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['role']) ?></td>

                            <?php
                            $last_login = $u['last_login'] ?? null;
                            $is_online = $last_login && (time() - strtotime($last_login) < 300); // 5 minutes = 300s
                            ?>

                            <td class="p-2">
                                <?php if ($is_online): ?>
                                    <span class="text-green-600 font-bold">Online</span>
                                <?php else: ?>
                                    <span class="text-red-600 font-bold">Disconnected</span>
                                <?php endif; ?>
                            </td>

                            <td class="p-2">
                                <?= $last_login ? date('d/m/Y H:i', strtotime($last_login)) : 'Never connected' ?>
                            </td>

                            <td class="p-2 text-center"><?= $u['nb_ventes'] ?></td>

                            <td class="p-2 text-center">
                                <?php
                                $today = date('Y-m-d');
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventes WHERE user_id = :user_id AND DATE(date_vente) = :today");
                                $stmt->execute(['user_id' => $u['user_id'], 'today' => $today]);
                                echo $stmt->fetchColumn();
                                ?>
                            </td>

                            <td class="p-2 text-right">HTG <?= number_format($u['total_ventes'] ?? 0, 2) ?></td>

                            <td class="p-2 text-right">
                                <?php
                                $week_start = date('Y-m-d', strtotime('monday this week'));
                                $stmt = $pdo->prepare("SELECT SUM(total) FROM ventes WHERE user_id = :user_id AND DATE(date_vente) >= :week_start");
                                $stmt->execute(['user_id' => $u['user_id'], 'week_start' => $week_start]);
                                echo 'HTG ' . number_format($stmt->fetchColumn() ?? 0, 2);
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody> -->
                <tbody>
                    <?php foreach ($users as $u): ?>

                        <?php
                        $last_activity = $u['last_activity'];
                        $is_online = $last_activity && (time() - strtotime($last_activity) < 300); // < 5 min
                        ?>

                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-2"><?= htmlspecialchars($u['username']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($u['role']) ?></td>
                            <td class="p-2">
                                <!-- Status session -->
                                <php>

                                </php>
                                <?php if ($is_online): ?>
                                    <span class="text-green-600 font-bold">Online</span>
                                <?php else: ?>
                                    <span class="text-red-600 font-bold">Disconnected</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2">
                                <?= $last_activity ? date('d/m/Y H:i', strtotime($last_activity)) : 'Never connected' ?>
                            </td>
                            <td class="p-2 text-center"><?= $u['ventes_today'] ?></td>
                            <td class="p-2 text-center"><?= $u['ventes_annulees'] ?></td>
                            <td class="p-2 text-right">HTG <?= number_format($u['total_ventes'], 2) ?></td>
                            <td class="p-2 text-right">HTG <?= number_format($u['ventes_week'], 2) ?></td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>

            </table>
        </div>
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