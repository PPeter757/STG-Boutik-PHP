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

// Récupérer filtres
$groupe = $_GET['groupe'] ?? '';
$recherche = $_GET['recherche'] ?? '';

// Pagination
$perPage = 8;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Récupérer tous les groupes pour le select
$groupes = $pdo->query("SELECT DISTINCT groupe FROM clients")->fetchAll(PDO::FETCH_COLUMN);

// Construire la requête SQL pour clients avec ventes en crédit uniquement
$sqlBase = "FROM clients c
            INNER JOIN ventes v ON c.client_id = v.client_id AND v.status = 'Crédit'
            WHERE 1=1";

$params = [];

// Filtre par groupe
if (!empty($groupe)) {
    $sqlBase .= " AND c.groupe = :groupe";
    $params[':groupe'] = $groupe;
}

// Filtre par recherche
if (!empty($recherche)) {
    $sqlBase .= " AND (c.nom LIKE :recherche OR c.prenom LIKE :recherche)";
    $params[':recherche'] = "%$recherche%";
}

// Compter total clients pour pagination
$countSql = "SELECT COUNT(DISTINCT c.client_id) as total " . $sqlBase;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalClients = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalClients / $perPage));

// Requête finale avec GROUP BY et LIMIT, tri initial par date décroissante
$sql = "SELECT 
    c.client_id,
    c.groupe,
    c.nom,
    c.prenom,
    COUNT(v.vente_id) AS nb_ventes_credit,
    COALESCE(SUM(v.total),0) AS total_achats_credit,
    MAX(v.date_vente) AS derniere_vente_credit
" . $sqlBase . "
GROUP BY c.client_id, c.groupe, c.nom, c.prenom
ORDER BY derniere_vente_credit DESC
LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

// Lier les paramètres dynamiques (groupe, recherche)
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}

// Lier LIMIT et OFFSET
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$aujourdhui = new DateTime();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Rapport Clients à Crédit</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        th {
            cursor: pointer;
        }

        @media print {
            body * {
                visibility: hidden;
            }

            #printable,
            #printable * {
                visibility: visible;
            }

            #printable {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
        }
    </style>
</head>

<body class="bg-gray-100 font-sans">
    <?php include 'includes/menu_lateral.php'; ?>
    <main class="ml-64 p-8 space-y-6">
        <h1 class="text-2xl font-bold text-gray-700">🧾 Rapport Clients à Crédit</h1>

        <!-- Formulaire de recherche et filtre -->
        <form method="get" class="mb-6 flex gap-4">
            <input type="text" name="recherche" placeholder="Rechercher nom/prénom" value="<?= htmlspecialchars($recherche) ?>" class="p-2 border rounded">
            <select name="groupe" class="p-2 border rounded">
                <option value="">Tous les groupes</option>
                <?php foreach ($groupes as $grp): ?>
                    <option value="<?= htmlspecialchars($grp) ?>" <?= $groupe == $grp ? 'selected' : '' ?>><?= htmlspecialchars($grp) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="bg-blue-600 text-white px-4 rounded">Filtrer</button>
        </form>

        <div class="flex justify-end mb-4">
            <button onclick="window.print()" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">🖨️ Imprimer le rapport</button>
        </div>

        <div id="printable" class="bg-white p-6 rounded-lg shadow overflow-x-auto pr-2">
            <h2 class="text-lg font-semibold mb-4">Liste des ventes à crédit</h2>
            <table id="clientsTable" class="w-full text-left border-b">
                <thead class="bg-blue-600 text-white border-b uppercase text-xs">
                    <tr>
                        <th class="p-2" onclick="sortTable(0)">Groupe</th>
                        <th class="p-2" onclick="sortTable(1)">Nom Client</th>
                        <th class="p-2" onclick="sortTable(2)">Nombre d’achats</th>
                        <th class="p-2" onclick="sortTable(3)">Date vente</th>
                        <th class="p-2" onclick="sortTable(4)">Total à crédit</th>
                        <th class="p-2" onclick="sortTable(5)">Nb. Jour</th>
                        <th class="p-2" onclick="sortTable(6)">Alerte > 30 jours</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_credits = 0;

                    foreach ($clients as $c):
                        $alerte = '';
                        $ligne_class = '';
                        $jours_depuis_vente = '-';

                        if (!empty($c['derniere_vente_credit'])) {
                            $dernier = new DateTime($c['derniere_vente_credit']);
                            $diff = $dernier->diff($aujourdhui)->days;
                            $jours_depuis_vente = $diff;

                            if ($diff > 30) {
                                $alerte = "⚠️ Plus de $diff jours impayer";
                                $ligne_class = 'bg-red-100';
                            } else {
                                $alerte = '-';
                            }

                            $total_credits += $c['total_achats_credit'];
                        }
                    ?>
                        <tr class="<?= $ligne_class ?> border-b hover:bg-gray-50">
                            <td class="p-2"><?= htmlspecialchars($c['groupe']) ?></td>
                            <td class="p-2"><?= htmlspecialchars($c['nom'] . ' ' . $c['prenom']) ?></td>
                            <td class="p-2"><?= $c['nb_ventes_credit'] ?></td>
                            <td class="p-2"><?= $dernier->format('d/m/Y H:i') ?></td>
                            <td class="p-2  text-red-600 text-right"><?= number_format($c['total_achats_credit'], 2) ?> HTG</td>
                            <td class="p-2"><?= $jours_depuis_vente . ' ' . ($jours_depuis_vente < 2 ? 'jour' : 'jours') ?></td>
                            <td class="p-2"><?= $alerte ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-gray-200 font-bold">
                        <td class="p-2" colspan="4">Total des ventes à crédit</td>
                        <td class="p-2"><?= number_format($total_credits, 2) ?> HTG</td>
                        <td class="p-2" colspan="2"></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Pagination -->
            <div class="mt-4 flex justify-center space-x-2">
                <?php
                $maxPagesToShow = 5;

                $start = max(1, $page - floor($maxPagesToShow / 2));
                $end = min($totalPages, $start + $maxPagesToShow - 1);

                if ($end - $start < $maxPagesToShow - 1) {
                    $start = max(1, $end - $maxPagesToShow + 1);
                }

                $queryBase = [];
                if ($groupe) $queryBase['groupe'] = $groupe;
                if ($recherche) $queryBase['recherche'] = $recherche;
                $queryStr = http_build_query($queryBase);

                if ($start > 1) {
                    echo '<span class="px-3 py-1 text-gray-500">...</span>';
                }
                for ($i = $start; $i <= $end; $i++) {
                    $url = "rapport_clients.php?page=$i" . ($queryStr ? "&$queryStr" : "");
                    $activeClass = $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border';
                    echo "<a href='" . htmlspecialchars($url) . "' class='px-3 py-1 rounded $activeClass'>$i</a>";
                }
                if ($end < $totalPages) {
                    echo '<span class="px-3 py-1 text-gray-500">...</span>';
                    $url = "rapport_clients.php?page=$totalPages" . ($queryStr ? "&$queryStr" : "");
                    echo "<a href='" . htmlspecialchars($url) . "' class='px-3 py-1 rounded bg-white text-blue-600 border'>$totalPages</a>";
                }
                ?>
            </div>

        </div>
    </main>

    <script>
        // Tri amélioré des colonnes
        function sortTable(n) {
            const table = document.getElementById("clientsTable");
            let switching = true,
                dir = "asc",
                switchcount = 0;

            while (switching) {
                switching = false;
                const rows = table.rows;

                for (let i = 1; i < rows.length - 1; i++) {
                    let shouldSwitch = false;
                    let x = rows[i].getElementsByTagName("TD")[n];
                    let y = rows[i + 1].getElementsByTagName("TD")[n];
                    let xContent = x.innerText.trim();
                    let yContent = y.innerText.trim();

                    if (n === 2) { // Date vente
                        xContent = xContent === '-' ? 0 : new Date(xContent.split('/').reverse().join('-')).getTime();
                        yContent = yContent === '-' ? 0 : new Date(yContent.split('/').reverse().join('-')).getTime();
                    } else if (n === 1 || n === 3 || n === 4) { // Nombre ou montant
                        xContent = parseFloat(xContent.replace(/[^0-9.-]+/g, "")) || 0;
                        yContent = parseFloat(yContent.replace(/[^0-9.-]+/g, "")) || 0;
                    }

                    if ((dir === "asc" && xContent > yContent) || (dir === "desc" && xContent < yContent)) {
                        shouldSwitch = true;
                        break;
                    }
                }

                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    switchcount++;
                } else if (switchcount === 0 && dir === "asc") {
                    dir = "desc";
                    switching = true;
                }
            }
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