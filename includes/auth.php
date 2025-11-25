<?php
// ---------------------
// SÉCURITÉ & SESSION
// ---------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Désactive le cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Timeout 10 minutes
$timeout = 600;

// Inactivité → déconnexion
if (isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > $timeout) {
        session_unset();
        session_destroy();
        header("Location: logout.php");
        exit;
    }
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/db.php';

// Vérification connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Charger utilisateur depuis DB
$stmt = $pdo->prepare("
    SELECT u.*, r.nom_role 
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE u.user_id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Si l'utilisateur n'existe plus
if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Chargement des variables session
$_SESSION['username']   = $user['username'];
$_SESSION['user_name']  = $user['user_nom'] . ' ' . $user['user_prenom'];
$_SESSION['user_photo'] = $user['photo'] ?: 'avatar.png';
$_SESSION['nom_role']   = strtolower($user['nom_role']);
?>