<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Durée d'inactivité avant fermeture automatique (en secondes)
$timeout = 600; // 10 minutes — ajustable

// Vérifier si l'utilisateur est inactif
if (isset($_SESSION['last_activity'])) {
    if ((time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        header("Location: logout.php?timeout=1");
        exit;
    }
}

// Mettre à jour l’activité
$_SESSION['last_activity'] = time();

// Empêcher le cache du navigateur après déconnexion
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

// Vérification connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Vérification rôle (si nécessaire)
require_once 'includes/db.php';
require_once 'includes/role_check.php';
checkRole(['administrateur']); // adapter selon la page

// Vérifier si l'utilisateur est administrateur
if ($_SESSION['nom_role'] !== 'administrateur') {
    die('Accès refusé.');
}

$error = $success = '';

if (!isset($_GET['id'])) {
    die('ID utilisateur manquant.');
}

$user_id = (int)$_GET['id'];

// Récupérer les infos actuelles de l'utilisateur
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Utilisateur introuvable.');
}

// Récupérer la liste des rôles
$rolesStmt = $pdo->query("SELECT * FROM roles ORDER BY nom_role ASC");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

// Options de statut
$status_options = ['actif', 'bloquer'];

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_nom = trim($_POST['user_nom']);
    $user_prenom = trim($_POST['user_prenom']);
    $email = trim($_POST['email']);
    $role_id = intval($_POST['role_id']);
    $status_user_account = $_POST['status_user_account'];
    $password = trim($_POST['password']);

    if (empty($user_nom) || empty($user_prenom) || empty($email) || empty($role_id) || empty($status_user_account)) {
        $error = "Tous les champs sauf le mot de passe sont obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Adresse email invalide.";
    } else {
        // Vérifier si l'email existe pour un autre utilisateur
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            $error = "Cet email est déjà utilisé par un autre utilisateur.";
        } else {
            // Requête de mise à jour
            $sql = "UPDATE users 
                    SET user_nom = :nom, 
                        user_prenom = :prenom, 
                        email = :email, 
                        role_id = :role, 
                        status_user_account = :status";
            if (!empty($password)) {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $sql .= ", password = :password";
            }
            $sql .= " WHERE user_id = :id";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':nom', $user_nom);
            $stmt->bindValue(':prenom', $user_prenom);
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':role', $role_id, PDO::PARAM_INT);
            $stmt->bindValue(':status', $status_user_account);
            if (!empty($password)) {
                $stmt->bindValue(':password', $hashed);
            }
            $stmt->bindValue(':id', $user_id, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $success = "Utilisateur mis à jour avec succès 🎉";

                // Recharger les infos
                $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "Erreur lors de la mise à jour.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Modifier un utilisateur</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 font-sans">

    <?php include 'includes/menu_lateral.php'; ?>

    <main class="ml-64 p-8">
        <h1 class="text-2xl flex justify-center font-bold mb-4">✏️ Modifier l'utilisateur #<?= $user['user_id'] ?></h1>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= $success ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" class="bg-white p-6 rounded-lg shadow-md max-w-lg mx-auto space-y-4">
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Nom :</label>
                <input type="text" name="user_nom" value="<?= htmlspecialchars($user['user_nom']) ?>" class="w-full border p-2 rounded" required>
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-1">Prénom :</label>
                <input type="text" name="user_prenom" value="<?= htmlspecialchars($user['user_prenom']) ?>" class="w-full border p-2 rounded" required>
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-1">Email :</label>
                <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="w-full border p-2 rounded" required>
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-1">Rôle :</label>
                <select name="role_id" class="w-full border p-2 rounded" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['role_id'] ?>" <?= $user['role_id'] == $r['role_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($r['nom_role']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-1">Statut du compte :</label>
                <select name="status_user_account" class="w-full border p-2 rounded" required>
                    <?php foreach ($status_options as $status): ?>
                        <option value="<?= $status ?>" <?= $user['status_user_account'] === $status ? 'selected' : '' ?>>
                            <?= ucfirst($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 font-semibold mb-1">Mot de passe (laisser vide pour conserver l'actuel) :</label>
                <input type="password" name="password" class="w-full border p-2 rounded">
            </div>

            <div class="text-center">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">💾 Enregistrer</button>
                <a href="users_list.php" class="bg-purple-500 text-white px-4 py-2 rounded hover:bg-purple-600 ml-2">↩️ Retour</a>
            </div>
        </form>
    </main>
    <script>
        // Durée d'inactivité en millisecondes
        const timeout = <?php echo $timeout * 1000; ?>;

        let timer;

        // Réinitialiser le timer à chaque interaction
        function resetTimer() {
            clearTimeout(timer);
            timer = setTimeout(() => {
                // Redirige vers logout.php ou recharge la page
                window.location.href = 'logout.php?timeout=1';
            }, timeout);
        }

        // Événements pour détecter activité
        window.onload = resetTimer;
        document.onmousemove = resetTimer;
        document.onkeypress = resetTimer;
        document.onclick = resetTimer;
        document.onscroll = resetTimer;
    </script>
</body>

</html>