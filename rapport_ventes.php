<?php
// rapport_ventes.php
// Rapport & gestion des ventes (liste, stats, export, details, changement status)

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

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/role_check.php';

checkRole(['administrateur', 'superviseur']);

function h($s)
{
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
function jsonOk($data = [])
{
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'data' => $data]);
    exit;
}
function jsonErr($msg)
{
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// ---------------- Actions AJAX / Export ----------------
$action = $_GET['action'] ?? $_POST['action'] ?? null;

// Détails vente
if ($action === 'details' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];

    // Vente + client
    $stmt = $pdo->prepare("
        SELECT v.*, cl.nom AS client_nom, cl.prenom AS client_prenom
        FROM ventes v
        LEFT JOIN clients cl ON v.client_id = cl.client_id
        WHERE v.vente_id = ?
    ");
    $stmt->execute([$id]);
    $vente = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$vente) jsonErr("Vente introuvable.");

    // Items de la vente
    $stmtItems = $pdo->prepare("
        SELECT vi.*, p.nom AS produit_nom
        FROM vente_items vi
        LEFT JOIN produits p ON vi.produit_id = p.produit_id
        WHERE vi.vente_id = ?
    ");
    $stmtItems->execute([$id]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'data' => [
            'vente' => $vente,
            'items' => $items
        ]
    ]);
    exit;
}


// Mettre à jour status
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? null;
    $allowed = ['Crédit', 'Payée', 'Annulée'];
    if (!$id || !in_array($status, $allowed)) jsonErr("Données invalides.");
    $stmt = $pdo->prepare("UPDATE ventes SET status=? WHERE vente_id=?");
    $stmt->execute([$status, $id]);
    jsonOk(['id' => $id, 'status' => $status]);
}

// Export CSV / PDF
if ($action === 'export' && isset($_GET['format'])) {
    $format = $_GET['format'];
    $where = "1=1";
    $params = [];
    if (!empty($_GET['date_from'])) {
        $where .= " AND DATE(date_vente)>=:df";
        $params[':df'] = $_GET['date_from'];
    }
    if (!empty($_GET['date_to'])) {
        $where .= " AND DATE(date_vente)<=:dt";
        $params[':dt'] = $_GET['date_to'];
    }
    if (!empty($_GET['status'])) {
        $where .= " AND status=:status";
        $params[':status'] = $_GET['status'];
    }

    $sql = "SELECT v.*, cl.nom AS client_nom, cl.prenom AS client_prenom
          FROM ventes v
          LEFT JOIN clients cl ON v.client_id = cl.client_id
          WHERE $where
          ORDER BY date_vente DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'csv') {
        $filename = 'rapport_ventes_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fputcsv($out, ['ID', 'Client', 'Total HTG', 'status', 'Date']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['vente_id'], trim($r['client_nom'] . ' ' . $r['client_prenom']), number_format($r['total'], 2), $r['status'], $r['date_vente']]);
        }
        fclose($out);
        exit;
    }

    if ($format === 'pdf') {
        if (class_exists('Dompdf\Dompdf')) {
            $html = '<h2>Rapport ventes</h2><table border="1" cellpadding="4" cellspacing="0" width="100%"><thead>';
            $html .= '<tr><th>ID</th><th>Client</th><th>Total HTG</th><th>status</th><th>Date</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                $html .= '<tr>';
                $html .= '<td>' . h($r['vente_id']) . '</td>';
                $html .= '<td>' . h(trim($r['client_nom'] . ' ' . $r['client_prenom'])) . '</td>';
                $html .= '<td>' . number_format($r['total'], 2) . '</td>';
                $html .= '<td>' . h($r['status']) . '</td>';
                $html .= '<td>' . h($r['date_vente']) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $dompdf = new Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $dompdf->stream('rapport_ventes_' . date('Ymd_His') . '.pdf', ['Attachment' => 1]);
            exit;
        } else {
            echo "<h2>Rapport ventes</h2><table border='1'><tr><th>ID</th><th>Client</th><th>Total HTG</th><th>status</th><th>Date</th></tr>";
            foreach ($rows as $r) {
                echo "<tr><td>" . h($r['vente_id']) . "</td><td>" . h(trim($r['client_nom'] . ' ' . $r['client_prenom'])) . "</td><td>" . number_format($r['total'], 2) . "</td><td>" . h($r['status']) . "</td><td>" . h($r['date_vente']) . "</td></tr>";
            }
            echo "</table>";
            exit;
        }
    }
    jsonErr("Format non supporté.");
}

// ---------------- Page principale ----------------
$filters = [
    'q' => trim($_GET['q'] ?? ''),
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'status' => $_GET['status'] ?? ''
];

$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];
if ($filters['q'] !== '') {
    $where .= " AND (cl.nom LIKE :q OR cl.prenom LIKE :q)";
    $params[':q'] = "%{$filters['q']}%";
}
if ($filters['date_from'] !== '') {
    $where .= " AND DATE(date_vente)>=:df";
    $params[':df'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where .= " AND DATE(date_vente)<=:dt";
    $params[':dt'] = $filters['date_to'];
}
if ($filters['status'] !== '') {
    $where .= " AND status=:status";
    $params[':status'] = $filters['status'];
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM ventes v LEFT JOIN clients cl ON v.client_id=cl.client_id WHERE $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$sql = "SELECT v.*, cl.nom AS client_nom, cl.prenom AS client_prenom
      FROM ventes v
      LEFT JOIN clients cl ON v.client_id=cl.client_id
      WHERE $where
      ORDER BY date_vente DESC
      LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats - Montant par statut
$statsStmt = $pdo->prepare("SELECT 
    COUNT(*) AS total_ventes,
    COALESCE(SUM(total),0) AS total_montant,
    SUM(CASE WHEN status='Crédit' THEN total ELSE 0 END) AS Crédit,
    SUM(CASE WHEN status='Payée' THEN total ELSE 0 END) AS Payée,
    SUM(CASE WHEN status='Annulée' THEN total ELSE 0 END) AS Annulée
    FROM ventes v
    LEFT JOIN clients cl ON v.client_id=cl.client_id
    WHERE $where");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Charts
$lineLabels = [];
$totalsByMonth = [];
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $m = date('Y-m-01', strtotime("-{$i} months"));
    $lineLabels[] = date('M Y', strtotime($m));
    $months[] = $m;
    $totalsByMonth[] = 0;
}
$monthStmt = $pdo->prepare("SELECT DATE_FORMAT(date_vente,'%Y-%m-01') AS month_key, COALESCE(SUM(total),0) AS total
                           FROM ventes WHERE DATE(date_vente)>=:min_date GROUP BY month_key");
$monthStmt->execute([':min_date' => date('Y-m-01', strtotime('-11 months'))]);
$monthRows = $monthStmt->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($months as $idx => $mkey) {
    $totalsByMonth[$idx] = isset($monthRows[$mkey]) ? (float)$monthRows[$mkey] : 0.0;
}

// Status montant par statut
$statusStmt = $pdo->query("SELECT status, COALESCE(SUM(total),0) AS montant FROM ventes GROUP BY status");
$statusMontants = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);


$queryBase = [];
if ($filters['q'] !== '') $queryBase['q'] = $filters['q'];
if ($filters['date_from'] !== '') $queryBase['date_from'] = $filters['date_from'];
if ($filters['date_to'] !== '') $queryBase['date_to'] = $filters['date_to'];
if ($filters['status'] !== '') $queryBase['status'] = $filters['status'];
$qs = http_build_query($queryBase);

?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Rapport — Ventes</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 50;
        }

        .modal.active {
            display: flex;
        }
    </style>
</head>

<body class="bg-gray-100 font-sans text-gray-800">
    <div class="flex">
        <?php if (file_exists(__DIR__ . '/includes/menu_lateral.php')) include __DIR__ . '/includes/menu_lateral.php'; ?>
        <main class="flex-1 p-8 ml-64">
            <h1 class="text-2xl font-bold mb-4">💰 Rapport — Ventes</h1>

            <!-- Filters -->
            <div class="bg-white p-4 rounded-lg shadow mb-6">
                <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div>
                        <label class="text-sm text-gray-600">Recherche</label>
                        <input name="q" value="<?= h($filters['q']) ?>" class="w-full border rounded px-2 py-2" placeholder="Nom client..." />
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Date début</label>
                        <input type="date" name="date_from" value="<?= h($filters['date_from']) ?>" class="w-full border rounded px-2 py-2" />
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">Date fin</label>
                        <input type="date" name="date_to" value="<?= h($filters['date_to']) ?>" class="w-full border rounded px-2 py-2" />
                    </div>
                    <div>
                        <label class="text-sm text-gray-600">status</label>
                        <select name="status" class="w-full border rounded px-2 py-2">
                            <option value="">-- Tous --</option>
                            <option <?= $filters['status'] === 'Crédit' ? 'selected' : '' ?>>A crédit</option>
                            <option <?= $filters['status'] === 'Payée' ? 'selected' : '' ?>>Payée</option>
                            <option <?= $filters['status'] === 'Annulée' ? 'selected' : '' ?>>Annulée</option>
                        </select>
                    </div>
                    <div class="md:col-span-4 flex gap-2 mt-2">
                        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Appliquer</button>
                        <a href="rapport_ventes.php" class="bg-purple-500 text-white px-4 py-2 rounded">Réinitialiser</a>
                        <div class="ml-auto flex gap-2">
                            <a href="?action=export&format=csv&<?= h($qs) ?>" class="bg-green-600 text-white px-4 py-2 rounded">CSV</a>
                            <a href="?action=export&format=pdf&<?= h($qs) ?>" class="bg-yellow-600 text-white px-4 py-2 rounded">PDF</a>
                            <style>
                                @media print {
                                    body * {
                                        visibility: hidden;
                                    }

                                    #tableContainer,
                                    #tableContainer * {
                                        visibility: visible;
                                    }

                                    #tableContainer {
                                        position: absolute;
                                        top: 0;
                                        left: 0;
                                        width: 100%;
                                    }
                                }
                            </style>
                            <button id="printBtn" type="button" class="bg-gray-800 text-white px-4 py-2 rounded">Imprimer</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white p-4 rounded shadow">
                    <div class="text-sm text-gray-500">Nombre Total de Ventes :</div>
                    <div class="text-2xl font-bold"><?= (int)($stats['total_ventes'] ?? 0) ?> ventes</div>
                </div>
                <div class="bg-white p-4 rounded shadow">
                    <div class="text-sm text-gray-500">Cout Total des Ventes :</div>
                    <div class="text-2xl text-purple-500 font-bold"><?= number_format((float)($stats['total_montant'] ?? 0), 2) ?>HTG</div>
                </div>
                <div class="bg-white p-4 rounded shadow">
                    <div class="text-sm text-gray-500">CoutVentes Payées</div>
                    <div class="text-2xl text-green-500 font-bold"><?= number_format((float)($stats['Payée'] ?? 0), 2) ?>HTG</div>
                </div>
                <div class="bg-white p-4 rounded shadow">
                    <div class="text-sm text-gray-500">A crédit / Annulé :</div>
                    <div class="text-sm text-yellow-500">A crédit : <?= number_format((float)($stats['Crédit'] ?? 0), 2) ?> HTG</div>
                    <div class="text-sm text-red-500"> Annulée : <?= number_format((float)($stats['Annulée'] ?? 0), 2) ?> HTG</div>
                </div>
            </div>

            <!-- Charts -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
                <div class="col-span-2 bg-white p-4 rounded shadow">
                    <h3 class="font-semibold mb-2">Évolution mensuelle</h3>
                    <canvas id="lineChart" height="150"></canvas>
                </div>
                <div class="bg-white p-4 rounded shadow">
                    <h3 class="font-semibold mb-2">Répartition status</h3>
                    <canvas id="donutChart" height="150"></canvas>
                </div>
            </div>

            <!-- Table ventes -->

            <div id="tableContainer" class="bg-white p-4 rounded shadow overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 text-left">No. Recu</th>
                            <th class="p-2 text-left">Vendeur</th>
                            <th class="p-2 text-left">Nom du Client</th>
                            <th class="p-2 text-right">Total HTG</th>
                            <th class="p-2 text-left">Date</th>
                            <th class="p-2 text-left">Status Vente</th>
                            <th class="p-2 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="6" class="p-4 text-center text-gray-500">Aucune vente trouvée.</td>
                            </tr>
                            <?php else: foreach ($rows as $r): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="p-2">#<?= h($r['vente_id']) ?></td>
                                    <td class="p-2">@<?= h($r['username']) ?></td>
                                    <td class="p-2"><?= h(trim($r['client_nom'] . ' ' . $r['client_prenom'])) ?></td>
                                    <td class="p-2 text-right"><?= number_format($r['total'], 2) ?> HTG</td>
                                    <td class="p-2"><?= h($r['date_vente']) ?></td>
                                    <td class="p-2">
                                        <span class="px-2 py-1 rounded <?= $r['status'] === 'Payée' ? 'bg-green-100 text-green-700' : ($r['status'] === 'Annulé' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') ?>">
                                            <?= h($r['status']) ?></span>
                                    </td>
                                    <td class="p-2 text-center space-x-2">
                                        <button class="bg-indigo-600 text-white px-2 py-1 rounded btn-details" data-id="<?= (int)$r['vente_id'] ?>">Voir Details</button>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <div class="mt-4 flex items-center justify-between">
                    <div class="text-sm text-gray-600">
                        Affichage <?= ($offset + 1) ?> - <?= min($offset + count($rows), $totalRows) ?> sur <?= $totalRows ?> résultats
                    </div>
                    <div class="space-x-1">
                        <?php
                        $maxPagesToShow = 5;
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $startPage + $maxPagesToShow - 1);

                        // Ajuster le startPage si on est à la fin
                        if ($endPage - $startPage + 1 < $maxPagesToShow) {
                            $startPage = max(1, $endPage - $maxPagesToShow + 1);
                        }

                        if ($startPage > 1) {
                            echo '<a href="?page=1&' . h($qs) . '" class="px-3 py-1 rounded bg-white border">1</a>';
                            if ($startPage > 2) echo '<span class="px-2">…</span>';
                        }

                        for ($p = $startPage; $p <= $endPage; $p++): ?>
                            <a href="?page=<?= $p ?>&<?= h($qs) ?>" class="px-3 py-1 rounded <?= $p == $page ? 'bg-blue-600 text-white' : 'bg-white border' ?>"><?= $p ?></a>
                        <?php endfor;

                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) echo '<span class="px-2">…</span>';
                            echo '<a href="?page=' . $totalPages . '&' . h($qs) . '" class="px-3 py-1 rounded bg-white border">' . $totalPages . '</a>';
                        }
                        ?>
                    </div>
                </div>
            </div> <!-- fin table container -->

        </main>
    </div>

    <!-- Modal détails -->
    <div id="modal" class="modal">
        <div class="bg-white rounded-lg shadow-lg w-11/12 md:w-3/4 lg:w-1/2 p-6 relative">
            <button id="modalClose" class="absolute top-3 right-3 text-gray-600">✕</button>
            <div id="modalContent">
                <div class="text-center text-gray-500">Chargement...</div>
            </div>
        </div>
    </div>

    <script>
        const lineLabels = <?= json_encode($lineLabels) ?>;
        const lineData = <?= json_encode($totalsByMonth) ?>;
        const statusMontants = <?= json_encode(array_values($statusMontants)) ?>;
        const statusLabels = <?= json_encode(array_keys($statusMontants)) ?>;


        const ctxLine = document.getElementById('lineChart').getContext('2d');
        new Chart(ctxLine, {
            type: 'line',
            data: {
                labels: lineLabels,
                datasets: [{
                    label: 'Montant HTG',
                    data: lineData,
                    fill: true,
                    tension: 0.3,
                    borderColor: 'rgb(37,99,235)',
                    backgroundColor: 'rgba(37,99,235,0.08)'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        const ctxDonut = document.getElementById('donutChart').getContext('2d');
        new Chart(ctxDonut, {
            type: 'doughnut',
            data: {
                labels: statusLabels,
                datasets: [{
                    data: statusMontants,
                    backgroundColor: ['#10B981', '#F59E0B', '#EF4444']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Modal détails
        const modal = document.getElementById('modal');
        const modalContent = document.getElementById('modalContent');
        document.getElementById('modalClose').addEventListener('click', () => modal.classList.remove('active'));

        document.querySelectorAll('.btn-details').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                modal.classList.add('active');
                modalContent.innerHTML = '<div class="text-center p-6">Chargement...</div>';
                try {
                    const res = await fetch('?action=details&id=' + encodeURIComponent(id));
                    const data = await res.json();
                    if (!data.ok) {
                        modalContent.innerHTML = '<div class="text-red-600 p-4">Erreur</div>';
                        return;
                    }
                    const d = data.data;

                    let itemsHtml = '';
                    if (d.items && d.items.length > 0) {
                        itemsHtml = '<table class="w-full text-sm border mt-2"><thead class="bg-gray-100"><tr><th>Produit</th><th>Qté</th><th>PU</th><th>Total</th></tr></thead><tbody>';
                        d.items.forEach(item => {
                            itemsHtml += `<tr>
            <td class="border px-2 py-1">${escapeHtml(item.produit_nom ?? item.nom)}</td>
            <td class="border px-2 py-1 text-center">${item.quantite}</td>
            <td class="border px-2 py-1 text-right">${parseFloat(item.prix_vente).toFixed(2)}</td>
            <td class="border px-2 py-1 text-right">${parseFloat(item.quantite*item.prix_vente).toFixed(2)}</td>
        </tr>`;
                        });
                        itemsHtml += '</tbody></table>';
                    } else {
                        itemsHtml = '<div class="text-gray-500 mt-2">Aucun article trouvé.</div>';
                    }

                    modalContent.innerHTML = `
<h2 class="text-lg font-bold mb-2">Reçu Vente #${d.vente.vente_id}</h2>
<div class="grid grid-cols-1 md:grid-cols-2 gap-2">
  <div><strong>Client:</strong> ${escapeHtml(d.vente.client_nom+' '+d.vente.client_prenom)}</div>
  <div><strong>Date:</strong> ${d.vente.date_vente}</div>
  <div><strong>Status:</strong> ${escapeHtml(d.vente.status)}</div>
  <div><strong>Total:</strong> ${parseFloat(d.vente.total).toFixed(2)} HTG</div>
</div>
<div class="mt-4">${itemsHtml}</div>
<div class="mt-4 text-right">
  <button onclick="modal.classList.remove('active')" class="px-3 py-1 bg-gray-300 rounded">Fermer</button>
</div>`;

                } catch (e) {
                    modalContent.innerHTML = '<div class="text-red-600 p-4">Erreur réseau</div>';
                }
            });
        });

        // Update statut
        document.querySelectorAll('.btn-set-status').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = btn.dataset.id;
                const statut = btn.dataset.status;
                if (!confirm(`Changer le statut pour #${id} → ${statut} ?`)) return;
                try {
                    const form = new FormData();
                    form.append('action', 'update_status');
                    form.append('id', id);
                    form.append('statut', statut);
                    const res = await fetch('?action=update_status', {
                        method: 'POST',
                        body: form
                    });
                    const json = await res.json();
                    if (json.ok) {
                        alert('Statut mis à jour');
                        location.reload();
                    } else alert('Erreur: ' + (json.error || 'inconnu'));
                } catch (e) {
                    alert('Erreur réseau');
                }
            });
        });

        document.getElementById('printBtn').addEventListener('click', async () => {
            try {
                // On va charger toutes les ventes sans limite pour l'impression
                const params = new URLSearchParams(<?= json_encode($queryBase) ?>);
                params.append('all', '1'); // flag pour indiquer qu'on veut tout
                const res = await fetch('rapport_ventes_print.php?' + params.toString());
                const html = await res.text();

                const printWindow = window.open('', '_blank');
                printWindow.document.write(html);
                printWindow.document.close();
                printWindow.print();
            } catch (e) {
                alert('Erreur lors du chargement pour impression');
            }
        });

        function escapeHtml(s) {
            if (!s) return '';
            return String(s).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", "&#039;");
        }

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