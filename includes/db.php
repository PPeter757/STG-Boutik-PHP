<?php
// Connexion PostgreSQL Render
$host = "dpg-d4th8rpr0fns73deo5ig-a.oregon-postgres.render.com";
$dbname = "gestion_boutique";
$user = "gestion_boutique_user";
$pass = "p4hMxXFoaL1ZyhaHVtPeTt74nigPD0bS";

// DSN PDO PostgreSQL
$dsn = "pgsql:host=$host;port=5432;dbname=$dbname;";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("❌ Connexion PostgreSQL échouée : " . $e->getMessage());
}
?>
