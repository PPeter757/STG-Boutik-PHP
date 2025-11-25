<?php
// finaliser_vente.php
// Enregistre une vente + items + enregistre les produits "sur commande"
// Conserve la logique et la structure existantes, évite produit_id = 0 pour les custom items.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------
// Configuration & sécurité
// ---------------------------
$timeout = 600; // 10 minutes
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
    header("Location: logout.php?timeout=1");
    exit;
}
$_SESSION['last_activity'] = time();

// Empêcher le cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Vérifier connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Includes
require_once 'includes/db.php';            // $pdo (PDO)
require_once 'includes/role_check.php';
checkRole(['administrateur','superviseur','vendeur','caissier']);

// ---------------------------
// Vérifier méthode POST
// ---------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ventes.php');
    exit;
}

// ---------------------------
// 1️⃣ Récupération des données POST
// ---------------------------
$client_id     = !empty($_POST['client_id']) ? intval($_POST['client_id']) : null;
$payment_method = $_POST['payment_method'] ?? 'Payer cash';
$total         = floatval($_POST['total'] ?? 0);
$status        = ($payment_method === 'Vente à crédit') ? 'Crédit' : 'Payée';
$items         = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];

// Validation minimale
if (!$client_id) {
    die("<script>alert('Veuillez sélectionner un client.'); history.back();</script>");
}
if (!$items || !is_array($items) || count($items) === 0) {
    die("<script>alert('Aucun produit dans le panier.'); history.back();</script>");
}

// ---------------------------
// 2️⃣ Récupérer l'utilisateur connecté
// ---------------------------
$user_id = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("<script>alert('Utilisateur introuvable.'); location.href='login.php';</script>");
}
$username = $user['username'] ?? 'Inconnu';

// ---------------------------
// 3️⃣ Récupérer le client
// ---------------------------
$client_stmt = $pdo->prepare("SELECT nom, prenom, `groupe` FROM clients WHERE client_id = ?");
$client_stmt->execute([$client_id]);
$client = $client_stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    die("<script>alert('Client invalide.'); history.back();</script>");
}
$client_nom = $client['nom'] ?? 'Inconnu';
$client_prenom = $client['prenom'] ?? 'Inconnu';
$client_groupe = $client['groupe'] ?? 'Aucun';

// ---------------------------
// 4️⃣ Vérification des stocks pour PRODUITS RÉELS (pas les custom)
// ---------------------------
$rupture = [];
$insuffisant = [];

// Nous collectons aussi les données produit réelles (cache) pour éviter multiples SELECT identiques
$productCache = [];

foreach ($items as $item) {
    $isCustom = !empty($item['custom']) && $item['custom'] === true;

    // Si produit sur commande (custom), on n'effectue pas cette vérification
    if ($isCustom) continue;

    // produit_id attendu
    if (empty($item['produit_id'])) {
        die("<script>alert('Produit invalide dans le panier.'); history.back();</script>");
    }
    $pid = (int)$item['produit_id'];

    if (!isset($productCache[$pid])) {
        $pstmt = $pdo->prepare("SELECT produit_id, nom, quantite FROM produits WHERE produit_id = ?");
        $pstmt->execute([$pid]);
        $prod = $pstmt->fetch(PDO::FETCH_ASSOC);
        $productCache[$pid] = $prod ?: null;
    } else {
        $prod = $productCache[$pid];
    }

    if (!$prod) {
        die("<script>alert('Produit introuvable (ID: {$pid}).'); history.back();</script>");
    }

    // stock checks
    $stockQty = (int)$prod['quantite'];
    $wantQty = (int)$item['quantite'];
    if ($stockQty <= 0) {
        $rupture[] = $prod['nom'];
    }
    if ($wantQty > $stockQty) {
        $insuffisant[] = "{$prod['nom']} (Stock: {$stockQty})";
    }
}

if (!empty($rupture)) {
    die("<script>alert('Rupture de stock pour : " . addslashes(implode(', ', $rupture)) . "'); history.back();</script>");
}
if (!empty($insuffisant)) {
    die("<script>alert('Stock insuffisant pour : " . addslashes(implode(', ', $insuffisant)) . "'); history.back();</script>");
}

// ---------------------------
// 5️⃣ Enregistrement de la vente (transaction)
// ---------------------------
try {
    $pdo->beginTransaction();
    $now = date('Y-m-d H:i:s');

    // 5.1 Insert dans ventes
    $stmt = $pdo->prepare("
        INSERT INTO ventes 
        (client_id, total, user_id, username, date_vente, payment_method, client_nom, client_prenom, `groupe`, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $client_id,
        $total,
        $user_id,
        $username,
        $now,
        $payment_method,
        $client_nom,
        $client_prenom,
        $client_groupe,
        $status
    ]);
    $vente_id = $pdo->lastInsertId();

    // 5.2 Préparer statements
    $item_stmt = $pdo->prepare("
        INSERT INTO vente_items (vente_id, produit_id, nom, quantite, prix_vente, subtotal)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    // Mise à jour stock : on va utiliser une requête qui évite stock négatif (WHERE quantite >= ?)
    $stock_stmt = $pdo->prepare("
        UPDATE produits 
        SET 
            stock_precedent = quantite,
            ajustement = ?,
            quantite = quantite - ?,
            stock_actuel = quantite - ?
        WHERE produit_id = ? AND quantite >= ?
    ");

    // Insert commandes_sur_commande pour les produits "custom" (hors stock)
    $commande_stmt = $pdo->prepare("
        INSERT INTO commandes_sur_commande 
        (vente_id, produit_id, nom, quantite, prix_vente, date_commande, statut)
        VALUES (?, ?, ?, ?, ?, ?, 'En attente')
    ");

    // 5.3 Parcourir les items et appliquer
    foreach ($items as $item) {
        $isCustom = !empty($item['custom']) && $item['custom'] === true;
        $qty = max(1, (int)($item['quantite'] ?? 0));
        $prix = (float)($item['prix_vente'] ?? 0.0);
        $subtotal = $qty * $prix;
        $nomItem = trim((string)($item['nom'] ?? ''));

        if ($nomItem === '') $nomItem = 'Produit inconnu';

        // Produit réel : passe produit_id (int), mise à jour stock
        if (!$isCustom) {
            $produit_id = !empty($item['produit_id']) ? (int)$item['produit_id'] : null;

            // Insert vente_items (produit_id réel)
            $item_stmt->execute([
                $vente_id,
                $produit_id,
                $nomItem,
                $qty,
                $prix,
                $subtotal
            ]);

            // Mettre à jour le stock (en s'assurant que quantite >= qty)
            // ajustement : on stocke l'ajustement positif (quantité retirée)
            $stock_stmt->execute([
                $qty, // ajustement
                $qty, // quantite = quantite - ?
                $qty, // stock_actuel = quantite - ?
                $produit_id,
                $qty
            ]);

            // Vérifier qu'une ligne a été affectée (si WHERE quantite >= qty échoue)
            if ($stock_stmt->rowCount() === 0) {
                // rollback et message clair
                $pdo->rollBack();
                die("<script>alert('Impossible de mettre à jour le stock pour le produit \"".addslashes($nomItem)."\" — quantité insuffisante ou produit introuvable.'); history.back();</script>");
            }

        } else {
            // Produit "sur commande" (custom) : n'a pas d'entrée stock, on enregistre vente_item avec produit_id NULL
            // On écrit NULL pour produit_id pour bien le distinguer des produits réels
            // NOTE: PDO translate null automatiquement si la valeur fournie est null.
            $item_stmt->execute([
                $vente_id,
                null,
                $nomItem,
                $qty,
                $prix,
                $subtotal
            ]);

            // Enregistrer la commande sur commande pour suivi
            $commande_stmt->execute([
                $vente_id,
                null,        // produit_id inconnu (on garde NULL)
                $nomItem,
                $qty,
                $prix,
                $now
            ]);

            // (Optionnel) récupérer l'id de la commande si tu veux lier plus tard
            // $commande_id = $pdo->lastInsertId();
            // Si tu veux, tu peux ultérieurement ajouter une colonne commande_id à vente_items et l'updater ici.
        }
    }

    // 5.4 Commit
    $pdo->commit();

    // Redirection vers le reçu
    header("Location: recu_vente.php?vente_id=" . $vente_id);
    exit;

} catch (Exception $e) {
    // rollback + message erreur
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = addslashes($e->getMessage());
    die("<script>alert('Erreur lors de l’enregistrement : {$msg}'); history.back();</script>");
}?>
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

