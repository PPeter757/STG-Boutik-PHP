<?php
session_start();
require_once 'includes/db.php';
require_once 'includes/role_check.php';
checkRole(['administrateur', 'superviseur', 'vendeur', 'caissier']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=rapport_stock.csv');

$output = fopen('php://output', 'w');
fputcsv($output, ['Code', 'Nom', 'Stock Précédent', 'Ajustement', 'Stock Actuel', 'Prix de Vente', 'Total']);

// Récupérer tous les produits
$produits = $pdo->query("SELECT code_barre, nom, quantite, prix_vente, stock_precedent, ajustement FROM produits ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

foreach ($produits as $p) {
    $stock_actuel = $p['quantite'] + ($p['ajustement'] ?? 0);
    $subtotal = $stock_actuel * $p['prix_vente'];
    fputcsv($output, [
        $p['code_barre'],
        $p['nom'],
        $p['stock_precedent'] ?? 0,
        $p['ajustement'] ?? 0,
        $stock_actuel,
        number_format($p['prix_vente'], 2),
        number_format($subtotal, 2)
    ]);
}

fclose($output);
exit;
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

