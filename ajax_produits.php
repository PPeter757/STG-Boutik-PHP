<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Durée d'inactivité avant fermeture automatique (en secondes)
$timeout = 600; // 5 minutes — ajustable

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
checkRole($pdo, ['administrateur', 'manager']); // adapter selon la page

// --------------------- POST add/edit ---------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $produit_id = intval($_POST['produit_id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $categorie = trim($_POST['categorie'] ?? '');
    $prix_achat = floatval($_POST['prix_achat'] ?? 0);
    $prix_vente = floatval($_POST['prix_vente'] ?? 0);
    $quantite = intval($_POST['quantite'] ?? 0);
    $dimension = trim($_POST['dimension'] ?? '');
    $code_barre = trim($_POST['code_barre'] ?? '');

    if ($nom === '' || $code_barre === '') {
        echo json_encode(['success' => false, 'message' => 'Nom et code-barres obligatoires']);
        exit;
    }

    try {
        if ($action === 'edit' && $produit_id) {
            $stmt = $pdo->prepare("UPDATE produits SET nom=?, categorie=?, prix_achat=?, prix_vente=?, quantite=?, dimension=?, code_barre=? WHERE produit_id=?");
            $stmt->execute([$nom, $categorie, $prix_achat, $prix_vente, $quantite, $dimension, $code_barre, $produit_id]);
            echo json_encode(['success' => true, 'message' => 'Produit modifié avec succès']);
        } else {
            $stmt = $pdo->prepare("INSERT INTO produits (nom,categorie,prix_achat,prix_vente,quantite,dimension,code_barre) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$nom, $categorie, $prix_achat, $prix_vente, $quantite, $dimension, $code_barre]);
            echo json_encode(['success' => true, 'message' => 'Produit ajouté avec succès']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $produit_id = intval($_POST['produit_id'] ?? 0);

    if ($action === 'delete' && $produit_id) {
        try {
            $stmt = $pdo->prepare("DELETE FROM produits WHERE produit_id = ?");
            $stmt->execute([$produit_id]);
            echo json_encode(['success' => true, 'message' => 'Produit supprimé avec succès']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
        }
        exit;
    }
}

// --------------------- GET liste produits ---------------------
$search = trim($_GET['search'] ?? '');
$search_id = is_numeric($search) ? intval($search) : '';
$perPage = 20;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Count total
$count_sql = "SELECT COUNT(*) FROM produits WHERE 1=1";
if ($search_id) $count_sql .= " AND produit_id = :produit_id";
if ($search !== '') $count_sql .= " AND (nom LIKE :search OR categorie LIKE :search OR code_barre LIKE :search)";
$count_stmt = $pdo->prepare($count_sql);
if ($search_id) $count_stmt->bindValue(':produit_id', $search_id, PDO::PARAM_INT);
if ($search !== '') $count_stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
$count_stmt->execute();
$total = $count_stmt->fetchColumn();
$totalPages = ceil($total / $perPage);

// Récupérer produits
$sql = "SELECT * FROM produits WHERE 1=1";
if ($search_id) $sql .= " AND produit_id = :produit_id";
if ($search !== '') $sql .= " AND (nom LIKE :search OR categorie LIKE :search OR code_barre LIKE :search)";
$sql .= " ORDER BY nom ASC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
if ($search_id) $stmt->bindValue(':produit_id', $search_id, PDO::PARAM_INT);
if ($search !== '') $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Construire le tableau HTML identique à ton design
$tbody = '';
foreach ($produits as $row) {
    $tbody .= '<tr class="hover:bg-gray-50 border-b border-gray-100">';
    $tbody .= '<td class="p-3 text-left col-code">' . htmlspecialchars($row['code_barre']) . '</td>';
    $tbody .= '<td class="p-3 text-left col-nom max-w-[180px] truncate" title="' . htmlspecialchars($row['nom']) . '">' . htmlspecialchars($row['nom']) . '</td>';
    $tbody .= '<td class="p-3 text-left col-cat">' . htmlspecialchars($row['categorie']) . '</td>';
    $tbody .= '<td class="p-3 text-right col-prix_achat">' . number_format($row['prix_achat'], 2) . ' HTG</td>';
    $tbody .= '<td class="p-3 text-right col-prix_vente">' . number_format($row['prix_vente'], 2) . ' HTG</td>';
    $tbody .= '<td class="p-3 text-right col-qt">' . (int)$row['quantite'] . '</td>';
    $tbody .= '<td class="p-3 text-right col-dim">' . htmlspecialchars($row['dimension']) . '</td>';
    $tbody .= '<td class="p-3 flex gap-2 justify-right col-actions">';
    $tbody .= '<button data-edit="' . $row['produit_id'] . '" class="bg-green-500 text-white px-3 py-1 rounded hover:bg-green-600 transition">Modifier</button>';
    $tbody .= '<button data-delete="' . $row['produit_id'] . '" class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600 transition">Supprimer</button>';
    $tbody .= '</td></tr>';
}

// Pagination HTML identique à ton design
$pagination = '';
if ($totalPages > 1) {
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i == $page ? 'bg-blue-600 text-white' : 'bg-white text-blue-600 border';
        $pagination .= '<a href="#" data-page="' . $i . '" class="px-3 py-1 rounded ' . $active . ' border-blue-600 hover:bg-blue-100 transition">' . $i . '</a>';
    }
}

// Retour JSON
echo json_encode([
    'tbody' => $tbody,
    'pagination' => $pagination
]);
?>
<script>
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

