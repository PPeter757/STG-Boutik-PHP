<?php
// Récupérer l'URL PostgreSQL depuis Render
$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("❌ DATABASE_URL non détectée. Vérifiez vos variables Render.");
}

// Parser l'URL Render pour extraire host, user, pass, etc.
$parts = parse_url($databaseUrl);

$host = $parts['host'];
$port = isset($parts['port']) ? $parts['port'] : 5432;
$user = $parts['user'];
$pass = $parts['pass'];
$db   = ltrim($parts['path'], '/');

// Construire le DSN PostgreSQL compatible PDO
$dsn = "pgsql:host=$host;port=$port;dbname=$db";

try {
    // Connexion PDO PostgreSQL
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

} catch (PDOException $e) {
    die("❌ Connexion PostgreSQL échouée : " . $e->getMessage());
}
?>
