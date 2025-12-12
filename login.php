<?php
session_start();
require_once 'includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = "Veuillez remplir tous les champs.";
    } else {

        // Récupération utilisateur + rôle
        $stmt = $pdo->prepare("
            SELECT u.*, r.nom_role
            FROM users u
            LEFT JOIN roles r ON r.role_id = u.role_id
            WHERE u.email = ?
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && strtolower($user['status_user_account']) !== 'bloquer') {

            // Vérification mot de passe
            if (password_verify($password, $user['password'])) {

                session_regenerate_id(true);

                $_SESSION['logged_in'] = true;
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['nom_role'] = $user['nom_role'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['user_name'] = $user['user_nom'] . ' ' . $user['user_prenom'];
                $_SESSION['user_photo'] = $user['photo'] ?? 'avatar.png';

                // ✔ Redirection selon rôle
                if ($user['role_id'] == 1) {
                    header("Location: dashboard.php");
                } else {
                    header("Location: dashboard_non_administrateur.php");
                }
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
