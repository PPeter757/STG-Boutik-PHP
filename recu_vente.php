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
require_once __DIR__ . '/includes/barcode/vendor/autoload.php';


$vente_id = $_GET['vente_id'] ?? null;
if (!$vente_id) {
    die("ID de vente manquant.");
}

// Récupération de la vente avec infos client et utilisateur
$stmt = $pdo->prepare("SELECT v.*, 
           c.nom AS client_nom, 
           c.prenom AS client_prenom, 
           u.username
    FROM ventes v
    LEFT JOIN clients c ON v.client_id = c.client_id
    LEFT JOIN users u ON v.user_id = u.user_id
    WHERE v.vente_id = ?
");
$stmt->execute([$vente_id]);
$vente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$vente) {
    die("Vente introuvable.");
}

// Récupérer les articles de cette vente
$items_stmt = $pdo->prepare("SELECT vi.*, 
    p.nom AS produit_nom, 
    vi.quantite, 
    vi.prix_vente, 
    vi.subtotal
    FROM vente_items vi
    LEFT JOIN produits p ON vi.produit_id = p.produit_id
    WHERE vi.vente_id = ?
");
$items_stmt->execute([$vente_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculer le total si non enregistré
$total = $vente['total'] ?? array_sum(array_column($items, 'subtotal'));


?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Reçu Vente #<?= htmlspecialchars($vente_id) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print {
                display: none;
            }

            body {
                background: white;
            }
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: #f8fafc;
            color: #333;
        }

        .ticket {
            max-width: 420px;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.1);
        }

        .divider {
            border-bottom: 1px dashed #aaa;
            margin: 10px 0;
        }
    </style>
</head>

<body>
    <div class="ticket">
        <!-- En-tête -->
        <div class="text-center mb-4">
            <div class="flex items-center justify-center gap-2">
                <img src="assets/NPH_logo.png" alt="Logo" class="h-10 w-10 object-contain">
                <h1 class="text-2xl font-bold text-gray-700 uppercase">Kay Ste. Germaine</h1>
            </div>
            <p class="text-sm text-gray-500">Tabarre 27, Angle rue T.Auguste & Pierre Paul, Port-au-Prince - Haïti</p>
            <p class="text-sm text-gray-500">Tél : +509 3800-4206</p>
            <div class="border-t border-gray-300 my-2"></div>
            <p class="text-xs text-gray-400">Reçu de vente N° <?= htmlspecialchars($vente_id) ?></p>
            <p class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($vente['date_vente'])) ?></p>
        </div>

        <!-- Infos vente et client -->
        <div class="mb-3 text-sm">
            <p><strong>Vente # :</strong> <?= htmlspecialchars($vente['vente_id']) ?></p>
            <p><strong>Vendeur : @</strong> <?= htmlspecialchars($vente['username'] ?? ($vente['user_id'] ?? 'N/A')) ?></p>
            <p><strong>Client :</strong> <?= htmlspecialchars(trim(($vente['client_nom'] ?? '') . ' ' . ($vente['client_prenom'] ?? ''))) ?></p>
            <!-- <p><strong>Client :</strong> <?= htmlspecialchars(trim(($vente['client_nom'] ?? '') . ' ' . ($clients['client_prenom'] ?? ''))) ?></p> -->
            <p><strong>Status vente :</strong> <?= htmlspecialchars($vente['status'] ?? '—') ?></p>
        </div>

        <div class="divider"></div>

        <!-- Produits -->
        <table class="w-full text-sm text-left border">
            <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 border">Produit</th>
                    <th class="p-2 border">Qté</th>
                    <th class="p-2 border text-left">Prix unitaire</th>
                    <th class="p-2 border text-left">Sous-total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="p-2 border"><?= htmlspecialchars($it['produit_nom'] ?? $it['nom'] ?? 'Produit inconnu') ?></td>
                        <td class="p-2 text-right border"><?= $it['quantite'] ?></td>
                        <td class="p-2 text-right border"><?= number_format($it['prix_vente'], 2) ?> HTG</td>
                        <td class="p-2 text-right border "><?= number_format($it['subtotal'], 2) ?> HTG</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="divider"></div>

        <!-- Total -->
        <div class="text-right text-lg font-semibold text-gray-700 mb-2">
            Total : <?= number_format($total, 2, ',', ' ') ?> HTG
        </div>

        <!-- Code-barres
        <div class="text-center mt-4">
            <img src="barcode.php?code=<?= urlencode($vente_id) ?>" class="mx-auto">
            <p><?= htmlspecialchars($vente_id) ?></p>
        </div> -->

        <!-- Pied du ticket -->
        <div class="divider"></div>
        <p class="text-center text-xs text-gray-500 mt-3">
            Merci pour votre achat 💙 <br>
            Pas de retour!!!<br>
            © <?= date('Y') ?> Ste Germaine - Tous droits réservés
        </p>

        <!-- Boutons -->
        <div class="no-print text-center mt-4">
            <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                🖨️ Imprimer
            </button>
            <a href="ventes.php" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
                ⬅Retour
            </a>
        </div>
    </div>

    <script>
        // Impression automatique après chargement
        window.onload = () => window.print();

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