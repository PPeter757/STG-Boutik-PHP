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

// --- 1) Récupérer clients et produits pour filtres ---
$clients = $pdo->query("SELECT client_id, nom, prenom FROM clients ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);
$produits = $pdo->query("SELECT produit_id, nom FROM produits ORDER BY nom ASC")->fetchAll(PDO::FETCH_ASSOC);

// --- 2) Récupérer paramètres de filtrage ---
$client_id = intval($_GET['client_id'] ?? 0);
$produit_id = intval($_GET['produit_id'] ?? 0);
$status = $_GET['status'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// --- 3) Construire WHERE dynamiquement ---
$where = "1=1";
$params = [];

if ($client_id > 0) {
    $where .= " AND v.client_id = :client_id";
    $params[':client_id'] = $client_id;
}
if ($produit_id > 0) {
    $where .= " AND vp.produit_id = :produit_id";
    $params[':produit_id'] = $produit_id;
}
if ($status !== '') {
    $where .= " AND v.status = :status";
    $params[':status'] = $status;
}
if ($start_date !== '') {
    $where .= " AND v.date_vente >= :start_date";
    $params[':start_date'] = $start_date;
}
if ($end_date !== '') {
    $where .= " AND v.date_vente <= :end_date";
    $params[':end_date'] = $end_date;
}

// --- 4) Récupérer ventes filtrées ---
$sql = "SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom, SUM(vp.quantite) AS total_produits
        FROM ventes v
        LEFT JOIN clients c ON v.client_id = c.client_id
        LEFT JOIN vente_items vp ON v.vente_id = vp.vente_id
        WHERE $where
        GROUP BY v.vente_id
        ORDER BY v.date_vente DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 5) Préparer données pour graphique (ventes par mois) ---
$chartQuery = $pdo->query("SELECT MONTH(date_vente) AS mois, SUM(total) AS total
                           FROM ventes
                           GROUP BY MONTH(date_vente)
                           ORDER BY mois");
$chartLabels = [];
$chartData = [];
while ($row = $chartQuery->fetch()) {
    $chartLabels[] = date('F', mktime(0, 0, 0, $row['mois'], 1));
    $chartData[] = $row['total'];
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Rapports des ventes</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 font-sans">
    <?php include 'includes/menu_lateral.php'; ?>
    <main class="flex-1 ml-64 p-8 space-y-6">
        <h1 class="text-2xl font-bold text-gray-700">📊 Rapports des ventes</h1>

        <!-- Formulaire de filtres -->
        <form method="get" class="bg-white p-4 rounded-lg shadow flex flex-wrap gap-4 items-end">
            <div>
                <label class="text-gray-600 text-sm">Client :</label>
                <select name="client_id" class="border rounded px-2 py-1">
                    <option value="0">Tous</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['client_id'] ?>" <?= $c['client_id'] == $client_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nom'] . ' ' . $c['prenom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-gray-600 text-sm">Produit :</label>
                <select name="produit_id" class="border rounded px-2 py-1">
                    <option value="0">Tous</option>
                    <?php foreach ($produits as $p): ?>
                        <option value="<?= $p['produit_id'] ?>" <?= $p['produit_id'] == $produit_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-gray-600 text-sm">Status :</label>
                <select name="status" class="border rounded px-2 py-1">
                    <option value="">Tous</option>
                    <option value="Payée" <?= $status === 'Payée' ? 'selected' : '' ?>>Payée</option>
                    <option value="Crédit" <?= $status === 'Crédit' ? 'selected' : '' ?>>Crédit</option>
                    <option value="Annulée" <?= $status === 'Annulée' ? 'selected' : '' ?>>Annulée</option>
                </select>
            </div>
            <div>
                <label class="text-gray-600 text-sm">Date début :</label>
                <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="border rounded px-2 py-1">
            </div>
            <div>
                <label class="text-gray-600 text-sm">Date fin :</label>
                <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="border rounded px-2 py-1">
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Filtrer</button>
            <a href="rapports.php" class="text-gray-600 hover:underline">Réinitialiser</a>
            <a href="export_csv.php?<?= http_build_query($_GET) ?>" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">📥 Export CSV</a>
        </form>

        <!-- Tableau des ventes -->
        <div class="bg-white rounded-xl shadow overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-blue-600 text-white">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Client</th>
                        <th class="p-3">Date</th>
                        <th class="p-3">Total (HTG)</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Total Produits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ventes as $v): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3"><?= $v['vente_id'] ?></td>
                            <td class="p-3"><?= htmlspecialchars($v['client_nom'] . ' ' . $v['client_prenom']) ?></td>
                            <td class="p-3"><?= htmlspecialchars($v['date_vente']) ?></td>
                            <td class="p-3 text-green-600 font-bold"><?= number_format($v['total'], 2) ?></td>
                            <td class="p-3"><?= htmlspecialchars($v['status']) ?></td>
                            <td class="p-3"><?= $v['total_produits'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Graphique des ventes -->
        <div class="bg-white p-6 rounded-xl shadow">
            <h2 class="text-lg font-semibold mb-4">Ventes par mois</h2>
            <canvas id="ventesChart" height="100"></canvas>
        </div>
    </main>

    <script>
        const ctx = document.getElementById('ventesChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= json_encode($chartLabels) ?>,
                datasets: [{
                    label: 'Ventes (HTG)',
                    data: <?= json_encode($chartData) ?>,
                    borderColor: 'rgba(37,99,235,1)',
                    backgroundColor: 'rgba(37,99,235,0.2)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

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