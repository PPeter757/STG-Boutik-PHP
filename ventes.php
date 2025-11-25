<?php
// ventes.php (version améliorée - conserve tes fonctionnalités + modal "produit sur commande")

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- Timeout inactivity (10 minutes) ---
$timeout = 600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: logout.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

// Prevent cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Require auth and DB
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'includes/db.php';
require_once 'includes/role_check.php';
checkRole(['administrateur', 'caissier', 'vendeur']); // adapt if needed

// Load products and clients
$produits = $pdo->query("SELECT produit_id, nom, prix_vente, quantite, code_barre FROM produits ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$clients = $pdo->query("SELECT client_id, nom, prenom FROM clients ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);

// Cancel sale action (kept)
if (isset($_GET['action']) && $_GET['action'] === 'annuler' && isset($_GET['vente_id'])) {
    $vente_id = (int) $_GET['vente_id'];
    $stmt = $pdo->prepare("UPDATE ventes SET status='Annulée' WHERE vente_id=?");
    $stmt->execute([$vente_id]);
    header("Location: liste_ventes.php?annulee=1");
    exit;
}

// ----------------
// Search & Pagination for ventes list (kept functionality, improved binding)
// ----------------
$sqlWhere = "1=1";
$params = [];

// name filter
if (!empty($_GET['search_name'])) {
    $sqlWhere .= " AND (c.nom LIKE :qname OR c.prenom LIKE :qname)";
    $params[':qname'] = "%" . $_GET['search_name'] . "%";
}

// date filter
if (!empty($_GET['search_date'])) {
    $sqlWhere .= " AND DATE(v.date_vente) = :qdate";
    $params[':qdate'] = $_GET['search_date'];
}

// status filter
if (!empty($_GET['search_status'])) {
    $sqlWhere .= " AND v.status = :qstatus";
    $params[':qstatus'] = $_GET['search_status'];
}


// Mise à jour du statut si demandé
if (isset($_GET['action'], $_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['action'];

    if ($action === 'livrer') {
        $pdo->prepare("UPDATE commandes_sur_commande SET statut='Livré' WHERE id=?")->execute([$id]);
    } elseif ($action === 'annuler') {
        $pdo->prepare("UPDATE commandes_sur_commande SET statut='Annulé' WHERE id=?")->execute([$id]);
    }

    header("Location: ventes.php");
    exit;
}

// Récupération des commandes
$stmt = $pdo->query("
    SELECT c.*, 
           v.vente_id, v.date_vente, v.client_nom, v.client_prenom,
           p.nom AS produit_nom
    FROM commandes_sur_commande c
    LEFT JOIN ventes v ON c.vente_id = v.vente_id
    LEFT JOIN produits p ON c.produit_id = p.produit_id
    ORDER BY c.date_commande DESC
");

$commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// pagination
$perPage = 5;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// count total
$totalSql = "SELECT COUNT(*) FROM ventes v LEFT JOIN clients c ON v.client_id = c.client_id WHERE $sqlWhere";
$totalStmt = $pdo->prepare($totalSql);
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($total / $perPage));

// fetch ventes page
$sql = "SELECT v.*, c.nom AS client_nom, c.prenom AS client_prenom
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    ORDER BY v.date_vente DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$ventes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// preserve query for pagination links
$searchParams = [];
if (!empty($_GET['search_name'])) $searchParams['search_name'] = $_GET['search_name'];
if (!empty($_GET['search_date'])) $searchParams['search_date'] = $_GET['search_date'];
if (!empty($_GET['search_status'])) $searchParams['search_status'] = $_GET['search_status'];
$queryBase = http_build_query($searchParams);

// provide products JSON to JS
$produits_json = json_encode($produits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Pagination commande liste
$perPage = 5; // nombre de commandes par page
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Compter le total des commandes
$totalCommandes = $pdo->query("SELECT COUNT(*) FROM commandes_sur_commande")->fetchColumn();
$totalPages = ceil($totalCommandes / $perPage);

// Récupérer les commandes paginées
$stmt = $pdo->prepare("SELECT * FROM commandes_sur_commande ORDER BY date_commande DESC LIMIT :lim OFFSET :off");
$stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$commandes = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Gestion des ventes</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>
    <style>
        /* simple modal utility (kept lightweight) */
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 60;
        }

        .modal.active {
            display: flex;
        }

        .modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
        }

        .modal-card {
            position: relative;
            z-index: 61;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            max-width: 600px;
            width: 100%;
        }
    </style>
</head>

<body class="bg-gray-100 min-h-screen flex font-sans text-gray-800">

    <!-- include menu lateral (kept) -->
    <?php include __DIR__ . '/includes/menu_lateral.php'; ?>

    <main class="ml-64 flex-1 p-8 space-y-10">

        <!-- Header -->
        <header class="flex justify-between items-center bg-white shadow rounded-lg p-5">
            <h1 class="text-2xl font-bold text-blue-700">🧾 Nouvelle vente</h1>
            <div class="flex items-center gap-3">
                <button id="openCmdModalBtn" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">Achat sur commande</button>
                <button onclick="location.reload()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition">🔄 Actualiser</button>
            </div>
        </header>

        <!-- Main section: catalogue + panier -->
        <section class="grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- Catalogue -->
            <div class="space-y-3 max-h-[75vh] overflow-y-auto pr-2 sticky top-0 bg-white z-10 p-2 shadow-md rounded-lg border border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center gap-2">📦 Catalogue produits</h2>

                <div class="space-y-3 max-h-[65vh] overflow-y-auto pr-2 bg-white z-10 p-2 rounded-lg">

                    <!-- Search + scanner -->
                    <div id="search-container" class="sticky top-0 bg-white z-10 p-2 rounded-lg">
                        <form id="searchForm" class="mb-3 flex gap-2" onsubmit="return false;">
                            <input type="text" id="search_input" placeholder="Rechercher un produit ou code-barres..."
                                class="flex-1 border rounded-lg px-3 py-2 focus:ring-2 focus:ring-blue-400 outline-none">
                            <button type="button" id="resetSearch" class="bg-gray-200 px-3 py-2 rounded-lg hover:bg-gray-300">🔁</button>
                        </form>

                        <div class="mt-5 text-center">
                            <button id="scan_btn" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg shadow-sm transition">📷 Scanner un code-barres</button>
                            <div id="scanner_container" class="hidden mt-3 relative w-full h-64 border rounded overflow-hidden"></div>
                        </div>
                    </div>

                    <!-- dynamic products list -->
                    <div id="produits-list" class="space-y-2"></div>

                    <!-- static fallback list (hidden when JS active) -->
                    <div id="static-products" class="hidden">
                        <?php foreach ($produits as $p): ?>
                            <div class="flex justify-between items-center border-b pb-2 hover:bg-gray-50 rounded transition">
                                <div>
                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($p['nom']) ?></p>
                                    <p class="text-sm text-gray-500">Stock: <?= $p['quantite'] ?></p>
                                    <p class="text-xs text-gray-400">Code-barres: <?= htmlspecialchars($p['code_barre']) ?></p>
                                </div>
                                <button class="add-to-cart bg-blue-500 hover:bg-blue-600 text-white text-sm px-3 py-1 rounded-lg shadow-sm"
                                    data-id="<?= $p['produit_id'] ?>"
                                    data-nom="<?= htmlspecialchars($p['nom']) ?>"
                                    data-prix_vente="<?= $p['prix_vente'] ?>"
                                    data-code_barre="<?= htmlspecialchars($p['code_barre']) ?>">
                                    ➕ Ajouter
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>

            <!-- Panier -->
            <div class="space-y-3 max-h-[75vh] overflow-y-auto pr-2 sticky top-0 bg-white z-10 p-2 shadow-md rounded-lg border border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700 mb-4">🛒 Panier</h2>

                <div class="space-y-3 max-h-[24rem] overflow-y-auto pr-2 bg-white p-2 rounded-lg border border-gray-200">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                            <tr>
                                <th class="py-2 px-3">Produit</th>
                                <th class="py-2 px-3 text-center">Qté</th>
                                <th class="py-2 px-3 text-right">Prix vente</th>
                                <th class="py-2 px-3 text-right">Sous-total</th>
                                <th class="py-2 px-3"></th>
                            </tr>
                        </thead>
                        <tbody id="cart-table" class="divide-y"></tbody>
                    </table>
                </div>

                <div class="sticky bottom-0 bg-white mt-4 p-4 rounded-lg shadow-md border border-gray-200">
                    <div class="text-right mb-4">
                        <h3 class="text-xl font-bold text-gray-700">Total : <span id="total_display">0.00</span> HTG</h3>
                    </div>

                    <form id="saleForm" action="finaliser_vente.php" method="POST" class="mt-2">
                        <div>
                            <label class="block text-sm font-semibold mb-1 text-gray-600">👤 Client</label>
                            <select id="client_id" name="client_id" class="w-full border rounded-lg p-2" required>
                                <option value="">-- Sélectionner un client --</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['client_id'] ?>"><?= htmlspecialchars($c['nom'] . ' ' . $c['prenom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mt-4">
                            <label class="block text-sm font-semibold mb-1 text-gray-600">💳 Méthode de paiement</label>
                            <select id="payment_method" name="payment_method" class="w-full border rounded-lg p-2" required>
                                <option value="Payer cash">Payer cash</option>
                                <option value="Vente à crédit">Vente à crédit</option>
                            </select>
                            <input type="hidden" id="status" name="status" value="Payée">
                        </div>

                        <input type="hidden" name="items" id="items_input" value="">
                        <input type="hidden" name="total" id="total_input" value="">
                        <input type="hidden" name="payment_method" id="payment_input" value="">
                        <input type="hidden" name="action" value="create_sale">

                        <div class="mt-4 flex gap-2">
                            <button type="button" id="finalize" class="bg-green-600 text-white px-4 py-2 rounded">💾 Enregistrer la vente</button>
                            <button type="button" id="clearCartBtn" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">🧹 Vider le panier</button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- Recherche & Liste des ventes (kept) -->
        <section class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">
            <form method="GET" class="mb-6 flex flex-wrap gap-4 items-end bg-white p-4 rounded-lg shadow">
                <div class="flex flex-col flex-1 min-w-[200px]">
                    <label for="search_name" class="text-sm font-medium text-gray-600">Nom / Prénom</label>
                    <input type="text" id="search_name" name="search_name" value="<?= htmlspecialchars($_GET['search_name'] ?? '') ?>"
                        placeholder="Rechercher..." class="border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex flex-col">
                    <label for="search_date" class="text-sm font-medium text-gray-600">Date de vente</label>
                    <input type="date" id="search_date" name="search_date" value="<?= htmlspecialchars($_GET['search_date'] ?? '') ?>"
                        class="border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex flex-col">
                    <label for="search_status" class="text-sm font-medium text-gray-600">Statut</label>
                    <select name="search_status" id="search_status" class="border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">-- Tous --</option>
                        <option value="Payée" <?= (($_GET['search_status'] ?? '') == 'Payée') ? 'selected' : '' ?>>Payée</option>
                        <option value="Crédit" <?= (($_GET['search_status'] ?? '') == 'Crédit') ? 'selected' : '' ?>>Crédit</option>
                    </select>
                </div>

                <div class="flex gap-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-semibold">Rechercher</button>
                    <?php if (!empty($_GET['search_name']) || !empty($_GET['search_date']) || !empty($_GET['search_status'])): ?>
                        <a href="ventes.php" class="bg-gray-600 hover:bg-gray-800 text-white px-4 py-2 rounded font-semibold">Réinitialiser</a>
                    <?php endif; ?>
                </div>
            </form>

            <div id="ajax_list_container" class="overflow-x-auto rounded-lg shadow-lg">
                <table class="min-w-full text-sm border rounded-lg">
                    <thead class="bg-blue-600 text-white uppercase text-xs border-b">
                        <tr>
                            <th class="p-3 text-center"># Recu</th>
                            <th class="p-3 text-left">Nom</th>
                            <th class="p-3 text-left">Prénom</th>
                            <th class="p-3 text-left">Groupe</th>
                            <th class="p-3 text-left">Total</th>
                            <th class="p-3 text-left">Date</th>
                            <th class="p-3 text-left">Statut</th>
                            <th class="p-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ventes as $v): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3 text-right"><?= $v['vente_id'] ?></td>
                                <td class="p-2"><?= htmlspecialchars($v['client_nom'] ?? 'Inconnu') ?></td>
                                <td class="p-2"><?= htmlspecialchars($v['client_prenom'] ?? 'Inconnu') ?></td>
                                <td class="p-2"><?= htmlspecialchars($v['groupe'] ?? 'Inconnu') ?></td>
                                <td class="p-2"><?= number_format($v['total'], 2) ?> HTG</td>
                                <td class="p-2"><?= $v['date_vente'] ?></td>
                                <td class="p-2"><?= $v['status'] ?></td>
                                <td class="p-2 space-x-2 flex justify-center">
                                    <div id="editModal<?= $v['vente_id'] ?>" class="hidden fixed inset-0 flex items-center justify-center bg-black bg-opacity-50">
                                        <div class="bg-white rounded-lg shadow-lg w-96 p-6">
                                            <h2 class="text-lg font-bold mb-4">Modifier la vente #<?= $v['vente_id'] ?></h2>
                                            <form method="post" action="liste_ventes.php">
                                                <input type="hidden" name="action" value="modifier">
                                                <input type="hidden" name="vente_id" value="<?= $v['vente_id'] ?>">
                                                <label class="block mb-2 text-sm text-gray-700">Status :</label>
                                                <select name="status" class="border rounded w-full p-2 mb-3">
                                                    <option <?= $v['status'] == 'Payée' ? 'selected' : '' ?>>Payée</option>
                                                    <option <?= $v['status'] == 'Crédit' ? 'selected' : '' ?>>Crédit</option>
                                                    <option <?= $v['status'] == 'Annulée' ? 'selected' : '' ?>>Annulée</option>
                                                </select>
                                                <label class="block mb-2 text-sm text-gray-700">Total (HTG) :</label>
                                                <input type="number" step="0.01" name="total" value="<?= $v['total'] ?>" class="border rounded w-full p-2 mb-4" readonly>
                                                <div class="flex justify-end space-x-2">
                                                    <button type="button" onclick="document.getElementById('editModal<?= $v['vente_id'] ?>').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Fermer</button>
                                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">Enregistrer</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    <button onclick="document.getElementById('editModal<?= $v['vente_id'] ?>').classList.remove('hidden')" class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600">✏️ Modifier</button>
                                    <a href="recu_vente.php?vente_id=<?= $v['vente_id'] ?>" target="_blank" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm font-medium shadow">🧾 Voir reçu</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- simple pagination display -->
                <div class="p-4 flex justify-center gap-2">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?<?= $queryBase ? $queryBase . '&' : '' ?>page=<?= $i ?>" class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
            </div>
        </section>
        <section class="bg-white shadow-md rounded-2xl p-6 border border-gray-100">
            <div class="max-w-6xl mx-auto bg-white p-6 rounded-xl shadow">
                <h1 class="text-2xl font-bold mb-6">📦 Ventes sur Commande</h1>

                <div id="ajax_list_container" class="overflow-x-auto rounded-lg shadow-lg">
                    <table class="min-w-full text-sm border rounded-lg">
                        <thead class="bg-blue-600 text-white uppercase text-xs border-b">
                            <tr>
                                <th class="p-3 text-left">Vente</th>
                                <th class="p-3 text-left">Nom Client</th>
                                <th class="p-3 text-left">Commande</th>
                                <th class="p-3">Quantité</th>
                                <th class="p-3">Prix</th>
                                <th class="p-3">Prix Total</th>
                                <th class="p-3">Date</th>
                                <th class="p-3">Statut</th>
                                <th class="p-3">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($commandes as $cmd): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="p-3">
                                        <a href="recu_vente.php?vente_id=<?= $cmd['vente_id'] ?>">
                                            Commande #<?= $cmd['vente_id'] ?>
                                        </a>
                                    </td>

                                    <td class="p-3">
                                        <?= htmlspecialchars($cmd['client_nom'] . ' ' . $cmd['client_prenom']) ?>
                                    </td>

                                    <td class="p-3">
                                        <?= htmlspecialchars($cmd['nom']) ?>
                                    </td>

                                    <td class="p-3 text-center"><?= $cmd['quantite'] ?></td>

                                    <td class="p-3"><?= number_format($cmd['prix_vente'], 2) ?> HTG</td>
                                    <td class="p-3 text-right text-green-500"><?= number_format($cmd['total'], 2) ?> HTG</td>

                                    <td class="p-3"><?= date('d/m/Y H:i', strtotime($cmd['date_commande'])) ?></td>

                                    <td class="p-3">
                                        <?php if ($cmd['statut'] === 'En attente'): ?>
                                            <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-xs">En attente</span>
                                        <?php elseif ($cmd['statut'] === 'Livré'): ?>
                                            <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs">Livré</span>
                                        <?php else: ?>
                                            <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-xs">Annulé</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="p-3 space-x-2">

                                        <?php if ($cmd['statut'] === 'En attente'): ?>
                                            <a href="?action=livrer&id=<?= $cmd['id'] ?>"
                                                class="px-3 py-1 bg-green-600 text-white rounded text-xs">
                                                Livrer
                                            </a>

                                            <a href="?action=annuler&id=<?= $cmd['id'] ?>"
                                                class="px-3 py-1 bg-red-600 text-white rounded text-xs">
                                                Annuler
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-500 text-xs">Aucune action</span>
                                        <?php endif; ?>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>

                    </table>
                    <!-- Pagination -->
                    <div class="p-4 flex justify-center gap-2">
                        <?php if ($totalPages > 1): ?>
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>" class="px-3 py-1 border rounded bg-white text-blue-600">« Précédent</a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <a href="?page=<?= $i ?>" class="px-3 py-1 rounded <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>" class="px-3 py-1 border rounded bg-white text-blue-600">Suivant »</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Modal for "Produit sur commande" (fills a product item into cart without existing produit_id) -->
    <div id="cmdModal" class="modal" aria-hidden="true">
        <div class="modal-backdrop" onclick="closeCmdModal()"></div>
        <div class="modal-card p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold">Produit sur commande</h3>
                <button onclick="closeCmdModal()" class="text-gray-600 hover:text-gray-800">&times;</button>
            </div>
            <form id="cmdForm" onsubmit="return addCustomProductFromModal();">
                <div class="space-y-3">
                    <div>
                        <label class="text-sm font-medium">Nom du produit</label>
                        <input id="cmd_nom" type="text" required class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="text-sm font-medium">Quantité</label>
                        <input id="cmd_qte" type="number" min="1" value="1" required class="w-full border rounded p-2">
                    </div>
                    <div>
                        <label class="text-sm font-medium">Prix de vente (HTG)</label>
                        <input id="cmd_prix" type="number" step="0.01" min="0" value="0" required class="w-full border rounded p-2">
                    </div>
                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" onclick="closeCmdModal()" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Annuler</button>
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded">Ajouter au panier</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        /* ---------- Utilities & initial data ---------- */
        const PRODUCTS = <?= $produits_json ?> || [];

        function escapeHtml(unsafe) {
            if (unsafe === undefined || unsafe === null) return '';
            return String(unsafe).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
        }

        function escapeHtmlAttr(s) {
            return escapeHtml(s).replaceAll('"', '&quot;');
        }

        /* ---------- Elements ---------- */
        const produitsList = document.getElementById('produits-list');
        const staticProducts = document.getElementById('static-products');
        const searchInput = document.getElementById('search_input');
        const resetBtn = document.getElementById('resetSearch');
        const scanBtn = document.getElementById('scan_btn');
        const scannerContainer = document.getElementById('scanner_container');

        const cart = [];
        const cartTable = document.getElementById('cart-table');
        const totalDisplay = document.getElementById('total_display');
        const paymentSelect = document.getElementById('payment_method');
        const statusInput = document.getElementById('status');
        const finalizeBtn = document.getElementById('finalize');
        const saleForm = document.getElementById('saleForm');
        const itemsInput = document.getElementById('items_input');
        const totalInput = document.getElementById('total_input');
        const paymentInput = document.getElementById('payment_input');
        const clearCartBtn = document.getElementById('clearCartBtn');

        const openCmdModalBtn = document.getElementById('openCmdModalBtn');
        const cmdModal = document.getElementById('cmdModal');
        const cmdForm = document.getElementById('cmdForm');
        const cmdNom = document.getElementById('cmd_nom');
        const cmdQte = document.getElementById('cmd_qte');
        const cmdPrix = document.getElementById('cmd_prix');

        /* ---------- Render products ---------- */
        function renderProducts(list) {
            produitsList.innerHTML = '';
            if (!list.length) {
                produitsList.innerHTML = '<p class="text-sm text-gray-500">Aucun produit trouvé.</p>';
                return;
            }
            list.forEach(p => {
                const div = document.createElement('div');
                div.className = 'flex justify-between items-center border-b pb-2 hover:bg-gray-50 rounded transition';
                div.innerHTML = `
            <div>
                <p class="font-medium text-gray-800">${escapeHtml(p.nom)}</p>
                <p class="text-sm text-gray-500">Stock: ${p.quantite}</p>
                <p class="text-xs text-gray-400">Code-barres: ${escapeHtml(p.code_barre)}</p>
            </div>
            <button class="add-to-cart bg-blue-500 hover:bg-blue-600 text-white text-sm px-3 py-1 rounded-lg shadow-sm"
                data-id="${p.produit_id}"
                data-nom="${escapeHtmlAttr(p.nom)}"
                data-prix_vente="${p.prix_vente}"
                data-code_barre="${escapeHtmlAttr(p.code_barre)}">➕ Ajouter</button>
        `;
                produitsList.appendChild(div);
            });

            if (staticProducts) staticProducts.classList.add('hidden');

            document.querySelectorAll('.add-to-cart').forEach(btn => {
                if (!btn.dataset.bound) {
                    btn.dataset.bound = '1';
                    btn.addEventListener('click', () => {
                        const id = btn.dataset.id;
                        const nom = btn.dataset.nom;
                        const prix = parseFloat(btn.dataset.prix_vente) || 0;
                        const exist = cart.find(item => item.produit_id == id && !item.custom);
                        if (exist) exist.quantite++;
                        else cart.push({
                            produit_id: id,
                            nom,
                            quantite: 1,
                            prix_vente: prix,
                            custom: false
                        });
                        renderCart();
                    });
                }
            });
        }

        /* initial render */
        renderProducts(PRODUCTS);

        /* ---------- Search functionality ---------- */
        function filterProducts(q) {
            q = (q || '').trim().toLowerCase();
            if (!q) return PRODUCTS.slice();
            return PRODUCTS.filter(p => (p.nom && p.nom.toLowerCase().includes(q)) || (p.code_barre && String(p.code_barre).toLowerCase().includes(q)));
        }
        searchInput.addEventListener('input', e => renderProducts(filterProducts(e.target.value)));
        resetBtn.addEventListener('click', () => {
            searchInput.value = '';
            renderProducts(PRODUCTS);
        });

        /* ---------- Cart rendering / interactions ---------- */
        function renderCart() {
            cartTable.innerHTML = '';
            let total = 0;
            cart.forEach((item, idx) => {
                const subtotal = item.quantite * item.prix_vente;
                total += subtotal;
                cartTable.insertAdjacentHTML('beforeend', `
            <tr>
                <td class="py-1 px-2">${escapeHtml(item.nom)} ${item.custom ? '<span class="text-xs text-gray-400 ml-2">(sur commande)</span>' : ''}</td>
                <td class="py-1 px-2 text-center"><input type="number" min="1" value="${item.quantite}" data-idx="${idx}" class="qty w-16 border rounded text-center" /></td>
                <td class="py-1 px-2 text-right">${item.custom ? `<input type="number" step="0.01" value="${item.prix_vente.toFixed(2)}" data-idx="${idx}" class="price w-24 border rounded text-right"/>` : item.prix_vente.toFixed(2)}</td>
                <td class="py-1 px-2 text-right">${subtotal.toFixed(2)}</td>
                <td class="py-1 px-2 text-center"><button data-idx="${idx}" class="remove text-red-500">×</button></td>
            </tr>
        `);
            });
            totalDisplay.innerText = total.toFixed(2);
            totalInput.value = total.toFixed(2);

            document.querySelectorAll('.qty').forEach(el => {
                el.addEventListener('change', (e) => {
                    const i = parseInt(e.target.dataset.idx);
                    cart[i].quantite = Math.max(1, parseInt(e.target.value) || 1);
                    renderCart();
                });
            });
            document.querySelectorAll('.remove').forEach(btn => {
                btn.addEventListener('click', () => {
                    const i = parseInt(btn.dataset.idx);
                    cart.splice(i, 1);
                    renderCart();
                });
            });
            document.querySelectorAll('.price').forEach(el => {
                el.addEventListener('change', (e) => {
                    const i = parseInt(e.target.dataset.idx);
                    cart[i].prix_vente = parseFloat(e.target.value) || 0;
                    renderCart();
                });
            });
        }

        /* clear cart */
        clearCartBtn.addEventListener('click', () => {
            if (confirm('Vider le panier ?')) {
                cart.length = 0;
                renderCart();
            }
        });

        /* ---------- Finalize sale (classic POST) ---------- */
        paymentSelect.addEventListener('change', () => {
            statusInput.value = paymentSelect.value === 'Vente à crédit' ? 'Crédit' : 'Payée';
        });

        finalizeBtn.addEventListener('click', () => {
            const client_id = document.getElementById('client_id').value;
            if (!client_id) {
                alert('Veuillez sélectionner un client');
                return;
            }
            if (cart.length === 0) {
                alert('Aucun article dans le panier');
                return;
            }

            itemsInput.value = JSON.stringify(cart.map(i => ({
                produit_id: i.produit_id,
                quantite: i.quantite,
                prix_vente: i.prix_vente,
                custom: !!i.custom,
                nom: i.nom
            })));
            totalInput.value = totalDisplay.innerText;
            paymentInput.value = paymentSelect.value;

            saleForm.submit();
        });

        /* ---------- Barcode scanner (Quagga) ---------- */
        scanBtn.addEventListener('click', () => {
            scannerContainer.classList.remove('hidden');
            Quagga.init({
                inputStream: {
                    type: "LiveStream",
                    target: scannerContainer
                },
                decoder: {
                    readers: ["code_128_reader", "ean_reader", "ean_8_reader", "code_39_reader"]
                }
            }, err => {
                if (err) {
                    console.error(err);
                    alert('Erreur démarrage scanner');
                    return;
                }
                Quagga.start();
            });
        });
        Quagga.onDetected(data => {
            const code = data.codeResult.code;
            const prod = PRODUCTS.find(p => String(p.code_barre) === String(code));
            if (prod) {
                const exist = cart.find(item => item.produit_id == prod.produit_id && !item.custom);
                if (exist) exist.quantite++;
                else cart.push({
                    produit_id: prod.produit_id,
                    nom: prod.nom,
                    quantite: 1,
                    prix_vente: parseFloat(prod.prix_vente),
                    custom: false
                });
                renderCart();
            } else {
                alert('Produit non trouvé pour ce code-barres : ' + code);
            }
            Quagga.stop();
            scannerContainer.classList.add('hidden');
        });

        /* ---------- Commande modal (custom product) ---------- */
        openCmdModalBtn.addEventListener('click', () => {
            cmdNom.value = '';
            cmdQte.value = 1;
            cmdPrix.value = '0.00';
            cmdModal.classList.add('active');
            cmdModal.setAttribute('aria-hidden', 'false');
            cmdNom.focus();
        });

        function closeCmdModal() {
            cmdModal.classList.remove('active');
            cmdModal.setAttribute('aria-hidden', 'true');
        }

        function addCustomProductFromModal() {
            const nom = String(cmdNom.value || '').trim();
            const qte = parseInt(cmdQte.value) || 1;
            const prix = parseFloat(cmdPrix.value) || 0;
            if (!nom) {
                alert('Nom requis');
                return false;
            }
            if (qte <= 0) {
                alert('Quantité invalide');
                return false;
            }
            if (prix < 0) {
                alert('Prix invalide');
                return false;
            }

            // push as custom product (produit_id = 0)
            cart.push({
                produit_id: 0,
                nom,
                quantite: qte,
                prix_vente: prix,
                custom: true
            });
            renderCart();
            closeCmdModal();
            return false; // prevent native submit
        }

        /* ---------- AJAX list loader (keeps your ajax_list_vente.php usage) ---------- */
        const listContainer = document.getElementById('ajax_list_container');
        const filtersForm = document.querySelector('form[method="GET"]');

        async function loadVentes(page = 1) {
            const params = new URLSearchParams(new FormData(filtersForm));
            params.set('page', page);
            try {
                const response = await fetch('ajax_list_vente.php?' + params.toString());
                if (!response.ok) throw new Error('Erreur chargement ventes');
                const html = await response.text();
                listContainer.innerHTML = html;
                // attach pagination handlers if any
                document.querySelectorAll('.ajax-page').forEach(btn => {
                    btn.addEventListener('click', () => loadVentes(btn.dataset.page));
                });
            } catch (e) {
                console.error(e);
            }
        }
        filtersForm.addEventListener('submit', e => {
            e.preventDefault();
            loadVentes(1);
        });
        document.addEventListener('DOMContentLoaded', () => loadVentes());

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