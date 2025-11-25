<?php
require_once 'includes/db.php';

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

// 🔍 Recherche par nom ou code-barres, avec similarité
$stmt = $pdo->prepare("
    SELECT produit_id, nom, prix_vente, quantite, code_barre
    FROM produits
    WHERE nom LIKE :q
       OR code_barre LIKE :q
       OR levenshtein(nom, :q2) <= 2
    ORDER BY nom ASC
    LIMIT 15
");
$stmt->execute([
    ':q' => "%$q%",
    ':q2' => $q
]);

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
