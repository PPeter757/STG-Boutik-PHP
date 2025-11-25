<?php
// Si l'utilisateur n'est pas administrateur, rediriger vers le dashboard non administrateur
if ($_SESSION['nom_role'] !== 'administrateur') {
    header('Location: dashboard_non_administrateur.php');
    exit;
}
?>