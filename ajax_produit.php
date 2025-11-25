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
checkRole(['administrateur', 'superviseur']);// adapter selon la page

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? 'add';
$produit_id = intval($_POST['produit_id'] ?? 0);
$nom = trim($_POST['nom'] ?? '');
$categorie = trim($_POST['categorie'] ?? '');
$prix_achat = floatval($_POST['prix_achat'] ?? 0);
$prix_vente = floatval($_POST['prix_vente'] ?? 0);
$quantite = intval($_POST['quantite'] ?? 0);
$dimension = trim($_POST['dimension'] ?? '');
$code_barre = trim($_POST['code_barre'] ?? '');

// fonction util pour construire le HTML du message (identique au style utilisé dans produits.php)
function makeMessageHtml($type, $text) {
    if ($type === 'success') {
        return "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-3 rounded' id='messageBox'>{$text}</div>";
    } elseif ($type === 'error') {
        return "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-3 rounded' id='messageBox'>{$text}</div>";
    } else {
        return "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 rounded' id='messageBox'>{$text}</div>";
    }
}

if ($nom === '' || $code_barre === '') {
    $html = makeMessageHtml('warning', '⚠️ Nom et code-barres obligatoires');
    echo json_encode(['success' => false, 'message' => 'Nom et code-barres obligatoires', 'message_html' => $html]);
    exit;
}

try {
    if ($action === 'edit' && $produit_id) {
        $stmt = $pdo->prepare("UPDATE produits SET nom=?, categorie=?, prix_achat=?, prix_vente=?, quantite=?, dimension=?, code_barre=? WHERE produit_id=?");
        $stmt->execute([$nom, $categorie, $prix_achat, $prix_vente, $quantite, $dimension, $code_barre, $produit_id]);
        $html = makeMessageHtml('success', '✅ Produit modifié avec succès.');
        // optionnel : stocker en session si tu veux que le message survive à un reload
        $_SESSION['message'] = $html;
        echo json_encode(['success' => true, 'message' => 'Produit modifié avec succès', 'message_html' => $html]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO produits (nom,categorie,prix_achat,prix_vente,quantite,dimension,code_barre) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$nom, $categorie, $prix_achat, $prix_vente, $quantite, $dimension, $code_barre]);
        $html = makeMessageHtml('success', '✅ Produit ajouté avec succès.');
        $_SESSION['message'] = $html;
        echo json_encode(['success' => true, 'message' => 'Produit ajouté avec succès', 'message_html' => $html]);
    }
} catch (PDOException $e) {
    // attention : en production tu peux éviter d'exposer $e->getMessage() directement
    $errText = 'Erreur : ' . htmlspecialchars($e->getMessage());
    $html = makeMessageHtml('error', $errText);
    echo json_encode(['success' => false, 'message' => $errText, 'message_html' => $html]);
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

