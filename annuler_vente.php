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
checkRole(['administrateur', 'superviseur', 'vendeur', 'caissier']);// adapter selon la page

if (isset($_GET['vente_id'])) {
    $vente_id = intval($_GET['vente_id']);

    if ($vente_id > 0) {
        try {
            $pdo->beginTransaction();

            // 1️⃣ Vérifier la vente
            $stmtCheck = $pdo->prepare("SELECT status FROM ventes WHERE vente_id = ?");
            $stmtCheck->execute([$vente_id]);
            $vente = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if (!$vente) throw new Exception("Vente introuvable.");
            if ($vente['status'] === 'Annulée') throw new Exception("Cette vente est déjà annulée.");

            // 2️⃣ Récupérer les produits de la vente
            $stmtProduits = $pdo->prepare("SELECT produit_id, quantite FROM ventes_produits WHERE vente_id = ?");
            $stmtProduits->execute([$vente_id]);
            $produits = $stmtProduits->fetchAll(PDO::FETCH_ASSOC);

            if ($produits) {
                $stmtUpdatestock_actuel = $pdo->prepare("UPDATE produits SET stock_actuel = stock_actuel + ? WHERE produit_id = ?");
                $stmtHistoriquestock_actuel = $pdo->prepare("
                    INSERT INTO historique_stock_actuel
                    (produit_id, type_mouvement, quantite, stock_actuel_avant, stock_actuel_apres, date_mouvement, vente_id, user_id, commentaire)
                    VALUES (?, 'ANNULATION_VENTE', ?, ?, ?, NOW(), ?, ?, ?)
                ");

                foreach ($produits as $p) {
                    // 2a) Récupérer stock_actuel actuel
                    $stmtstock_actuel = $pdo->prepare("SELECT stock_actuel FROM produits WHERE produit_id = ?");
                    $stmtstock_actuel->execute([$p['produit_id']]);
                    $currentstock_actuel = (int)$stmtstock_actuel->fetchColumn();

                    $newstock_actuel = $currentstock_actuel + (int)$p['quantite'];

                    // 2b) Mettre à jour stock_actuel
                    $stmtUpdatestock_actuel->execute([$p['quantite'], $p['produit_id']]);

                    // 2c) Insérer dans historique_stock_actuel
                    $stmtHistoriquestock_actuel->execute([
                        $p['produit_id'],
                        $p['quantite'],
                        $currentstock_actuel,
                        $newstock_actuel,
                        $vente_id,
                        $_SESSION['user_id'],
                        "Annulation de la vente #$vente_id"
                    ]);
                }
            }

            // 3️⃣ Mettre à jour le statut de la vente
            $stmtUpdateVente = $pdo->prepare("UPDATE ventes SET status = 'Annulée' WHERE vente_id = ?");
            $stmtUpdateVente->execute([$vente_id]);

            // 4️⃣ Historique de la vente
            $stmtHistoriqueVente = $pdo->prepare("
                INSERT INTO historique_vente
                (vente_id, ancien_status, nouveau_status, date_mouvement, user_id, commentaire)
                VALUES (?, ?, 'Annulée', NOW(), ?, ?)
            ");
            $stmtHistoriqueVente->execute([
                $vente_id,
                $vente['status'],
                $_SESSION['user_id'],
                "Annulation de la vente #$vente_id"
            ]);

            $pdo->commit();

            header("Location: liste_ventes.php?annulee=1");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: liste_ventes.php?annulee=0&err=" . urlencode($e->getMessage()));
            exit;
        }
    }
}
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
