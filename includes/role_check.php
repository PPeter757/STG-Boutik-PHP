<?php
function checkRole($allowedRoles = []) {

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['nom_role'])) {
        header("Location: login.php");
        exit;
    }

    $role = strtolower($_SESSION['nom_role']);

    if (!in_array($role, array_map('strtolower', $allowedRoles))) {
        header("Location: acces_refuse.php");
        exit;
    }
}
?>