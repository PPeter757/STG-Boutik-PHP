<?php
// includes/db.php

// 🔒 Empêcher l'exécution directe du fichier
if (basename($_SERVER['PHP_SELF']) === 'db.php') {
    die("Accès direct interdit.");
}

// ⚙️ Paramètres de connexion
$host = 'localhost';       // Hôte du serveur
$dbname = 'gestion_boutique';      // Nom de ta base de données
$username = 'root';        // Nom d'utilisateur MySQL (par défaut sur XAMPP)
$password = '';            // Mot de passe MySQL (vide par défaut sur XAMPP)

try {
    // Connexion avec PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    // Mode d'erreur : exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Mode de récupération par défaut : tableau associatif
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // En cas d'erreur de connexion
    die("Erreur de connexion à la base de données : " . $e->getMessage());
}
?>
