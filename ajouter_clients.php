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

// Initialiser les variables pour les champs du formulaire
$nom = '';
$prenom = '';
$groupe = '';
$telephone = '';
$adresse = '';
$message = '';

// Traitement du formulaire à la soumission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $nom = $_POST['nom'] ?? '';
  $prenom = $_POST['prenom'] ?? '';
  $groupe = $_POST['groupe'] ?? '';
  $telephone = $_POST['telephone'] ?? '';
  $adresse = $_POST['adresse'] ?? '';

  if ($nom && $prenom && $groupe && $telephone && $adresse) {
    try {
      $stmt = $pdo->prepare("INSERT INTO clients (nom, prenom, groupe, telephone, adresse) VALUES (?, ?, ?, ?, ?)");
      $stmt->execute([$nom, $prenom, $groupe, $telephone, $adresse]);

      $message = "<div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-3 rounded'>
                ✅ Client ajouté avec succès !
            </div>";

      // Réinitialiser les champs
      $nom = $prenom = $groupe = $telephone = $adresse = '';
    } catch (PDOException $e) {
      if ($e->getCode() == 23000) { // Erreur de contrainte UNIQUE
        $message = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-3 rounded'>
                    ⚠️ Ce numéro de téléphone est déjà enregistré.
                </div>";
      } else {
        $message = "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-3 rounded'>
                    ❌ Erreur lors de l’ajout du client : " . htmlspecialchars($e->getMessage()) . "
                </div>";
      }
    }
  } else {
    $message = "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-3 rounded'>
            ⚠️ Veuillez remplir tous les champs.
        </div>";
  }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
  <meta charset="UTF-8">
  <title>Ajouter un client</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>
  <?php include __DIR__ . '/includes/menu_lateral.php'; ?>
  <main class="flex-1 ml-64 p-8">
    <div class="container mt-4">
      <h3>➕ Ajouter un nouveau client</h3>
      <?= $message ?>
      <form method="POST" class="card p-4">
        <div class="mb-3">
          <label class="form-label">Nom de famille</label>
          <input type="text" name="nom" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Prenom</label>
          <input type="text" name="prenom" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Groupe</label>
          <input type="text" name="groupe" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Téléphone</label>
          <input type="text" name="telephone" class="form-control" required>
        </div>
        <div class="mb-3">
          <label class="form-label">Adresse</label>
          <input type="text" name="adresse" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">💾 Enregistrer le client</button>
        <a href="clients.php" class="btn btn-secondary ms-2">↩ Retour à la liste</a>
      </form>
    </div>
  </main>
  <script>
    setTimeout(() => {
      const msg = document.getElementById('alert-msg');
      if (msg) msg.remove();
    }, 3000);

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