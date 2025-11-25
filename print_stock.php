<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// Timeout automatique (10 min)
$timeout = 600;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: logout.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/role_check.php';
checkRole(['administrateur','superviseur','vendeur','caissier']);

// Récupération de tous les produits
$produits = $pdo->query("SELECT code_barre, nom, quantite, prix_vente, stock_precedent, ajustement FROM produits ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Rapport Stock - Impression</title>
<script src="https://cdn.tailwindcss.com"></script>
<style>
@media print {
    body { background: #fff; margin:0; }
    #menuLateral, .no-print { display:none !important; }
    #printable { width:100%; margin:0 auto; box-shadow:none; position:relative; }
    table { page-break-inside:auto; border-collapse:collapse; }
    tr { page-break-inside:avoid; page-break-after:auto; }
    th, td { border:1px solid #000; padding:4px; text-align:left; }
}
table { width:100%; border-collapse:collapse; margin-top:10px; }
th, td { border:1px solid #000; padding:6px; text-align:right; }
th { background:#ccc; text-align:left; }
td.left { text-align:left; }
</style>
</head>
<body class="bg-gray-100 font-sans">

<div class="flex">
    <!-- Menu latéral -->
    <?php if(file_exists(__DIR__.'/includes/menu_lateral.php')): ?>
        <div id="menuLateral" class="no-print">
            <?php include __DIR__.'/includes/menu_lateral.php'; ?>
        </div>
    <?php endif; ?>

    <!-- Section imprimable -->
    <main class="flex-1 p-8 ml-64" id="printable">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-2xl font-bold">📦 Rapport complet du stock</h1>
            <button onclick="window.print()" class="no-print bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-700">Imprimer</button>
        </div>

        <div class="bg-white p-6 rounded shadow mb-6">
            <table class="min-w-full text-sm border border-gray-300">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 text-left">Code</th>
                        <th class="p-2 text-left">Nom</th>
                        <th class="p-2 text-right">Stock Précédent</th>
                        <th class="p-2 text-right">Ajustement</th>
                        <th class="p-2 text-right">Stock Actuel</th>
                        <th class="p-2 text-right">Prix de Vente</th>
                        <th class="p-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cout_total = 0;
                    foreach ($produits as $p):
                        $stock_actuel = $p['quantite'] + ($p['ajustement'] ?? 0);
                        $subtotal = $stock_actuel * $p['prix_vente'];
                        $cout_total += $subtotal;
                    ?>
                    <tr>
                        <td class="left"><?= h($p['code_barre']) ?></td>
                        <td class="left"><?= h($p['nom']) ?></td>
                        <td><?= $p['stock_precedent'] ?? 0 ?></td>
                        <td><?= $p['ajustement'] ?? 0 ?></td>
                        <td><?= $stock_actuel ?></td>
                        <td><?= number_format($p['prix_vente'],2) ?> HTG</td>
                        <td><?= number_format($subtotal,2) ?> HTG</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="6"><strong>Coût total du stock</strong></td>
                        <td><strong><?= number_format($cout_total,2) ?> HTG</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <footer class="text-xs text-gray-500 mt-6">
            Rapport généré le <?= date('d/m/Y H:i') ?>
        </footer>
    </main>
</div>
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
</body>
</html>
