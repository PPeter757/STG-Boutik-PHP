<?php
session_start();
require_once 'includes/db.php';
require 'vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Veuillez remplir tous les champs.";
    } else {

        // Vérifier si l'utilisateur existe
        $stmt = $pdo->prepare("
            SELECT u.*, r.nom_role 
            FROM users u
            LEFT JOIN roles r ON r.role_id = u.role_id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && strtolower($user['status_user_account']) !== 'bloquer') {

            if (password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                // Stockage temporaire
                $_SESSION['user_temp'] = $user;

                // Vérifier si déjà validé aujourd’hui
                $stmt = $pdo->prepare("
                    SELECT created_at FROM otp_verification
                    WHERE user_id = ? 
                      AND verified = TRUE
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmt->execute([$user['user_id']]);
                $last_validated = $stmt->fetchColumn();

                $already_valid_today = false;

                if ($last_validated) {
                    $otp_time = new DateTime($last_validated);
                    $now = new DateTime();

                    // Si un OTP a été validé aujourd'hui et dans les dernières 10 minutes
                    if ($otp_time->format('Y-m-d') === $now->format('Y-m-d')) {
                        $diff = $now->getTimestamp() - $otp_time->getTimestamp();
                        if ($diff <= 600) { // 600 sec = 10 minutes
                            $already_valid_today = true;
                        }
                    }
                }

                if ($already_valid_today) {
                    // Connexion directe
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['nom_role'] = $user['nom_role'];
                    $_SESSION['role_id'] = $user['role_id'];
                    $_SESSION['user_name'] = $user['user_nom'] . ' ' . $user['user_prenom'];
                    $_SESSION['user_photo'] = $user['photo'] ?? 'avatar.png';

                    // REDIRECTION SELON ROLE
                    if ($user['role_id'] == 1) {
                        header("Location: dashboard.php");
                    } else {
                        header("Location: dashboard_non_administrateur.php");
                    }
                    exit;
                }

                // Génération OTP
                $otp = rand(100000, 999999);
                $_SESSION['otp_code'] = $otp;

                $stmt = $pdo->prepare("
                    INSERT INTO otp_verification (user_id, otp_code, verified, created_at)
                    VALUES (?, ?, FALSE, NOW())
                ");
                $stmt->execute([$user['user_id'], $otp]);

                // Envoi email OTP
                $mail = new PHPMailer(true);

                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'TON_EMAIL@gmail.com';
                    $mail->Password   = 'TON_MOT_DE_PASSE_APP';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom('TON_EMAIL@gmail.com', 'Boutique');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = "Votre code OTP de connexion";
                    $mail->Body = "
                        <h2>Vérification OTP</h2>
                        <p>Bonjour <strong>{$user['user_nom']} {$user['user_prenom']}</strong>,</p>
                        <p>Voici votre code de connexion :</p>
                        <h1 style='color:blue;'>$otp</h1>
                        <p>Il expire dans <strong>10 minutes</strong>.</p>
                    ";

                    $mail->send();
                } catch (Exception $e) {
                    $error = "Erreur lors de l'envoi du code OTP.";
                }

                header("Location: otp_verification.php");
                exit;
            }
        }

        $error = "Identifiants incorrects ou compte bloqué.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Connexion Boutique</title>
<script src="https://cdn.tailwindcss.com"></script>

<style>
    body {
        min-height: 100vh;
        /* background: url("assets/Recycle Art.JPG") no-repeat center center fixed; */
        background-size: cover;
        display: flex;
        justify-content: center;
        align-items: center;
        font-family: Arial, sans-serif;
        position: relative;
    }

    body::before {
        content: "";
        position: absolute;
        top:0; left:0;
        width:100%; height:100%;
        background: rgba(0,0,0,0.35);
        z-index: 0;
    }

    .login-container {
        position: relative;
        z-index: 10;
        width: 350px;
        padding: 35px;
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.25);
        backdrop-filter: blur(12px);
        box-shadow: 0px 4px 30px rgba(0,0,0,0.3);
        text-align: center;
    }

    .login-container input {
        width: 100%;
        padding: 12px;
        margin-bottom: 15px;
        border-radius: 6px;
        border: 1px solid #ccc;
        font-size: 15px;
        background: rgba(255,255,255,0.8);
    }

    .login-container button {
        width: 100%;
        padding: 12px;
        background: #007bff;
        color: white;
        font-size: 17px;
        border-radius: 6px;
        cursor: pointer;
    }

    .error {
        color: #ff3b3b;
        margin-bottom: 10px;
    }
</style>

</head>
<body>

<div class="login-container">
    <h2 class="text-2xl font-bold mb-4 text-white">Connexion</h2>

    <?php if (!empty($error)) echo "<div class='error'>$error</div>"; ?>

    <form method="POST">
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Mot de passe" required>
        <button type="submit">Se connecter</button>
    </form>
</div>

</body>
</html>
