<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Durée d'inactivité avant fermeture automatique (en secondes)
$timeout = 600; // 10 minutes

if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header("Location: logout.php?timeout=1");
        exit;
    }
}

$_SESSION['last_activity'] = time();

// Empêcher cache navigateur
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Vérification connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Vérification rôle
require_once 'includes/db.php';
require_once 'includes/role_check.php';
checkRole(['administrateur', 'superviseur', 'vendeur', 'caissier']);

try {
    // Récupérer produits pour stock
    $produits = $pdo->query("SELECT produit_id, code_barre, nom, quantite, prix_vente, stock_precedent, ajustement, created_at FROM produits ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    $cout_total = 0;
    foreach ($produits as &$p) {
        $p['stock_actuel'] = $p['quantite'] + ($p['ajustement'] ?? 0);
        $p['subtotal'] = $p['stock_actuel'] * $p['prix_vente'];
    }
    unset($p);
    $cout_total_page = 0;
    foreach ($produits as $p) {
        $cout_total_page += $p['subtotal'];
    }


    // Top 10 produits pour graphique
    $chart = $pdo->query("SELECT nom, quantite FROM produits ORDER BY quantite DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    $labels = array_column($chart, 'nom');
    $data = array_column($chart, 'quantite');
} catch (PDOException $e) {
    die("Erreur: " . $e->getMessage());
}

// Pagination
$perPage = 11;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $perPage;
$totalProduits = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
$totalPages = ceil($totalProduits / $perPage);

// Produits paginés
$stmt = $pdo->prepare("SELECT * FROM produits ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cout_total_page = 0;
foreach ($produits as &$p) {
    $p['stock_actuel'] = $p['quantite'] + ($p['ajustement'] ?? 0);
    $p['subtotal'] = $p['stock_actuel'] * $p['prix_vente'];
    $cout_total_page += $p['subtotal'];
}
unset($p);


?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Rapport du stock</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 font-sans">
    <?php include 'includes/menu_lateral.php'; ?>
    <main class="ml-64 p-8 space-y-6">

        <h1 class="text-2xl font-bold text-gray-700">📦 Rapport du stock</h1>

        <!-- Graphique Top 10 produits -->
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-lg font-semibold mb-4">Top 10 produits par stock</h2>
            <canvas id="chartStock" height="100"></canvas>
        </div>

        <!-- Recherche + export -->
        <div class="flex justify-between items-center mb-4">
            <input type="text" id="search" placeholder="Rechercher par nom ou code" class="border p-2 rounded w-1/3">
            <div class="space-x-2">
                <button onclick="window.location.href='print_stock.php'" class="bg-blue-600 text-white px-4 py-2 rounded">🖨 Imprimer</button>
                <button onclick="window.location.href='export_stock.php'" class="bg-green-600 text-white px-4 py-2 rounded">💾 Export CSV</button>
            </div>
        </div>

        <!-- Tableau stock -->
        <div class="bg-white p-6 rounded-lg shadow overflow-x-auto">
            <table class="min-w-full text-sm border rounded-lg" id="stockTable">
                <thead class="bg-blue-600 text-white uppercase text-xs border-b">
                    <tr>
                        <th class="p-3 text-left">Code Produit</th>
                        <th class="p-3 text-left">Nom Produit</th>
                        <th class="p-3 text-right">Stock Précédent</th>
                        <th class="p-3 text-right">Ajustement</th>
                        <th class="p-3 text-right">Stock Actuel</th>
                        <th class="p-3 text-right">Prix de Vente</th>
                        <th class="p-3 text-right">Total du Stock</th>
                    </tr>
                </thead>
                <tbody id="stock-tbody">
                    <?php foreach ($produits as $p): ?>
                        <tr class="border-b hover:bg-gray-50 <?= ($p['stock_actuel'] < 5) ? 'bg-red-100' : '' ?>">
                            <td class="p-2"><?= htmlspecialchars($p['code_barre']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($p['nom']) ?></td>
                            <td class="p-2 text-right"><?= $p['stock_precedent'] ?? 0 ?></td>
                            <td class="p-2 text-right">
                                <?php
                                $aj = $p['ajustement'] ?? 0;
                                if ($aj > 0) echo "<span class='text-green-600 font-semibold'>+$aj</span>";
                                elseif ($aj < 0) echo "<span class='text-red-600 font-semibold'>$aj</span>";
                                else echo "0";
                                ?>
                            </td>
                            <td class="p-3 text-right font-semibold text-blue-700"><?= $p['stock_actuel'] ?></td>
                            <td class="p-3 text-right"><?= number_format($p['prix_vente'], 2) ?> HTG</td>
                            <td class="p-3 text-right text-green-500 font-semibold"><?= number_format($p['subtotal'], 2) ?> HTG</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-green-500 font-bold">
                        <td colspan="6" class="p-2  text-white uppercase">Coût total du stock</td>
                        <td class="p-2 text-white text-right text-xl"><?= number_format($cout_total_page, 2) ?> HTG
                        </td>
                    </tr>
                </tfoot>
            </table>

            <!-- Pagination -->
            <div id="stock-pagination" class="mt-4 flex justify-center space-x-2">
                <?php
                $maxPagesToShow = 5;
                $start = max(1, $page - floor($maxPagesToShow / 2));
                $end = min($totalPages, $start + $maxPagesToShow - 1);
                if ($end - $start < $maxPagesToShow - 1) $start = max(1, $end - $maxPagesToShow + 1);
                if ($start > 1) echo '<span class="px-3 py-1 text-gray-500">...</span>';
                for ($i = $start; $i <= $end; $i++) {
                    $active = $i == $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border';
                    echo "<a href='#' data-page='$i' class='px-3 py-1 rounded $active'>$i</a>";
                }
                if ($end < $totalPages) {
                    echo '<span class="px-3 py-1 text-gray-500">...</span>';
                    echo "<a href='#' data-page='$totalPages' class='px-3 py-1 rounded bg-white text-blue-600 border'>$totalPages</a>";
                }
                ?>
            </div>
        </div>
    </main>

    <script>
        // Chart Top 10
        new Chart(document.getElementById('chartStock'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [{
                    label: 'Stock disponible',
                    data: <?= json_encode($data) ?>,
                    backgroundColor: 'rgba(16,185,129,0.6)'
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true
                    }
                }
            }
        });

        // Pagination AJAX
        const tbody = document.getElementById('stock-tbody');
        const paginationDiv = document.getElementById('stock-pagination');
        paginationDiv.addEventListener('click', e => {
            if (e.target.tagName === 'A') {
                e.preventDefault();
                const page = e.target.dataset.page;
                fetch(`ajax_stock.php?page=${page}`)
                    .then(res => res.json())
                    .then(data => {
                        tbody.innerHTML = data.tbody;
                        paginationDiv.innerHTML = data.pagination;
                    });
            }
        });

        // Recherche dynamique
        document.getElementById('search').addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            document.querySelectorAll('#stock-tbody tr').forEach(row => {
                const nom = row.children[1].textContent.toLowerCase();
                const code = row.children[0].textContent.toLowerCase();
                row.style.display = (nom.includes(filter) || code.includes(filter)) ? '' : 'none';
            });
        });

        // Export CSV
        document.getElementById('exportBtn').addEventListener('click', function() {
            let csv = 'Code,Nom,Stock Precedent,Ajustement,Stock Actuel,Prix de Vente,Total\n';
            document.querySelectorAll('#stock-tbody tr').forEach(row => {
                const cols = Array.from(row.children).map(td => td.textContent.replace(/\s+/g, ' ').trim());
                csv += cols.join(',') + '\n';
            });
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'rapport_stock.csv';
            link.click();
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