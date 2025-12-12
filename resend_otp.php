<?php
session_start();
require_once 'includes/db.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Vérifier que l'utilisateur est bien en phase OTP
if (!isset($_SESSION['user_temp'])) {
    header("Location: login.php");
    exit;
}

$user = $_SESSION['user_temp'];
$user_id = $user['user_id'];
$email   = $user['email'];
$nom     = $user['user_nom'];
$prenom  = $user['user_prenom'];

$error = '';
$success = '';

// Anti-spam : 1 OTP / 60 secondes
if (isset($_SESSION['last_otp_time']) && time() - $_SESSION['last_otp_time'] < 60) {
    $remaining = 60 - (time() - $_SESSION['last_otp_time']);
    $error = "Veuillez patienter encore $remaining secondes avant de redemander un code.";
} else {

    try {
        // 🔐 Générer un nouveau OTP
        $otp = rand(100000, 999999);
        $_SESSION['otp_code'] = $otp;
        $_SESSION['last_otp_time'] = time();

        // 🗃 Enregistrer dans PostgreSQL
        $stmt = $pdo->prepare("
            INSERT INTO otp_verification (user_id, otp_code, verified, created_at)
            VALUES (?, ?, FALSE, NOW())
        ");
        $stmt->execute([$user_id, $otp]);

        // 📩 Envoi de l'email
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'TON_EMAIL@gmail.com';      // <--- À modifier
        $mail->Password   = 'TON_MOT_DE_PASSE_APP';     // <--- À modifier
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom('TON_EMAIL@gmail.com', 'Boutique');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = "Votre nouveau code OTP";

        $mail->Body = "
            <div style='font-family:Arial,sans-serif; line-height:1.6;'>
                <h2>Nouveau code OTP</h2>
                <p>Bonjour <strong>$nom $prenom</strong>,</p>
                <p>Voici votre nouveau code de connexion :</p>
                <h1 style='color:#1a73e8;'>$otp</h1>
                <p>Ce code expire dans <strong>10 minutes</strong>.</p>
            </div>
        ";

        $mail->send();

        $success = "Un nouveau code OTP a été envoyé à votre adresse email.";

    } catch (Exception $e) {
        $error = "Erreur lors de l'envoi du mail : " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renvoyer OTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-200 flex items-center justify-center h-screen">

    <div class="bg-white p-8 rounded shadow-lg w-full max-w-md">
        <h1 class="text-2xl font-bold mb-4 text-center">Renvoyer OTP</h1>

        <?php if ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="otp_verification.php" class="text-blue-600 underline">
                Retourner à la vérification OTP
            </a>
        </div>
    </div>

</body>
</html>
