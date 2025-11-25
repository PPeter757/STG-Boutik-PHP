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

// Vérifie si l'utilisateur connecté est administrateur
if (!isset($_SESSION['nom_role']) || $_SESSION['nom_role'] !== 'administrateur') {
    header('Location: dashboard.php');
    exit;
}

// Récupérer la liste des rôles
$rolesStmt = $pdo->query("SELECT * FROM roles ORDER BY nom_role ASC");
$roles = $rolesStmt->fetchAll(PDO::FETCH_ASSOC);

$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $civilite             = $_POST['civilite'] ?? '';
    $user_nom             = trim($_POST['nom_user']);
    $user_prenom          = trim($_POST['prenom_user']);
    $email                = trim($_POST['email']);
    $role_id              = !empty($_POST['role_id']) ? intval($_POST['role_id']) : null;
    $status_user_account  = $_POST['status_user_account'] ?? 'actif';
    $password             = trim($_POST['password']);
    $status               = 'Disconnected';
    $photo                = '';

    // Vérification des champs
    if (empty($civilite) || empty($user_nom) || empty($user_prenom) || empty($email) || empty($password) || empty($role_id)) {
        $error = "Tous les champs sont obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Adresse email invalide.";
    } else {
        // Génération automatique du username (ex: pjean)
        $base_username = strtolower(substr($user_nom, 0, 1) . $user_prenom);
        $username = $base_username;
        $i = 1;

        while (true) {
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $i++;
                $username = $base_username . str_pad($i, 2, '0', STR_PAD_LEFT);
            } else {
                break;
            }
        }

        // Vérifier si l'email existe déjà
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = "Cet email est déjà utilisé.";
        } else {
            // Gestion du téléchargement de la photo
            if (!empty($_FILES['photo']['name'])) {
                $uploadDir = "uploads/users/";
                if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
                $photoName = time() . '_' . basename($_FILES['photo']['name']);
                $uploadFile = $uploadDir . $photoName;

                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadFile)) {
                    $photo = $photoName;
                } else {
                    $error = "Erreur lors du téléchargement de la photo.";
                }
            }

            if (!$error) {
                // Hachage du mot de passe
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Insertion dans la base (nom_role sera géré par le trigger)
                $insert = $pdo->prepare("
                    INSERT INTO users 
                    (username, email, password, civilite, user_nom, user_prenom, created_at, status, status_user_account, photo, role_id)
                    VALUES 
                    (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
                ");

                if ($insert->execute([
                    $username,
                    $email,
                    $hashedPassword,
                    $civilite,
                    $user_nom,
                    $user_prenom,
                    $status,
                    $status_user_account,
                    $photo,
                    $role_id
                ])) {
                    // Redirection avec message de succès
                    header("Location: users_list.php?msg=" . urlencode("Utilisateur créé avec succès 🎉 — Identifiant : $username"));
                    exit;
                } else {
                    $error = "Erreur lors de l’enregistrement.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Création d’un utilisateur</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 font-sans">

    <?php include 'includes/menu_lateral.php'; ?>

    <main class="ml-64 p-8">
        <h1 class="text-2xl font-bold text-gray-700 mb-4 text-center">
            👤 Ajouter un nouvel utilisateur
        </h1>

        <?php if ($success): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4 text-center"><?= $success ?></div>
        <?php elseif ($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-center"><?= $error ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="bg-white p-6 rounded-lg shadow-md max-w-lg mx-auto space-y-4">

            <!-- Civilité -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Civilité :</label>
                <select name="civilite" class="w-full border p-2 rounded" required>
                    <option value="">-- Sélectionner --</option>
                    <option value="M.">M.</option>
                    <option value="Mme.">Mme.</option>
                </select>
            </div>

            <!-- Nom -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Nom :</label>
                <input type="text" name="nom_user" class="w-full border p-2 rounded" required>
            </div>

            <!-- Prénom -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Prénom :</label>
                <input type="text" name="prenom_user" class="w-full border p-2 rounded" required>
            </div>

            <!-- Email -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Email :</label>
                <input type="email" name="email" class="w-full border p-2 rounded" required>
            </div>

            <!-- Mot de passe -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Mot de passe :</label>
                <input type="password" name="password" class="w-full border p-2 rounded" required>
            </div>

            <!-- Rôle -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Rôle :</label>
                <select name="role_id" class="w-full border p-2 rounded" required>
                    <option value="">-- Choisir un rôle --</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['role_id'] ?>"><?= htmlspecialchars($r['nom_role']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Statut du compte -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Statut du compte :</label>
                <select name="status_user_account" class="w-full border p-2 rounded" required>
                    <option value="actif">Actif</option>
                    <option value="bloquer">Bloqué</option>
                </select>
            </div>

            <!-- Photo -->
            <div>
                <label class="block text-gray-700 font-semibold mb-1">Photo :</label>
                <input type="file" name="photo" class="w-full">
            </div>

            <!-- Bouton -->
            <div class="text-center">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    ➕ Créer l’utilisateur
                </button>
            </div>
        </form>
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