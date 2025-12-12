<?php
// ---------------------
// SÉCURITÉ & SESSION
// ---------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Désactiver le cache navigateur
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Timeout 10 minutes
$timeout = 600;

// Gestion de l'inactivité
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_unset();
        session_destroy();
        header("Location: logout.php");
        exit;
    }
}
$_SESSION['last_activity'] = time();

// ---------------------
// PROTECTION ACCÈS
// ---------------------

// Si pas connecté → redirection
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Charger base de données
require_once __DIR__ . '/db.php';

// Ne recharge l'utilisateur que si pas déjà en session
if (!isset($_SESSION['user_fetched']) || $_SESSION['user_fetched'] !== true) {

    $stmt = $pdo->prepare("
        SELECT u.*, r.nom_role 
        FROM users u
        LEFT JOIN roles r ON r.role_id = u.role_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Si l'utilisateur n'existe plus : déconnexion
    if (!$user) {
        session_destroy();
        header("Location: login.php");
        exit;
    }

    // Stockage sécurisé (évite requery chaque page)
    $_SESSION['username']   = $user['username'];
    $_SESSION['role_id']    = $user['role_id'];
    $_SESSION['nom_role']   = strtolower($user['nom_role']);
    $_SESSION['user_name']  = $user['user_nom'] . ' ' . $user['user_prenom'];
    $_SESSION['user_photo'] = !empty($user['photo']) ? $user['photo'] : 'avatar.png';

    $_SESSION['user_fetched'] = true;
}
?>
