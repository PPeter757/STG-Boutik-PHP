<?php
// rapport_commandes_sur_commande.php
// Rapport & gestion des commandes sur commande (liste, stats, export, details, changement statut)

// ---------- Init & sécurité ----------
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

// Empêcher cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Vérifier connexion et rôle
require_once __DIR__ . '/includes/db.php'; // doit fournir $pdo (PDO)
require_once __DIR__ . '/includes/role_check.php';

// Autoriser administrateur et superviseur (adapter si besoin)
checkRole(['administrateur', 'superviseur']);

// ------------- Helpers ----------------
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

// ------------- Actions AJAX / Export ----------------
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action === 'details' && isset($_GET['id'])) {
  // Retourne les détails d'une commande_sur_commande (AJAX JSON)
  $id = (int)$_GET['id'];
  $stmt = $pdo->prepare("
        SELECT csc.*, v.client_id, v.total AS vente_total, cl.nom AS client_nom, cl.prenom AS client_prenom,
               p.nom AS produit_nom
        FROM commandes_sur_commande csc
        LEFT JOIN ventes v ON csc.vente_id = v.vente_id
        LEFT JOIN clients cl ON v.client_id = cl.client_id
        LEFT JOIN produits p ON csc.produit_id = p.produit_id
        WHERE csc.id = ?
    ");
  $stmt->execute([$id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) jsonErr("Commande introuvable.");
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'data' => $row]);
  exit;
}

if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  // POST: id, statut
  $id = (int)($_POST['id'] ?? 0);
  $statut = $_POST['statut'] ?? null;
  $allowed = ['En attente', 'Livré', 'Annulé'];
  if (!$id || !in_array($statut, $allowed)) jsonErr("Données invalides.");
  $stmt = $pdo->prepare("UPDATE commandes_sur_commande SET statut = ? WHERE id = ?");
  $stmt->execute([$statut, $id]);
  jsonOk(['id' => $id, 'statut' => $statut]);
}

// Export CSV / PDF
if ($action === 'export' && isset($_GET['format'])) {
  $format = $_GET['format'];
  // Reuse simple query (no filters for now or include filters)
  $where = "1=1";
  $params = [];

  // Optional filters from GET (search_date_from, search_date_to, statut)
  if (!empty($_GET['date_from'])) {
    $where .= " AND DATE(date_commande) >= :df";
    $params[':df'] = $_GET['date_from'];
  }
  if (!empty($_GET['date_to'])) {
    $where .= " AND DATE(date_commande) <= :dt";
    $params[':dt'] = $_GET['date_to'];
  }
  if (!empty($_GET['statut'])) {
    $where .= " AND statut = :statut";
    $params[':statut'] = $_GET['statut'];
  }

  $sql = "SELECT csc.*, p.nom AS produit_nom, cl.nom AS client_nom, cl.prenom AS client_prenom, v.total AS vente_total
            FROM commandes_sur_commande csc
            LEFT JOIN produits p ON csc.produit_id = p.produit_id
            LEFT JOIN ventes v ON csc.vente_id = v.vente_id
            LEFT JOIN clients cl ON v.client_id = cl.client_id
            WHERE $where
            ORDER BY csc.date_commande DESC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if ($format === 'csv') {
    $filename = 'rapport_commandes_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Vente ID', 'Produit ID', 'Produit', 'Quantité', 'Prix vente', 'Montant', 'Date commande', 'Statut', 'Client']);
    foreach ($rows as $r) {
      fputcsv($out, [
        $r['id'],
        $r['vente_id'],
        $r['produit_id'],
        $r['nom'] ?? $r['produit_nom'] ?? '',
        $r['quantite'],
        $r['prix_vente'],
        number_format($r['quantite'] * $r['prix_vente'], 2, '.', ''),
        $r['date_commande'],
        $r['statut'],
        trim(($r['client_nom'] ?? '') . ' ' . ($r['client_prenom'] ?? ''))
      ]);
    }
    fclose($out);
    exit;
  }

  if ($format === 'pdf') {
    // Try Dompdf if installed; fallback to printable HTML
    if (class_exists('Dompdf\Dompdf')) {
      // build simple HTML
      $html = '<h2>Rapport commandes sur commande</h2>';
      $html .= '<table border="1" cellpadding="4" cellspacing="0" width="100%"><thead>';
      $html .= '<tr><th>ID</th><th>Vente</th><th>Produit</th><th>Quantité</th><th>Prix</th><th>Date</th><th>Statut</th><th>Client</th></tr></thead><tbody>';
      foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td>' . h($r['id']) . '</td>';
        $html .= '<td>' . h($r['vente_id']) . '</td>';
        $html .= '<td>' . h($r['nom'] ?? $r['produit_nom']) . '</td>';
        $html .= '<td>' . h($r['quantite']) . '</td>';
        $html .= '<td>' . h(number_format($r['prix_vente'], 2)) . '</td>';
        $html .= '<td>' . h($r['date_commande']) . '</td>';
        $html .= '<td>' . h($r['statut']) . '</td>';
        $html .= '<td>' . h(trim(($r['client_nom'] ?? '') . ' ' . ($r['client_prenom'] ?? ''))) . '</td>';
        $html .= '</tr>';
      }
      $html .= '</tbody></table>';
      // generate PDF
      $dompdf = new Dompdf\Dompdf();
      $dompdf->loadHtml($html);
      $dompdf->setPaper('A4', 'landscape');
      $dompdf->render();
      $dompdf->stream('rapport_commandes_' . date('Ymd_His') . '.pdf', ['Attachment' => 1]);
      exit;
    } else {
      // Fallback: generate printable HTML page
      header('Content-Type: text/html; charset=utf-8');
      echo '<!doctype html><html><head><meta charset="utf-8"><title>Rapport PDF - Imprimer</title>
                  <style>table{width:100%;border-collapse:collapse}td,th{border:1px solid #ccc;padding:6px}</style></head><body>';
      echo '<h2>Rapport commandes sur commande</h2>';
      echo '<table><thead><tr><th>ID</th><th>Vente</th><th>Produit</th><th>Quantité</th><th>Prix</th><th>Date</th><th>Statut</th><th>Client</th></tr></thead><tbody>';
      foreach ($rows as $r) {
        echo '<tr>';
        echo '<td>' . h($r['id']) . '</td>';
        echo '<td>' . h($r['vente_id']) . '</td>';
        echo '<td>' . h($r['nom'] ?? $r['produit_nom']) . '</td>';
        echo '<td>' . h($r['quantite']) . '</td>';
        echo '<td>' . h(number_format($r['prix_vente'], 2)) . '</td>';
        echo '<td>' . h($r['date_commande']) . '</td>';
        echo '<td>' . h($r['statut']) . '</td>';
        echo '<td>' . h(trim(($r['client_nom'] ?? '') . ' ' . ($r['client_prenom'] ?? ''))) . '</td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
      echo '<script>window.print()</script></body></html>';
      exit;
    }
  }

  // otherwise unsupported format
  jsonErr("Format d'export non supporté.");
}

// ------------- Page principale : filtres, listing, stats, pagination ---------------

// Filtres
$filters = [
  'q' => trim($_GET['q'] ?? ''),
  'date_from' => $_GET['date_from'] ?? '',
  'date_to' => $_GET['date_to'] ?? '',
  'statut' => $_GET['statut'] ?? ''
];

// Pagination
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Build where
$where = "1=1";
$params = [];

if ($filters['q'] !== '') {
  $where .= " AND (csc.nom LIKE :q OR p.nom LIKE :q OR cl.nom LIKE :q OR cl.prenom LIKE :q)";
  $params[':q'] = "%{$filters['q']}%";
}
if ($filters['date_from'] !== '') {
  $where .= " AND DATE(csc.date_commande) >= :df";
  $params[':df'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
  $where .= " AND DATE(csc.date_commande) <= :dt";
  $params[':dt'] = $filters['date_to'];
}
if ($filters['statut'] !== '') {
  $where .= " AND csc.statut = :statut";
  $params[':statut'] = $filters['statut'];
}

// Count total
$countSql = "SELECT COUNT(*) FROM commandes_sur_commande csc
             LEFT JOIN ventes v ON csc.vente_id = v.vente_id
             LEFT JOIN clients cl ON v.client_id = cl.client_id
             LEFT JOIN produits p ON csc.produit_id = p.produit_id
             WHERE $where";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

// Fetch page
$sql = "SELECT csc.*, p.nom AS produit_nom, cl.nom AS client_nom, cl.prenom AS client_prenom, v.total AS vente_total
        FROM commandes_sur_commande csc
        LEFT JOIN ventes v ON csc.vente_id = v.vente_id
        LEFT JOIN clients cl ON v.client_id = cl.client_id
        LEFT JOIN produits p ON csc.produit_id = p.produit_id
        WHERE $where
        ORDER BY csc.date_commande DESC
        LIMIT :lim OFFSET :off";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats: total commandes, montants, counts par statut
$statsSql = "SELECT 
    COUNT(*) AS total_cmds,
    COALESCE(SUM(csc.quantite * csc.prix_vente),0) AS total_montant,
    SUM(CASE WHEN csc.statut='En attente' THEN 1 ELSE 0 END) AS en_attente,
    SUM(CASE WHEN csc.statut='Livré' THEN 1 ELSE 0 END) AS livre,
    SUM(CASE WHEN csc.statut='Annulé' THEN 1 ELSE 0 END) AS annule
    FROM commandes_sur_commande csc
    LEFT JOIN ventes v ON csc.vente_id = v.vente_id
    LEFT JOIN clients cl ON v.client_id = cl.client_id
    LEFT JOIN produits p ON csc.produit_id = p.produit_id
    WHERE $where";
$statsStmt = $pdo->prepare($statsSql);
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Montant total par produit pour le mois en cours
$currentMonthStart = date('Y-m-01');
$currentMonthEnd = date('Y-m-t');

$monthlyProductStmt = $pdo->prepare("
    SELECT p.nom AS produit_nom,
           COALESCE(SUM(csc.quantite * csc.prix_vente),0) AS montant_total
    FROM commandes_sur_commande csc
    LEFT JOIN produits p ON csc.produit_id = p.produit_id
    WHERE DATE(csc.date_commande) BETWEEN :start AND :end
    GROUP BY csc.produit_id, p.nom
    ORDER BY montant_total DESC
");
$monthlyProductStmt->execute([
  ':start' => $currentMonthStart,
  ':end' => $currentMonthEnd
]);

$monthlyProductTotals = $monthlyProductStmt->fetchAll(PDO::FETCH_KEY_PAIR);
// Exemple résultat : ['Produit A' => 15000, 'Produit B' => 7500]


// Chart data: monthly totals for last 12 months and counts by statut
$chartData = [];
$labels = [];
$totalsByMonth = [];
$months = [];
for ($i = 11; $i >= 0; $i--) {
  $m = date('Y-m-01', strtotime("-{$i} months"));
  $label = date('M Y', strtotime($m));
  $labels[] = $label;
  $months[] = $m;
  $totalsByMonth[] = 0.0;
}
$monthStmt = $pdo->prepare("
    SELECT DATE_FORMAT(date_commande, '%Y-%m-01') AS month_key, COALESCE(SUM(quantite * prix_vente),0) AS total
    FROM commandes_sur_commande
    WHERE DATE(date_commande) >= :min_date
    GROUP BY month_key
");
$monthStmt->execute([':min_date' => date('Y-m-01', strtotime('-11 months'))]);
$monthRows = $monthStmt->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($months as $idx => $mkey) {
  $totalsByMonth[$idx] = isset($monthRows[$mkey]) ? (float)$monthRows[$mkey] : 0.0;
}

// status counts for donut chart (avec mêmes filtres que le tableau)
// Totaux par statut (montant = quantite * prix_vente) avec filtres existants
$statusSql = "
    SELECT statut, COALESCE(SUM(quantite * prix_vente),0) AS total
    FROM commandes_sur_commande csc
    WHERE 1=1
";

$statusParams = [];
if ($filters['date_from'] !== '') {
  $statusSql .= " AND DATE(csc.date_commande) >= :df";
  $statusParams[':df'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
  $statusSql .= " AND DATE(csc.date_commande) <= :dt";
  $statusParams[':dt'] = $filters['date_to'];
}
if ($filters['statut'] !== '') {
  $statusSql .= " AND csc.statut = :statut";
  $statusParams[':statut'] = $filters['statut'];
}



// Totaux par statut (montant = quantite * prix_vente) avec filtres existants
$statusSql = "
    SELECT csc.statut, COALESCE(SUM(csc.quantite * csc.prix_vente),0) AS total
    FROM commandes_sur_commande csc
    WHERE 1=1
";

$statusParams = [];
if ($filters['date_from'] !== '') {
  $statusSql .= " AND DATE(csc.date_commande) >= :df";
  $statusParams[':df'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
  $statusSql .= " AND DATE(csc.date_commande) <= :dt";
  $statusParams[':dt'] = $filters['date_to'];
}
if ($filters['statut'] !== '') {
  $statusSql .= " AND csc.statut = :statut";
  $statusParams[':statut'] = $filters['statut'];
}

$statusSql .= " GROUP BY csc.statut";

$statusStmt = $pdo->prepare($statusSql);
$statusStmt->execute($statusParams);
$statusTotalsRaw = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

// Transformer en tableau clé => valeur pour Chart.js
$statusTotals = [];
foreach ($statusTotalsRaw as $row) {
  $statusTotals[$row['statut']] = (float)$row['total']; // <== utiliser 'total', pas 'c'
}

// Utiliser directement pour le donut chart
$statusCounts = $statusTotals;



// Appliquer les filtres date si existants
$statusParams = [];
if ($filters['date_from'] !== '') {
  $statusSql .= " AND DATE(csc.date_commande) >= :df";
  $statusParams[':df'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
  $statusSql .= " AND DATE(csc.date_commande) <= :dt";
  $statusParams[':dt'] = $filters['date_to'];
}

// Build query string for pagination links (preserve filters)
$queryBase = [];
if ($filters['q'] !== '') $queryBase['q'] = $filters['q'];
if ($filters['date_from'] !== '') $queryBase['date_from'] = $filters['date_from'];
if ($filters['date_to'] !== '') $queryBase['date_to'] = $filters['date_to'];
if ($filters['statut'] !== '') $queryBase['statut'] = $filters['statut'];
$qs = http_build_query($queryBase);

?>
<!doctype html>
<html lang="fr">

<head>
  <meta charset="utf-8">
  <title>Rapport — Commandes sur commande</title>
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
    <!-- menu lateral si tu as -->
    <?php if (file_exists(__DIR__ . '/includes/menu_lateral.php')) include __DIR__ . '/includes/menu_lateral.php'; ?>

    <main class="flex-1 p-8 ml-64">
      <h1 class="text-2xl font-bold mb-4">📦 Rapport — Ventes sur Commande</h1>

      <!-- Filters & actions -->
      <div class="bg-white p-4 rounded-lg shadow mb-6">
        <form method="get" id="filtersForm" class="grid grid-cols-1 md:grid-cols-4 gap-3">
          <div>
            <label class="text-sm text-gray-600">Recherche</label>
            <input name="q" value="<?= h($filters['q']) ?>" class="w-full border rounded px-2 py-2" placeholder="Produit, client, nom..." />
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
            <label class="text-sm text-gray-600">Statut</label>
            <select name="statut" class="w-full border rounded px-2 py-2">
              <option value="">-- Tous --</option>
              <option <?= $filters['statut'] === 'En attente' ? 'selected' : '' ?>>En attente</option>
              <option <?= $filters['statut'] === 'Livré' ? 'selected' : '' ?>>Livré</option>
              <option <?= $filters['statut'] === 'Annulé' ? 'selected' : '' ?>>Annulé</option>
            </select>
          </div>

          <div class="md:col-span-4 flex gap-2 mt-2">
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Appliquer</button>
            <a href="rapport_commandes_sur_commande.php" class="bg-purple-500 text-white px-4 py-2 rounded">Réinitialiser</a>

            <!-- Export -->
            <div class="ml-auto flex gap-2">
              <a href="?action=export&format=csv&<?= h($qs) ?>" class="bg-green-600 text-white px-4 py-2 rounded">Export CSV</a>
              <a href="?action=export&format=pdf&<?= h($qs) ?>" class="bg-yellow-600 text-white px-4 py-2 rounded">Export PDF</a>
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
          <div class="text-sm text-gray-500">Total commandes</div>
          <div class="text-2xl font-bold"><?= (int)($stats['total_cmds'] ?? 0) ?></div>
        </div>
        <div class="bg-white p-4 rounded shadow">
          <div class="text-sm text-gray-500">Montant total (HTG)</div>
          <div class="text-2xl font-bold"><?= number_format((float)($stats['total_montant'] ?? 0), 2) ?> HTG</div>
        </div>
        <div class="bg-white p-4 rounded shadow">
          <div class="text-sm text-gray-500">En attente</div>
          <div class="text-2xl font-bold"><?= (int)($stats['en_attente'] ?? 0) ?></div>
        </div>
        <div class="bg-white p-4 rounded shadow">
          <div class="text-sm text-gray-500">Livré / Annulé</div>
          <div class="text-2xl font-bold"><?= (int)($stats['livre'] ?? 0) ?> / <?= (int)($stats['annule'] ?? 0) ?></div>
        </div>
      </div>

      <!-- Charts -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="col-span-2 bg-white p-4 rounded shadow">
          <h3 class="font-semibold mb-2">Évolution mensuelle (12 mois)</h3>
          <canvas id="lineChart" height="150"></canvas>
        </div>
        <div class="bg-white p-4 rounded shadow">
          <h3 class="font-semibold mb-2">Répartition statut</h3>
          <canvas id="donutChart" height="150"></canvas>
        </div>
      </div>

      <!-- Table -->
      <div class="bg-white p-4 rounded shadow">
        <div id="tableContainer" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-gray-100">
              <tr>
                <th class="p-2 text-left">No. Recu</th>
                <th class="p-2 text-left">Produit</th>
                <th class="p-2 text-left">Quantité</th>
                <th class="p-2 text-left">Prix Unitaire</th>
                <th class="p-2 text-left">Total</th>
                <th class="p-2 text-left">Date Commande</th>
                <th class="p-2 text-left">Statut Commande</th>
                <th class="p-2 text-right">Actions Sur commande</th>
                <th class="p-2 text-center">Detail commande</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($rows)): ?>
                <tr>
                  <td colspan="9" class="p-4 text-center text-gray-500">Aucune commande trouvée.</td>
                </tr>
                <?php else: foreach ($rows as $r): ?>
                  <tr class="border-b hover:bg-gray-50">
                    <td class="p-2">#<?= h($r['vente_id']) ?></td>
                    <td class="p-2"><?= h($r['nom'] ?? $r['produit_nom'] ?? '—') ?></td>
                    <td class="p-2 text-right"><?= (int)$r['quantite'] ?></td>
                    <td class="p-2"><?= number_format($r['prix_vente'], 2) ?> HTG</td>
                    <td class="p-2 text-green-500 text-right"><?= number_format($r['quantite'] * $r['prix_vente'], 2) ?> HTG</td>
                    <td class="p-2"><?= h($r['date_commande']) ?></td>
                    <td class="p-2">
                      <span class="px-2 py-1 rounded <?= $r['statut'] === 'Livré' ? 'bg-green-100 text-green-700' : ($r['statut'] === 'Annulé' ? 'bg-red-100 text-red-700' : 'bg-yellow-100 text-yellow-700') ?>">
                        <?= h($r['statut']) ?>
                      </span>
                    </td>
                    <td class="p-2 text-right space-x-2">
                      <?php if ($r['statut'] !== 'Livré'): ?>
                        <button class="bg-green-600 text-white px-2 py-1 rounded btn-set-status" data-id="<?= (int)$r['id'] ?>" data-status="Livré">Marquer Livré</button>
                      <?php endif; ?>
                      <?php if ($r['statut'] !== 'Annulé'): ?>
                        <button class="bg-red-600 text-white px-2 py-1 rounded btn-set-status" data-id="<?= (int)$r['id'] ?>" data-status="Annulé">Annuler</button>
                      <?php endif; ?>
                    </td>
                    <td class="p-2 text-right">
                      <button class="bg-indigo-600 text-white px-2 py-1 rounded btn-details" data-id="<?= (int)$r['id'] ?>">Voir Details</button>
                    </td>
                  </tr>
              <?php endforeach;
              endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="mt-4 flex items-center justify-between">
          <div class="text-sm text-gray-600">
            Affichage <?= ($offset + 1) ?> - <?= min($offset + count($rows), $totalRows) ?> sur <?= $totalRows ?> résultats
          </div>
          <div class="space-x-1">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <a href="?page=<?= $p ?>&<?= h($qs) ?>" class="px-3 py-1 rounded <?= $p == $page ? 'bg-blue-600 text-white' : 'bg-white border' ?>"><?= $p ?></a>
            <?php endfor; ?>
          </div>
        </div>
      </div>

    </main>
  </div>

  <!-- Modal détails -->
  <div id="modal" class="modal">
    <div class="bg-white rounded-lg shadow-lg w-11/12 md:w-3/4 lg:w-1/2 p-6 relative">
      <button id="modalClose" class="absolute top-3 right-3 text-gray-600">✕</button>
      <div id="modalContent">
        <!-- contenu chargé par JS -->
        <div class="text-center text-gray-500">Chargement...</div>
      </div>
    </div>
  </div>

  <script>
    // ===================== Line Chart =====================
    const lineLabels = <?= json_encode($labels) ?>;
    const lineData = <?= json_encode($totalsByMonth) ?>;
    const ctxLine = document.getElementById('lineChart').getContext('2d');
    const lineChart = new Chart(ctxLine, {
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

    // ===================== Donut Chart =====================
    const ctxDonut = document.getElementById('donutChart').getContext('2d');
    const donutChart = new Chart(ctxDonut, {
      type: 'doughnut',
      data: {
        labels: <?= json_encode(array_keys($statusCounts)) ?>,
        datasets: [{
          data: <?= json_encode(array_values($statusCounts)) ?>,
          backgroundColor: ['#FBBF24','#10B981', '#EF4444']
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: 'bottom'
          },
          tooltip: {
            callbacks: {
              label: function(context) {
                const value = context.raw;
                const formatted = new Intl.NumberFormat('fr-FR', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                }).format(value);
                return context.label + ': ' + formatted + ' HTG';
              }
            }
          }
        }
      }
    });

    // ===================== Modal détails =====================
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
          modalContent.innerHTML = `
          <h2 class="text-lg font-bold mb-2">Commande #${d.id}</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <div><strong>Vente ID:</strong> ${d.vente_id ?? '—'}</div>
            <div><strong>Produit:</strong> ${escapeHtml(d.nom ?? d.produit_nom ?? '—')}</div>
            <div><strong>Quantité:</strong> ${d.quantite}</div>
            <div><strong>Prix vente:</strong> ${parseFloat(d.prix_vente).toFixed(2)} HTG</div>
            <div><strong>Total vente:</strong> ${parseFloat(d.prix_vente*d.quantite).toFixed(2)} HTG</div>
            <div><strong>Date commande:</strong> ${d.date_commande}</div>
            <div><strong>Statut:</strong> ${escapeHtml(d.statut)}</div>
            <div class="md:col-span-2"><strong>Client:</strong> ${escapeHtml((d.client_nom||'')+' '+(d.client_prenom||''))}</div>
          </div>
          <div class="mt-4 text-right">
            <button onclick="modal.classList.remove('active')" class="px-3 py-1 bg-gray-300 rounded">Fermer</button>
          </div>`;
        } catch (e) {
          modalContent.innerHTML = '<div class="text-red-600 p-4">Erreur réseau</div>';
        }
      });
    });

    // ===================== Update Status =====================
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
          } else {
            alert('Erreur: ' + (json.error || 'inconnu'));
          }
        } catch (e) {
          alert('Erreur réseau');
        }
      });
    });

    // ===================== Impression =====================
    document.getElementById('printBtn').addEventListener('click', async () => {
      try {
        const params = new URLSearchParams(<?= json_encode($queryBase) ?>);
        params.append('all', '1');
        const res = await fetch('rapport_commandes_print.php?' + params.toString());
        const html = await res.text();
        const printWindow = window.open('', '_blank');
        printWindow.document.write(html);
        printWindow.document.close();
        printWindow.print();
      } catch (e) {
        alert('Erreur lors du chargement pour impression');
      }
    });

    // ===================== Utilitaires =====================
    function escapeHtml(s) {
      if (!s) return '';
      return String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
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