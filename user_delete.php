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

if ($_SESSION['nom_role'] != 'administrateur') exit('Accès refusé');

$id = $_GET['id'] ?? 0;
$pdo->prepare("DELETE FROM users WHERE user_id = ?")->execute([$id]);
header('Location: users_list.php');
exit;
?>
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

