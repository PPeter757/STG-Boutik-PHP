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

// ------------------------
// 1️⃣ Traitement POST (ajout / modification produit)
// ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $produit_id = $_POST['produit_id'] ?? null;
    $nom = trim($_POST['nom'] ?? '');
    $categorie = trim($_POST['categorie'] ?? '');
    $prix_achat = $_POST['prix_achat'] ?? 0;
    $prix_vente = $_POST['prix_vente'] ?? 0;
    $quantite = $_POST['quantite'] ?? 0;
    $dimension = trim($_POST['dimension'] ?? '');
    $code_barre = trim($_POST['code_barre'] ?? '');

    if ($nom === '' || $code_barre === '') {
        $_SESSION['message'] = "
        <div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 rounded'>
            ⚠️ Le nom et le code-barres sont obligatoires.
        </div>";
    } else {
        try {
            if ($action === 'edit' && $produit_id) {
                $stmt = $pdo->prepare("UPDATE produits SET nom=?, categorie=?, prix_achat=?, prix_vente=?, quantite=?, dimension=?, code_barre=? WHERE produit_id=?");
                $stmt->execute([$nom, $categorie, $prix_achat, $prix_vente, $quantite, $dimension, $code_barre, $produit_id]);
                $_SESSION['message'] = "
                <div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-3 rounded'>
                    ✅ Produit modifié avec succès.
                </div>";
            } else {
                $stmt = $pdo->prepare("INSERT INTO produits (nom, categorie, prix_achat, prix_vente, quantite, dimension, code_barre)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$nom, $categorie, $prix_achat, $prix_vente, $quantite, $dimension, $code_barre]);
                $_SESSION['message'] = "
                <div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-3 rounded'>
                    ✅ Produit ajouté avec succès.
                </div>";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $_SESSION['message'] = "
                <div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-3 rounded'>
                    ❌ Erreur : code-barres déjà existant.
                </div>";
            } else {
                $_SESSION['message'] = "
                <div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-3 rounded'>
                    ❌ Erreur SQL : " . htmlspecialchars($e->getMessage()) . "
                </div>";
            }
        }
    }

    // ✅ Redirection après POST pour éviter double soumission
    header("Location: produits.php");
    exit;
}
// ---------- Suppression (GET) ----------
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $del = intval($_GET['delete']);
    try {
        $stmt = $pdo->prepare("DELETE FROM produits WHERE produit_id = ?");
        $stmt->execute([$del]);

        // ✅ Message succès (stocké dans la session)
        $_SESSION['message'] = "
        <div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-3 rounded mt-4'>
            ✅ Produit supprimé avec succès.
        </div>";

        // ✅ Redirection après suppression
        header("Location: produits.php");
        exit;
    } catch (PDOException $e) {
        // ✅ Message d'erreur (produit déjà vendu, etc.)
        $_SESSION['message'] = "
        <div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-3 rounded mt-4'>
            ❌ Ce produit est deja en vente, impossible de supprimer.
        </div>";

        header("Location: produits.php");
        exit;
    }
}

// ---------- AJAX lookup pour le scanner (retourne JSON si GET lookup_code=...) ----------
if (isset($_GET['lookup_code'])) {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim((string)($_GET['lookup_code'] ?? ''));
    if ($code === '') {
        echo json_encode(['status' => 'error', 'msg' => 'code vide']);
        exit;
    }
    $q = $pdo->prepare("SELECT * FROM produits WHERE code_barre = ? LIMIT 1");
    $q->execute([$code]);
    $prod = $q->fetch(PDO::FETCH_ASSOC);
    if ($prod) echo json_encode(['status' => 'ok', 'produit' => $prod]);
    else echo json_encode(['status' => 'not_found']);
    exit;
}

// ---------- Variables et messages ----------
$produit_id = '';
$nom = $categorie = $prix_achat = $prix_vente = $quantite = $dimension = $code_barre = '';
$message = '';

// ------------------------------------------
// Gestion des messages (initialisation)
// ------------------------------------------
// ---------- Pré-remplissage édition (GET edit=ID) ----------
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM produits WHERE produit_id=? LIMIT 1");
    $stmt->execute([$eid]);
    if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $produit_id = $p['produit_id'];
        $nom = $p['nom'];
        $categorie = $p['categorie'];
        $prix_achat = $p['prix_achat'];
        $prix_vente = $p['prix_vente'];
        $quantite = $p['quantite'];
        $dimension = $p['dimension'];
        $code_barre = $p['code_barre'];
    } else {
        $message = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 rounded'>Produit introuvable.</div>";
    }
}

// ---------- Recherche & pagination ----------
$search = trim($_GET['search'] ?? '');
$search_id = is_numeric($search) ? intval($search) : '';
$perPage = 8;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Compter le nombre total de ventes pour la pagination
$count_sql = "SELECT COUNT(*) FROM produits p LEFT JOIN produits c ON p.produit_id = c.produit_id WHERE 1=1";
if (!empty($search_id)) $count_sql .= " AND p.produit_id = :produit_id";
if ($search !== '') {
    $count_sql .= " AND (p.nom LIKE :search OR p.categorie LIKE :search OR p.code_barre LIKE :search)";
}
$count_stmt = $pdo->prepare($count_sql);
if (!empty($search_id)) $count_stmt->bindValue(':produit_id', $search_id, PDO::PARAM_INT);
if ($search !== '') {
    $like_search = '%' . $search . '%';
    $count_stmt->bindValue(':search', $like_search, PDO::PARAM_STR);
}
$count_stmt->execute();
$totalProduits = $count_stmt->fetchColumn();
$totalPages = ceil($totalProduits / $perPage);
// Récupérer les produits avec recherche et pagination
$sql = "SELECT p.* FROM produits p LEFT JOIN produits c ON p.produit_id = c.produit_id WHERE 1=1";
if (!empty($search_id)) $sql .= " AND p.produit_id = :produit_id";
if ($search !== '') {
    $sql .= " AND (p.nom LIKE :search OR p.categorie LIKE :search OR p.code_barre LIKE :search)";
}
$sql .= " ORDER BY p.nom ASC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
if (!empty($search_id)) $stmt->bindValue(':produit_id', $search_id, PDO::PARAM_INT);
if ($search !== '') {
    $like_search = '%' . $search . '%';
    $stmt->bindValue(':search', $like_search, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Produits — Gestion Boutique</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- QuaggaJS fallback (utilisé uniquement si BarcodeDetector absent) -->
    <script src="https://unpkg.com/@ericblade/quagga2@2.0.0-beta.3/dist/quagga.min.js"></script>
    <style>
        table {
            table-layout: fixed;
        }

        th,
        td {
            word-wrap: break-word;
        }

        .col-nom {
            width: 12%;
        }

        .col-cat {
            width: 14%;
        }

        .col-prix_achat {
            width: 12%;
        }

        .col-prix_vente {
            width: 12%;
        }

        .col-qt {
            width: 8%;
        }

        .col-dim {
            width: 14%;
        }

        .col-code {
            width: 12%;
        }

        .col-actions {
            width: 12%;
        }

        /* scanner insert sous le champ */
        #scanner-inline {
            display: none;
            margin-top: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 8px;
            background: #fff;
        }

        #scanner-video {
            width: 100%;
            height: auto;
            max-height: 360px;
            background: #000;
        }

        .flash-ok {
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.15);
            animation: flash 500ms ease-out;
        }

        @keyframes flash {
            from {
                box-shadow: 0 0 0 12px rgba(16, 185, 129, 0.25);
            }

            to {
                box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.12);
            }
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen">

    <!-- menu latéral -->
    <?php include 'includes/menu_lateral.php'; ?>

    <main class="ml-64 p-8">
        <div class="max-w-6xl mx-auto">
            <h1 class="text-3xl font-bold text-blue-700 mb-6">📦 Gestion des produits</h1>

            <?= $message ?>

            <!-- Formulaire : code-barres en haut; bouton scanner; scanner inline s'affiche sous le champ -->
            <!-- ✅ Bouton pour ouvrir le modal -->
            <div class="flex justify-end mb-4">
                <button onclick="openModal('modalProduit')"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg shadow">
                    ➕ Ajouter un produit
                </button>
            </div>

            <!-- ✅ Modal Ajouter / Modifier Produit -->
            <div id="modalProduit" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50">
                <div class="bg-white rounded-lg shadow-lg w-full max-w-4xl p-6 relative">

                    <!-- Header -->
                    <div class="flex justify-between items-center border-b pb-3 mb-4">
                        <h2 class="text-xl font-semibold text-gray-800">Ajouter un nouveau produit</h2>
                        <button onclick="closeModal('modalProduit')" class="text-gray-500 hover:text-red-600 text-xl font-bold">&times;</button>
                    </div>

                    <!-- ✅ Formulaire -->
                    <form id="formProduit" method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">

                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="produit_id" id="produit_id">

                        <!-- Code-barres + scanner -->
                        <div class="md:col-span-2">
                            <label class="text-sm text-gray-600">Code-barres</label>
                            <div class="flex gap-2">
                                <input id="code_barre" name="code_barre" required
                                    class="border rounded p-2 w-full" placeholder="Scannez ou saisissez le code">
                                <button type="button" id="btn-open-scanner" class="bg-green-500 text-white px-3 py-2 rounded">📷</button>
                                <button type="button" id="btn-clear" class="bg-gray-200 text-gray-800 px-3 py-2 rounded">✖</button>
                            </div>

                            <div id="scanner-inline" class="mt-3 hidden">
                                <div class="flex justify-between items-center mb-2">
                                    <div class="text-sm text-gray-600">Scanner (caméra active)</div>
                                    <button type="button" id="btn-close-scanner" class="text-sm bg-red-500 text-white px-2 py-1 rounded">Fermer</button>
                                </div>
                                <div id="scanner-video" class="border rounded"></div>
                            </div>
                        </div>

                        <!-- Autres champs -->
                        <div>
                            <label class="text-sm text-gray-600">Nom</label>
                            <input name="nom" id="nom" required class="border rounded p-2 w-full" placeholder="Nom du produit">
                        </div>

                        <div>
                            <label class="text-sm text-gray-600">Catégorie</label>
                            <input name="categorie" id="categorie" required class="border rounded p-2 w-full" placeholder="Catégorie">
                        </div>

                        <div>
                            <label class="text-sm text-gray-600">Prix d'achat</label>
                            <input name="prix_achat" id="prix_achat" type="number" step="0.01" class="border rounded p-2 w-full" placeholder="0.00">
                        </div>

                        <div>
                            <label class="text-sm text-gray-600">Prix de vente</label>
                            <input name="prix_vente" id="prix_vente" type="number" step="0.01" class="border rounded p-2 w-full" placeholder="0.00">
                        </div>

                        <div>
                            <label class="text-sm text-gray-600">Quantité</label>
                            <input name="quantite" id="quantite" type="number" class="border rounded p-2 w-full" placeholder="0">
                        </div>

                        <div>
                            <label class="text-sm text-gray-600">Dimension / Présentation</label>
                            <input name="dimension" id="dimension" required class="border rounded p-2 w-full" placeholder="Ex: 500g, boîte...">
                        </div>

                        <div class="md:col-span-6 flex justify-end mt-4">
                            <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700">
                                💾 Enregistrer le produit
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ✅ Script Scanner et Modal -->
            <script src="https://unpkg.com/html5-qrcode"></script>
            <script>
                let html5QrCode;
                const scannerDiv = document.getElementById("scanner-inline");

                document.getElementById("btn-open-scanner").addEventListener("click", () => {
                    scannerDiv.classList.remove("hidden");
                    html5QrCode = new Html5Qrcode("scanner-video");
                    html5QrCode.start({
                            facingMode: "environment"
                        }, {
                            fps: 10,
                            qrbox: 200
                        },
                        qrCodeMessage => {
                            document.getElementById("code_barre").value = qrCodeMessage;
                            html5QrCode.stop();
                            scannerDiv.classList.add("hidden");
                        }
                    );
                });

                document.getElementById("btn-close-scanner").addEventListener("click", () => {
                    if (html5QrCode) html5QrCode.stop();
                    scannerDiv.classList.add("hidden");
                });

                document.getElementById("btn-clear").addEventListener("click", () => {
                    document.getElementById("code_barre").value = "";
                });

                // ✅ Fonctions d’ouverture / fermeture du modal
                function openModal(id) {
                    document.getElementById(id).classList.remove("hidden");
                }

                function closeModal(id) {
                    document.getElementById(id).classList.add("hidden");
                    if (html5QrCode) html5QrCode.stop();
                }
                // Pré-remplir le formulaire si édition
                <?php if ($produit_id): ?>
                    openModal('modalProduit');
                    document.getElementById('formProduit').action.value = 'edit';
                    document.getElementById('formProduit').produit_id.value = '<?= htmlspecialchars($produit_id) ?>';
                    document.getElementById('formProduit').nom.value = '<?= htmlspecialchars($nom) ?>';
                    document.getElementById('formProduit').categorie.value = '<?= htmlspecialchars($categorie) ?>';
                    document.getElementById('formProduit').prix_achat.value = '<?= htmlspecialchars($prix_achat) ?>';
                    document.getElementById('formProduit').prix_vente.value = '<?= htmlspecialchars($prix_vente) ?>';
                    document.getElementById('formProduit').quantite.value = '<?= htmlspecialchars($quantite) ?>';
                    document.getElementById('formProduit').dimension.value = '<?= htmlspecialchars($dimension) ?>';
                    document.getElementById('formProduit').code_barre.value = '<?= htmlspecialchars($code_barre) ?>';
                <?php endif; ?>
            </script>
            <!-- Recherche -->
            <form method="GET" class="mb-4 flex gap-2 items-center">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="🔍 Rechercher par nom"
                    class="border p-2 rounded flex-1">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Rechercher</button>
                <?php if ($search !== ''): ?>
                    <a href="produits.php" class="bg-purple-500 text-white px-4 py-2 rounded">Réinitialiser</a>
                <?php endif; ?>
            </form>
            <?php
            if (isset($_SESSION['message'])) {
                echo "<div id='messageBox'>{$_SESSION['message']}</div>";
                unset($_SESSION['message']); // on efface après affichage
            }
            ?>
            <!-- Tableau des produits -->
            <div class="overflow-x-auto rounded-lg">
                <table class="min-w-full text-sm border rounded-lg">
                    <thead class="bg-blue-600 text-white uppercase text-xs border-b">
                        <tr>
                            <th class="p-2 text-left col-code">Code Produit</th>
                            <th class="p-3 text-left col-nom">Nom</th>
                            <th class="p-3 text-left col-cat">Catégorie</th>
                            <th class="p-3 text-right col-prix">Prix Achat</th>
                            <th class="p-3 text-right col-prix">Prix Vente</th>
                            <th class="p-3 text-left col-qt">Quantité</th>
                            <th class="p-3 text-right col-dim">Dimension</th>
                            <th class="p-3 text-center col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="produits-tbody" class="bg-white divide-y">
                        <!-- AJAX : contenu inséré ici -->
                        <?php foreach ($produits as $row): ?>
                            <tr>
                                <td class="p-3 text-left"><?= htmlspecialchars($row['code_barre']) ?></td>
                                <td class="p-3 text-left max-w-[180px] truncate" title="<?= htmlspecialchars($row['nom']) ?>"><?= htmlspecialchars($row['nom']) ?></td>
                                <td class="p-3 text-left"><?= htmlspecialchars($row['categorie']) ?></td>
                                <td class="p-3 text-right"><?= number_format($row['prix_achat'], 2) ?> HTG</td>
                                <td class="p-3 text-right"><?= number_format($row['prix_vente'], 2) ?> HTG</td>
                                <td class="p-3 text-right"><?= (int)$row['quantite'] ?></td>
                                <td class="p-3 text-right"><?= htmlspecialchars($row['dimension']) ?></td>
                                <td class="p-3 flex gap-2 justify-center">
                                    <button onclick="openModal('editModal<?= $row['produit_id'] ?>')" class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600">Modifier</button>
                                    <a href="?delete=<?= $row['produit_id'] ?>" onclick="return confirm('Supprimer ce produit ?')" class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600">Supprimer</a>
                                </td>
                            </tr>


                            <!-- Modale pour modification -->
                            <div id="editModal<?= $row['produit_id'] ?>" class="hidden fixed inset-0 flex items-center justify-center bg-black bg-opacity-50 z-50">
                                <div class="bg-white rounded-lg shadow-lg w-96 p-6 relative">
                                    <h2 class="text-lg font-bold mb-4">Modifier le produit #<?= $row['produit_id'] ?></h2>
                                    <form method="POST" action="">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="produit_id" value="<?= $row['produit_id'] ?>">

                                        <label class="block text-sm text-gray-700 mb-1">Code-barres</label>
                                        <input type="text" name="code_barre" value="<?= htmlspecialchars($row['code_barre']) ?>" class="border rounded w-full p-2 mb-3">

                                        <label class="block text-sm text-gray-700 mb-1">Nom</label>
                                        <input type="text" name="nom" value="<?= htmlspecialchars($row['nom']) ?>" class="border rounded w-full p-2 mb-3">

                                        <label class="block text-sm text-gray-700 mb-1">Catégorie</label>
                                        <input type="text" name="categorie" value="<?= htmlspecialchars($row['categorie']) ?>" class="border rounded w-full p-2 mb-3">

                                        <label class="block text-sm text-gray-700 mb-1">Prix d'achat</label>
                                        <input type="number" step="0.01" name="prix_achat" value="<?= htmlspecialchars($row['prix_achat']) ?>" class="border rounded w-full p-2 mb-3">

                                        <label class="block text-sm text-gray-700 mb-1">Prix de vente</label>
                                        <input type="number" step="0.01" name="prix_vente" value="<?= htmlspecialchars($row['prix_vente']) ?>" class="border rounded w-full p-2 mb-3">

                                        <label class="block text-sm text-gray-700 mb-1">Quantité</label>
                                        <input type="number" name="quantite" value="<?= (int)$row['quantite'] ?>" class="border rounded w-full p-2 mb-3">

                                        <label class="block text-sm text-gray-700 mb-1">Dimension</label>
                                        <input type="text" name="dimension" value="<?= htmlspecialchars($row['dimension']) ?>" class="border rounded w-full p-2 mb-4">

                                        <div class="flex justify-end gap-2">
                                            <button type="button" onclick="closeModal('editModal<?= $row['produit_id'] ?>')" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Fermer</button>
                                            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">💾 Enregistrer</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>
            function openModal(id) {
                document.getElementById(id).classList.remove('hidden');
            }

            function closeModal(id) {
                document.getElementById(id).classList.add('hidden');
            }
        </script>
        <!-- Pagination -->
        <div class="mt-4 flex justify-center space-x-2">
            <?php
            // Bouton Précédent
            if ($page > 1) {
                echo '<a href="produits.php?page=' . ($page - 1) . '&search=' . urlencode($search) . '" 
        class="px-3 py-1 rounded bg-white text-blue-600 border hover:bg-blue-100">« Précédent</a>';
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

            // Première page si nécessaire
            if ($start > 1) {
                echo '<a href="produits.php?page=1&search=' . urlencode($search) . '" 
        class="px-3 py-1 rounded bg-white text-blue-600 border hover:bg-blue-100">1</a>';
                if ($start > 2) echo '<span class="px-3 py-1 hidden sm:inline">...</span>';
            }

            // Boutons centraux
            for ($i = $start; $i <= $end; $i++) {
                $activeClass = $i === $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border hover:bg-blue-100';
                echo '<a href="produits.php?page=' . $i . '&search=' . urlencode($search) . '" 
        class="px-3 py-1 rounded ' . $activeClass . '">' . $i . '</a>';
            }

            // Dernière page si nécessaire
            if ($end < $totalPages) {
                if ($end < $totalPages - 1) echo '<span class="px-3 py-1 hidden sm:inline">...</span>';
                echo '<a href="produits.php?page=' . $totalPages . '&search=' . urlencode($search) . '" 
        class="px-3 py-1 rounded bg-white text-blue-600 border hover:bg-blue-100">' . $totalPages . '</a>';
            }

            // Bouton Suivant
            if ($page < $totalPages) {
                echo '<a href="produits.php?page=' . ($page + 1) . '&search=' . urlencode($search) . '" 
        class="px-3 py-1 rounded bg-white text-blue-600 border hover:bg-blue-100">Suivant »</a>';
            }
            ?>
        </div>


    </main>

    <script>
        /* Helper: beep */
        function beep() {
            try {
                const ctx = new(window.AudioContext || window.webkitAudioContext)();
                const o = ctx.createOscillator();
                const g = ctx.createGain();
                o.type = 'sine';
                o.frequency.value = 800;
                g.gain.value = 0.05;
                o.connect(g);
                g.connect(ctx.destination);
                o.start();
                setTimeout(() => {
                    o.stop();
                    ctx.close();
                }, 120);
            } catch (e) {
                /* ignore */
            }
        }

        /* Logique intégrée du scanner */
        const btnOpen = document.getElementById('btn-open-scanner');
        const btnClose = document.getElementById('btn-close-scanner');
        const scannerInline = document.getElementById('scanner-inline');
        const videoTarget = document.getElementById('scanner-video');
        const codeInput = document.getElementById('code_barre');
        let barcodeDetector = null;
        let quaggaRunning = false;
        let streamRef = null;
        let scanning = false;

        // open scanner under input
        btnOpen.addEventListener('click', async () => {
            if (scanning) return;
            scannerInline.style.display = 'block';
            codeInput.focus();

            if ('BarcodeDetector' in window) {
                try {
                    const formats = await BarcodeDetector.getSupportedFormats();
                    barcodeDetector = new BarcodeDetector({
                        formats: ['ean_13', 'ean_8', 'code_128', 'qr_code', 'upc_e', 'upc_a', 'code_39']
                    });
                    startBarcodeDetector();
                    return;
                } catch (e) {
                    console.warn('BarcodeDetector init failed:', e);
                }
            }
            startQuagga();
        });

        // close scanner
        btnClose.addEventListener('click', stopScanner);
        document.getElementById('btn-clear').addEventListener('click', () => {
            codeInput.value = '';
            document.querySelector('input[name="nom"]').value = '';
            document.querySelector('input[name="categorie"]').value = '';
            document.querySelector('input[name="prix_vente"]').value = '';
            document.querySelector('input[name="prix_achat"]').value = '';
            document.querySelector('input[name="quantite"]').value = '';
            document.querySelector('input[name="dimension"]').value = '';
            if (window.location.search.includes('edit=')) {
                window.location.href = 'produits.php';
            }
        });

        // ---------- BarcodeDetector path ----------
        async function startBarcodeDetector() {
            if (scanning) return;
            scanning = true;
            try {
                const stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: 'environment'
                    }
                });
                streamRef = stream;
                videoTarget.innerHTML = '';
                const video = document.createElement('video');
                video.setAttribute('autoplay', '');
                video.setAttribute('playsinline', '');
                video.srcObject = stream;
                videoTarget.appendChild(video);
                await video.play();

                const detectLoop = async () => {
                    if (!scanning) return;
                    try {
                        const barcodes = await barcodeDetector.detect(video);
                        if (barcodes && barcodes.length) {
                            const code = barcodes[0].rawValue;
                            onCodeScanned(code);
                            return;
                        }
                    } catch (e) {
                        console.warn('detect error', e);
                    }
                    requestAnimationFrame(detectLoop);
                };
                requestAnimationFrame(detectLoop);
            } catch (err) {
                console.error('Camera open failed', err);
                startQuagga();
            }
        }

        // ---------- Quagga fallback ----------
        function startQuagga() {
            if (quaggaRunning) return;
            scanning = true;
            quaggaRunning = true;
            videoTarget.innerHTML = '';

            Quagga.init({
                inputStream: {
                    name: "Live",
                    type: "LiveStream",
                    target: videoTarget,
                    constraints: {
                        facingMode: "environment"
                    }
                },
                decoder: {
                    readers: ["ean_reader", "ean_8_reader", "code_128_reader", "upc_reader", "upc_e_reader", "code_39_reader"]
                },
                locate: true,
                numOfWorkers: navigator.hardwareConcurrency ?? 2
            }, function(err) {
                if (err) {
                    console.error(err);
                    alert('Impossible d\'initialiser le scanner sur ce périphérique.');
                    stopScanner();
                    return;
                }
                Quagga.start();
            });

            Quagga.onDetected(function(result) {
                if (!result?.codeResult?.code) return;
                onCodeScanned(result.codeResult.code);
            });
        }

        // ---------- Stop scanner ----------
        function stopScanner() {
            scanning = false;
            if (barcodeDetector) barcodeDetector = null;
            if (streamRef) {
                streamRef.getTracks().forEach(t => t.stop());
                streamRef = null;
            }
            if (quaggaRunning && window.Quagga) {
                try {
                    Quagga.stop();
                } catch (e) {}
                Quagga.offDetected && Quagga.offDetected();
                quaggaRunning = false;
            }
            videoTarget.innerHTML = '';
            scannerInline.style.display = 'none';
        }

        // ---------- On code scanned ----------
        let lastScan = {
            code: null,
            time: 0
        };

        function onCodeScanned(code) {
            const now = Date.now();
            if (lastScan.code === code && (now - lastScan.time < 1500)) return;

            lastScan = {
                code,
                time: now
            };
            document.getElementById('code_barre').value = code;
            beep();
        }

        // ---------- SECTION AJAX PRODUITS ----------

        const tbody = document.getElementById('produits-tbody');
        const paginationDiv = document.getElementById('produits-pagination');
        const searchInput = document.querySelector('form input[name="search"]');

        function attachEditButtons() {
            document.querySelectorAll('button[data-edit]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = btn.dataset.edit;
                    openModal(`editModal${id}`);
                });
            });
        }

        function loadProduits(page = 1, search = '') {
            fetch(`ajax_produits.php?page=${page}&search=${encodeURIComponent(search)}`)
                .then(res => res.json())
                .then(data => {
                    tbody.innerHTML = data.tbody;
                    paginationDiv.innerHTML = data.pagination;
                    attachEditButtons();

                    paginationDiv.querySelectorAll('a').forEach(a => {
                        a.addEventListener('click', e => {
                            e.preventDefault();
                            loadProduits(a.dataset.page, search);
                        });
                    });
                });
        }

        loadProduits(1, searchInput.value);

        const searchForm = document.querySelector('form[method="GET"]');
        if (searchForm) {
            searchForm.addEventListener('submit', e => {
                e.preventDefault();
                loadProduits(1, searchInput.value.trim());
            });
        }


        // Masquer automatiquement le message après 3 secondes
        const msg = document.getElementById('msg-suppr');
        if (msg) {
            setTimeout(() => {
                msg.style.transition = 'opacity 0.5s';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 500); // supprime le div après la transition
            }, 3000);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const msg = document.getElementById('msg-suppr');
            if (msg) {
                // ✅ Correction 4 : disparition automatique du message après 4 secondes
                setTimeout(() => {
                    const msg = document.getElementById('msg-suppr');
                    if (msg) msg.style.opacity = 0;
                    setTimeout(() => msg && (msg.style.display = 'none'), 500);
                }, 4000); // disparaît après 4 secondes
            }
        });
    </script>`
    <script>
        setTimeout(() => {
            const msg = document.getElementById('messageBox');
            if (!msg) return;
            msg.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            msg.style.opacity = '0';
            msg.style.transform = 'translateY(-10px)';
            setTimeout(() => msg.remove(), 600);
        }, 4000); // disparaît après 4 secondes
    </script>
    <script>
        document.querySelectorAll("form").forEach(form => {
            form.addEventListener("submit", e => {
                console.log("➡️ Envoi du formulaire détecté :", form);
            });
        });

        // Durée d'inactivité en millisecondes
        const timeout = <?php echo $timeout * 1000; ?>;

        let timer;

        // Réinitialiser le timer à chaque interaction
        function resetTimer() {
            clearTimeout(timer);
            timer = setTimeout(() => {
                // Redirige vers logout.php ou recharge la page
                window.location.href = 'logout.php?timeout=1';
            }, timeout);
        }

        // Événements pour détecter activité
        window.onload = resetTimer;
        document.onmousemove = resetTimer;
        document.onkeypress = resetTimer;
        document.onclick = resetTimer;
        document.onscroll = resetTimer;
    </script>
</body>

</html>