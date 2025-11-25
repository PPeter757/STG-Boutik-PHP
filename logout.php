<?php
session_start();

// Supprime toutes les variables de session
session_unset();

// Détruit la session côté serveur
session_destroy();

// Détruit également le cookie de session (très important)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// Empêche le navigateur de garder les pages en cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Redirige vers la page de login
header("Location: login.php");
exit;
?>
