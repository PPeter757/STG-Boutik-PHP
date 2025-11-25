<?php
session_start();
require_once 'includes/db.php';
require 'vendor/autoload.php'; // Charge PHPMailer

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
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && strtolower($user['status_user_account']) !== 'bloquer') {

            if (password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                // Stocker temporairement l'utilisateur
                $_SESSION['user_temp'] = $user;

                // Vérifier si l'utilisateur a déjà validé OTP aujourd'hui
                $today = date('Y-m-d');
                $stmt = $pdo->prepare("
                    SELECT * FROM otp_verification 
                    WHERE user_id = ? AND DATE(created_at) = ? AND verified = 1
                ");
                $stmt->execute([$user['user_id'], $today]);
                $otp_verified_today = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$otp_verified_today) {
                    // Générer un OTP
                    $otp = rand(100000, 999999);
                    $_SESSION['otp_code'] = $otp;

                    // Enregistrer dans la table otp_verification
                    $stmt = $pdo->prepare("
                        INSERT INTO otp_verification (user_id, otp_code, verified, created_at) 
                        VALUES (?, ?, 0, NOW())
                    ");
                    $stmt->execute([$user['user_id'], $otp]);

                    // Envoi email avec PHPMailer
                    $mail = new PHPMailer(true);

                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'TON_EMAIL@gmail.com';   // <-- Modifier
                        $mail->Password   = 'TON_MOT_DE_PASSE_APP'; // <-- Mot de passe App Gmail
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
                        $error = "Erreur lors de l'envoi de l'email : " . $mail->ErrorInfo;
                    }

                    // Redirection vers la page OTP
                    header("Location: otp_verification.php");
                    exit;

                } else {
                    // Déjà validé aujourd'hui : connexion directe
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['nom_role'] = $user['nom_role'];
                    $_SESSION['user_name'] = $user['user_nom'] . ' ' . $user['user_prenom'];
                    $_SESSION['user_photo'] = $user['photo'] ?? 'avatar.png';

                    header("Location: dashboard.php");
                    exit;
                }
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
        background: url("assets/Recycle Art.JPG") no-repeat center center fixed;
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
