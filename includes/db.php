<?php
$url = getenv('DATABASE_URL');

if (!$url) {
    die("DATABASE_URL is missing.");
}

$parts = parse_url($url);

$host = $parts['host'];
$port = isset($parts['port']) ? $parts['port'] : 5432;
$user = $parts['user'];
$pass = $parts['pass'];
$db   = ltrim($parts['path'], '/');

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$db",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (Exception $e) {
    die("❌ Connexion PostgreSQL échouée : " . $e->getMessage());
}
?>
