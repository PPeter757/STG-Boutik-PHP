$host = "dpg-d4th8rpr0fns73deo5ig-a.oregon-postgres.render.com";
$dbname = "gestion_boutique";
$user = "gestion_boutique_user";
$pass = "p4hMxXFoaL1ZyhaHVtPeTt74nigPD0bS";

$dsn = "pgsql:host=$host;port=5432;dbname=$dbname;";

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    die("❌ Connexion PostgreSQL échouée : " . $e->getMessage());
}
