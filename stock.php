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

$sql = "SELECT p.produit_id, p.nom, p.quantite, p.prix_achat, p.prix_vente,
               (p.prix_vente - p.prix_achat) AS marge
        FROM produits p
        ORDER BY p.nom ASC";
$produits = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 🔹 Paramètres de pagination
$perPage = 11; // Nombre de produits par page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// 🔹 Compter le total des produits
$totalProduits = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
$totalPages = ceil($totalProduits / $perPage);

// 🔹 Récupérer les produits paginés
$stmt = $pdo->prepare("SELECT * FROM produits ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_marge = 0;

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Rapport du stock</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 font-sans">
    <?php include 'includes/menu_lateral.php'; ?>
    <main class="ml-64 p-8">
        <div class="flex justify-end mb-4">
            <button onclick="printSection('stockBlock')"
                class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                🖨️ Imprimer la liste
            </button>
        </div>
        <div id="stockBlock">
            <h1 class="text-2xl font-bold mb-4">📦 Liste des Produits en Stock</h1>
            <div class="overflow-x-auto rounded-lg">
                <!--calcul de la marge globale du stock  -->
                <?php
                $stmt = $pdo->query("SELECT SUM((prix_vente - prix_achat) * quantite) AS marge_totale FROM produits");
                $total_marge_globale = $stmt->fetch(PDO::FETCH_ASSOC)['marge_totale'];
                ?>
                <div class="mb-4 p-4 bg-gray-100 rounded shadow text-right font-bold">
                    💰 Marge Globale du Stock : <?= number_format($total_marge_globale, 2) ?> HTG
                </div>
                <table class="min-w-full text-sm border rounded-lg">
                    <thead class="bg-blue-600 text-white uppercase text-xs border-b">
                        <tr>
                            <th class="p-3 text-left">Produit</th>
                            <th class="p-3 text-right">Quantité</th>
                            <th class="p-3 text-right">Prix achat</th>
                            <th class="p-3 text-right">Prix vente</th>
                            <th class="p-3 text-right">Marge Beneficiaire</th>
                            <th class="p-3 text-right">Status Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($produits as $p): ?>
                            <?php
                            $benefice = ($p['prix_vente'] - $p['prix_achat']); // bénéfice unitaire
                            // ou $benefice_total = $benefice * $p['quantite']; si tu veux le total
                            $total_marge += $benefice * $p['quantite'];

                            ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 text-left"><?= htmlspecialchars($p['nom']) ?></td>
                                <td class="p-3 text-right"><?= number_format($p['quantite'], 2) ?></td>
                                <td class="p-3 text-right"><?= number_format($p['prix_achat'], 2) ?> HTG</td>
                                <td class="p-3 text-right"><?= number_format($p['prix_vente'], 2) ?> HTG</td>
                                <td class="p-3 text-right"><?= number_format($benefice * $p['quantite'], 2) ?> HTG</td>
                                <?php
                                if ($p['quantite'] == 0) {
                                    $statutTexte = 'Rupture de Stock';
                                    $statutClass = 'text-red-600 font-bold';
                                } elseif ($p['quantite'] <= 10) {
                                    $statutTexte = 'Stock Faible';
                                    $statutClass = 'text-yellow-600 font-semibold';
                                } else {
                                    $statutTexte = 'Stock Normal';
                                    $statutClass = 'text-green-600';
                                }
                                ?>
                                <td class="p-3 text-right <?= $statutClass ?>">
                                    <?= $statutTexte ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <!-- 🔹 Pied de tableau : total des marges -->
                    <tfoot class="bg-gray-100 font-bold">
                        <tr>
                            <td colspan="4" class="p-3 text-right">Total Marge :</td>
                            <td class="p-3 text-right text-blue-700"><?= number_format($total_marge, 2) ?> HTG</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <!-- Pagination -->
        <div class="mt-4 flex justify-center space-x-2">
            <?php
            // Bouton "Précédent"
            if ($page > 1) {
                $prevPage = $page - 1;
                echo "<a href='stock.php?page=$prevPage' class='px-3 py-1 rounded bg-white text-blue-600 border hover:bg-blue-50'>◀ Précédent</a>";
            }

            // Calcul des pages à afficher (max 5)
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);

            if ($page <= 3) {
                $start = 1;
                $end = min(5, $totalPages);
            } elseif ($page > $totalPages - 2) {
                $start = max(1, $totalPages - 4);
                $end = $totalPages;
            }

            // Premier bouton si pas au début
            if ($start > 1) {
                echo "<a href='stock.php?page=1' class='px-3 py-1 rounded bg-white text-blue-600 border'>1</a>";
                if ($start > 2) echo "<span class='px-2 py-1'>…</span>";
            }

            // Boutons centraux
            for ($i = $start; $i <= $end; $i++) {
                $activeClass = $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border';
                echo "<a href='stock.php?page=$i' class='px-3 py-1 rounded $activeClass'>$i</a>";
            }

            // Dernier bouton si pas à la fin
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo "<span class='px-2 py-1'>…</span>";
                echo "<a href='stock.php?page=$totalPages' class='px-3 py-1 rounded bg-white text-blue-600 border'>$totalPages</a>";
            }

            // Bouton "Suivant"
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                echo "<a href='stock.php?page=$nextPage' class='px-3 py-1 rounded bg-white text-blue-600 border hover:bg-blue-50'>Suivant ▶</a>";
            }
            ?>
        </div>

    </main>
    <script>
        const tbody = document.getElementById('stock-tbody');
        const paginationDiv = document.getElementById('stock-pagination');
        const coutTotalCell = document.querySelector('tfoot tr td:last-child');

        paginationDiv.addEventListener('click', e => {
            if (e.target.tagName === 'A') {
                e.preventDefault();
                const page = e.target.dataset.page;
                fetch(`ajax_stock.php?page=${page}`)
                    .then(res => res.json())
                    .then(data => {
                        tbody.innerHTML = data.tbody;
                        paginationDiv.innerHTML = data.pagination;
                        coutTotalCell.textContent = data.cout_total; // Met à jour le coût total
                    });
            }
        });

        function printSection(id) {
            // Récupère le contenu du bloc
            const content = document.getElementById(id).innerHTML;

            // Ouvre une nouvelle fenêtre temporaire
            const printWindow = window.open('', '', 'width=900,height=700');
            printWindow.document.write(`
        <html>
            <head>
                <title>Impression</title>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
            </head>
            <body class="p-6">
                ${content}
            </body>
        </html>
    `);
            printWindow.document.close();

            // Lance l'impression une fois le contenu chargé
            printWindow.onload = function() {
                printWindow.focus();
                printWindow.print();
                printWindow.close();
            };
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