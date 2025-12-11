<?php
// Récupérer l'URL PostgreSQL depuis Render
$databaseUrl = getenv("DATABASE_URL");

if (!$databaseUrl) {
    die("❌ DATABASE_URL non détectée. Vérifiez vos variables Render.");
}

try {
    // Créer la connexion PDO
    $pdo = new PDO($databaseUrl);

    // Activer les erreurs PDO
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    die("❌ Connexion PostgreSQL échouée : " . $e->getMessage());
}
?>
