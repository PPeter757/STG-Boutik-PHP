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
checkRole(['administrateur', 'superviseur', 'vendeur', 'caissier']); // adapter selon la page

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    header("Location: login.php");
    exit;
}

$user_nom = $user_prenom = $email = $civilite = $photo = '';
$message = '';
$password_message = '';

// ====================
// 1️⃣ Pré-remplissage
// ====================
$stmt = $pdo->prepare("SELECT user_nom, user_prenom, email, civilite, photo FROM users WHERE user_id=? LIMIT 1");
$stmt->execute([$user_id]);
if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $user_nom = $row['user_nom'];
    $user_prenom = $row['user_prenom'];
    $email = $row['email'];
    $civilite = $row['civilite'] ?? 'M.';
    $photo = $row['photo'] ?? 'default.png';
}

// ====================
// 2️⃣ Gestion du POST
// ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_nom = trim($_POST['user_nom'] ?? '');
    $user_prenom = trim($_POST['user_prenom'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $civilite = trim($_POST['civilite'] ?? 'M.');
    $new_password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['password_confirm'] ?? '');

    // Validation de base
    if ($user_nom === '' || $user_prenom === '' || $email === '') {
        $message = "⚠️ Tous les champs personnels sont obligatoires.";
    } elseif ($new_password !== '' && $new_password !== $confirm_password) {
        $message = "⚠️ Les mots de passe ne correspondent pas.";
    } else {
        try {
            // =========================
            // 3️⃣ Gestion de la photo
            // =========================
            if (!empty($_FILES['photo']['name'])) {
                $photo_tmp = $_FILES['photo']['tmp_name'];
                $photo_name = time() . "_" . basename($_FILES['photo']['name']);
                $target_dir = __DIR__ . "/uploads/";
                $target_path = $target_dir . $photo_name;

                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }

                if (move_uploaded_file($photo_tmp, $target_path)) {
                    $photo = $photo_name;
                } else {
                    $message = "❌ Erreur lors du téléchargement de la photo.";
                }
            }

            // =========================
            // 4️⃣ Mise à jour SQL
            // =========================
            if ($new_password !== '') {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET civilite=?, user_nom=?, user_prenom=?, email=?, password=?, photo=? WHERE user_id=?");
                $stmt->execute([$civilite, $user_nom, $user_prenom, $email, $hashed, $photo, $user_id]);
                $password_message = "✅ Mot de passe changé avec succès.";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET civilite=?, user_nom=?, user_prenom=?, email=?, photo=? WHERE user_id=?");
                $stmt->execute([$civilite, $user_nom, $user_prenom, $email, $photo, $user_id]);
            }

            // =========================
            // 5️⃣ Mise à jour session
            // =========================
            $_SESSION['civilite'] = $civilite;
            $_SESSION['user_prenom'] = $user_prenom;
            $_SESSION['photo'] = $photo;

            $message = "✅ Profil mis à jour avec succès.";
        } catch (PDOException $e) {
            $message = "❌ Erreur SQL : " . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8">
    <title>Profil utilisateur</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">

    <?php include 'includes/menu_lateral.php'; ?>

    <main class="ml-64 p-8">
        <div class="max-w-3xl mx-auto">
            <h1 class="text-3xl font-bold text-blue-700 mb-6">👤 Mon profil</h1>

            <div class="mb-4">
                <a href="dashboard.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">🏠 Retour à l'accueil</a>
            </div>

            <?php if ($message): ?>
                <div id="msg-box" class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($password_message): ?>
                <div id="pwd-msg" class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 rounded mb-4"><?= htmlspecialchars($password_message) ?></div>
            <?php endif; ?>

            <div class="bg-white shadow rounded-lg p-6">
                <form method="POST" enctype="multipart/form-data" class="grid gap-4">
                    <div class="flex flex-col items-center mb-4">
                        <img src="uploads/<?= htmlspecialchars($photo) ?>" alt="Photo de profil" class="w-12 h-12 rounded-full border-2 border-blue-400 shadow" onerror="this.src='uploads/avatar.png'">
                        <label class="text-sm text-gray-600">Changer la photo</label>
                        <input type="file" name="photo" accept="image/*" class="border p-2 w-full rounded">
                    </div>

                    <div>
                        <label class="text-sm text-gray-600">Civilité</label>
                        <select name="civilite" class="border p-2 w-full rounded" required>
                            <option value="M." <?= ($civilite === 'M.') ? 'selected' : '' ?>>M.</option>
                            <option value="Mme." <?= ($civilite === 'Mme.') ? 'selected' : '' ?>>Mme.</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm text-gray-600">Nom</label>
                        <input type="text" name="user_nom" value="<?= htmlspecialchars($user_nom) ?>" class="border p-2 w-full rounded" required>
                    </div>

                    <div>
                        <label class="text-sm text-gray-600">Prénom</label>
                        <input type="text" name="user_prenom" value="<?= htmlspecialchars($user_prenom) ?>" class="border p-2 w-full rounded" required>
                    </div>

                    <div>
                        <label class="text-sm text-gray-600">Email</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($email) ?>" class="border p-2 w-full rounded" required>
                    </div>

                    <hr class="my-4">

                    <div>
                        <label class="text-sm text-gray-600">Nouveau mot de passe</label>
                        <input type="password" name="password" placeholder="Laisser vide pour ne pas changer" class="border p-2 w-full rounded">
                    </div>

                    <div>
                        <label class="text-sm text-gray-600">Confirmer le mot de passe</label>
                        <input type="password" name="password_confirm" placeholder="Confirmez le mot de passe" class="border p-2 w-full rounded">
                    </div>

                    <div class="flex justify-end mt-2">
                        <button type="submit" class="bg-blue-600 text-white px-5 py-2 rounded hover:bg-blue-700">
                            💾 Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        // Durée d'inactivité côté client (ms)
        const timeout = <?php echo $timeout * 1000; ?>; // ex: 600000 ms pour 10 min
        const warningTime = 60 * 1000; // 1 min avant expiration
        let timer, warningTimer;

        function startTimers() {
            clearTimeout(timer);
            clearTimeout(warningTimer);

            // Timer pour afficher l'alerte
            warningTimer = setTimeout(() => {
                showWarning();
            }, timeout - warningTime);

            // Timer pour rediriger après timeout
            timer = setTimeout(() => {
                window.location.href = 'logout.php?timeout=1';
            }, timeout);
        }

        function resetTimers() {
            startTimers();
        }

        function showWarning() {
            // Créer un élément de notification
            let warningBox = document.getElementById('session-warning');
            if (!warningBox) {
                warningBox = document.createElement('div');
                warningBox.id = 'session-warning';
                warningBox.innerHTML = `
                <div class="fixed top-4 right-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded shadow-lg z-50">
                    ⚠️ Votre session expire dans 1 minute.
                    <button id="extend-session" class="ml-2 px-2 py-1 bg-yellow-500 text-white rounded hover:bg-yellow-600">Prolonger</button>
                </div>
            `;
                document.body.appendChild(warningBox);

                document.getElementById('extend-session').onclick = () => {
                    fetch('keep_alive.php') // petit script PHP pour prolonger session
                        .then(() => {
                            warningBox.remove();
                            resetTimers();
                        })
                        .catch(() => {
                            alert('Erreur, impossible de prolonger la session.');
                        });
                };
            }
        }

        // Détecter activité utilisateur
        ['mousemove', 'keypress', 'click', 'scroll'].forEach(evt => {
            window.addEventListener(evt, resetTimers);
        });

        window.addEventListener('load', startTimers);
    </script>
</body>
</html>