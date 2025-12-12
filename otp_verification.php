<?php
session_start();
require_once 'includes/db.php';

// Debug (désactiver en production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Vérifier que l'utilisateur et le code OTP existent
if (!isset($_SESSION['otp_code'], $_SESSION['user_temp'])) {
    header("Location: login.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $entered = trim($_POST['otp']);
    $expected = $_SESSION['otp_code'];
    $user = $_SESSION['user_temp'];
    $user_id = $user['user_id'];

    // Vérifier expiration OTP (10 minutes max)
    $stmt = $pdo->prepare("
        SELECT created_at 
        FROM otp_verification 
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $otp_created_at = $stmt->fetchColumn();

    if ($otp_created_at) {
        $otp_time = new DateTime($otp_created_at);
        $now = new DateTime();
        $diff_seconds = $now->getTimestamp() - $otp_time->getTimestamp();

        if ($diff_seconds > 600) { // 10 minutes
            $error = "❌ Code OTP expiré. Veuillez demander un nouveau code.";
        }
    }

    // Vérification du code OTP
    if (!$error && $entered === $expected) {

        // Marquer l’OTP le plus récent comme vérifié
        $stmt = $pdo->prepare("
            UPDATE otp_verification
            SET verified = TRUE
            WHERE ctid = (
                SELECT ctid FROM otp_verification 
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT 1
            )
        ");
        $stmt->execute([$user_id]);

        // Connexion réussie → création session utilisateur
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['nom_role'] = $user['nom_role'];
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['user_name'] = $user['user_nom'] . ' ' . $user['user_prenom'];
        $_SESSION['user_photo'] = $user['photo'] ?? 'avatar.png';

        // Nettoyer les sessions temporaires
        unset($_SESSION['otp_code']);
        unset($_SESSION['user_temp']);

        // Redirection selon rôle
        if ($user['role_id'] == 1) {
            header("Location: dashboard.php");
        } else {
            header("Location: dashboard_non_administrateur.php");
        }
        exit;

    } else {
        if (!$error) {
            $error = "⚠ Code OTP incorrect.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vérification OTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
        <h1 class="text-2xl font-bold mb-6 text-center">Vérification OTP</h1>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="block mb-1 font-semibold">Entrez le code OTP</label>
                <input 
                    type="text" 
                    name="otp" 
                    class="w-full border px-3 py-2 rounded" 
                    required
                >
            </div>

            <button 
                type="submit" 
                class="w-full bg-green-600 text-white py-2 rounded hover:bg-green-700 transition">
                Vérifier
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-500 text-center">
            Vous n'avez pas reçu le code ?
            <a href="resend_otp.php" class="text-blue-600 underline">Renvoyer le OTP</a>.
        </p>
    </div>
</body>
</html>
