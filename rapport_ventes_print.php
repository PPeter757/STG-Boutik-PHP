<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Timeout automatique (ex : 10 minutes)
$timeout = 600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: logout.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/role_check.php';
checkRole(['administrateur', 'superviseur']);

$where = "1=1";
$params = [];

// Appliquer les filtres GET
$q = $_GET['q'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? '';

if ($q !== '') {
    $where .= " AND (cl.nom LIKE :q OR cl.prenom LIKE :q)";
    $params[':q'] = "%$q%";
}
if ($date_from !== '') {
    $where .= " AND DATE(date_vente)>=:df";
    $params[':df'] = $date_from;
}
if ($date_to !== '') {
    $where .= " AND DATE(date_vente)<=:dt";
    $params[':dt'] = $date_to;
}
if ($status !== '') {
    $where .= " AND status=:status";
    $params[':status'] = $status;
}

$sql = "SELECT v.*, cl.nom AS client_nom, cl.prenom AS client_prenom
        FROM ventes v
        LEFT JOIN clients cl ON v.client_id=cl.client_id
        WHERE $where
        ORDER BY date_vente DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s)
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Rapport Ventes - Impression</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body {
                background: #fff;
                margin: 0;
            }

            /* Masquer tout sauf le contenu imprimable */
            #menuLateral {
                display: none !important;
            }

            #printable {
                width: 100%;
                margin: 0 auto;
                box-shadow: none;
                position: relative;
            }

            /* Optionnel: styliser le tableau pour l'impression */
            table {
                page-break-inside: auto;
                border-collapse: collapse;
            }

            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }

            th,
            td {
                border: 1px solid #000;
                padding: 4px;
                text-align: left;
            }
        }
    </style>

    </style>
</head>

<body class="bg-gray-100 font-sans">

    <div class="flex">
        <?php if (file_exists(__DIR__ . '/includes/menu_lateral.php')): ?>
            <div id="menuLateral">
                <?php include __DIR__ . '/includes/menu_lateral.php'; ?>
            </div>
        <?php endif; ?>

        <main class="flex-1 p-8 ml-64" id="printable">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-2xl font-bold">💰 Rapport Ventes</h1>
                <button onclick="window.print()" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-700">Imprimer</button>
            </div>

            <div class="bg-white p-6 rounded shadow mb-6">
                <table class="min-w-full text-sm border border-gray-300">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 text-left border-b">No. Recu</th>
                            <th class="p-2 text-left border-b">Vendeur</th>
                            <th class="p-2 text-left border-b">Client</th>
                            <th class="p-2 text-right border-b">Total HTG</th>
                            <th class="p-2 text-left border-b">Date</th>
                            <th class="p-2 text-left border-b">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="5" class="p-4 text-center text-gray-500">Aucune vente</td>
                            </tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr class="hover:bg-gray-50 border-b">
                                    <td class="p-2">#<?= h($r['vente_id']) ?></td>
                                    <td class="p-2">@<?= h($r['username']) ?></td>
                                    <td class="p-2"><?= h(trim($r['client_nom'] . ' ' . $r['client_prenom'])) ?></td>
                                    <td class="p-2 text-right"><?= number_format($r['total'], 2) ?> HTG</td>
                                    <td class="p-2"><?= h($r['date_vente']) ?></td>
                                    <td class="p-2"><?= h($r['status']) ?></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>

            <footer class="text-xs text-gray-500 mt-6">
                Rapport généré le <?= date('d/m/Y H:i') ?>
            </footer>
        </main>
    </div>
</body>
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

</html>