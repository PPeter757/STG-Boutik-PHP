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
checkRole($pdo, ['administrateur', 'manager']); // adapter selon la page

function updateVenteStatus($vente_id, $new_status, $pdo) {
    // 1️⃣ Mettre à jour le statut de la vente
    $stmt = $pdo->prepare("UPDATE ventes SET status=? WHERE vente_id=?");
    $stmt->execute([$new_status, $vente_id]);

    // 2️⃣ Recalculer la récupération et le stock_actuel de tous les produits
    $update_stock = $pdo->prepare("
        UPDATE produits p
        LEFT JOIN (
            SELECT vi.produit_id, SUM(vi.quantite) AS qte_recuperee
            FROM vente_items vi
            JOIN ventes v ON vi.vente_id = v.vente_id
            WHERE v.status = 'Annulée'
            GROUP BY vi.produit_id
        ) AS t ON p.produit_id = t.produit_id
        SET 
            p.recuperation = COALESCE(t.qte_recuperee, 0),
            p.stock_actuel = p.quantite + COALESCE(t.qte_recuperee, 0)
    ");
    $update_stock->execute();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'modifier') {
    $id = $_POST['vente_id'];
    $statut = $_POST['status'];
    $total = $_POST['total'];

    $stmt = $pdo->prepare("UPDATE ventes SET status=?, total=? WHERE vente_id=?");
    $stmt->execute([$statut, $total, $id]);

    header("Location: liste_ventes.php?success=1");
    exit;
}

// Annulation d'une vente avec restitution sécurisée du stock
if (isset($_GET['action']) && $_GET['action'] === 'annuler' && isset($_GET['vente_id'])) {
    $vente_id = (int)$_GET['vente_id']; // sécurisation de l'ID

    try {
        $pdo->beginTransaction();

        // 1️⃣ Récupérer les articles de la vente
        $items_stmt = $pdo->prepare("SELECT produit_id, quantite FROM vente_items WHERE vente_id=?");
        $items_stmt->execute([$vente_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            throw new Exception("Aucun article trouvé pour cette vente.");
        }

        // 2️⃣ Remettre chaque produit en stock (fusion vérification + update)
        $stock_stmt = $pdo->prepare("
            UPDATE produits 
            SET quantite = quantite + :qte,
                ajustement = ajustement - :qte,
                stock_actuel = quantite + :qte
            WHERE produit_id = :pid
        ");

        foreach ($items as $item) {
            $updated = $stock_stmt->execute([
                ':qte' => $item['quantite'],
                ':pid' => $item['produit_id']
            ]);

            if ($stock_stmt->rowCount() === 0) {
                throw new Exception("Produit introuvable ou mise à jour échouée (ID: {$item['produit_id']}).");
            }
        }
        // Mettre à jour récupération et stock_actuel
        $update_stock = $pdo->prepare("UPDATE produits p
    LEFT JOIN (
        SELECT vi.produit_id, SUM(vi.quantite) AS qte_recuperee
        FROM vente_items vi
        JOIN ventes v ON vi.vente_id = v.vente_id
        WHERE v.status = 'Annulée'
        GROUP BY vi.produit_id
    ) AS t ON p.produit_id = t.produit_id
    SET 
        p.recuperation = COALESCE(t.qte_recuperee, 0),
        p.stock_actuel = p.quantite + COALESCE(t.qte_recuperee, 0)
");
        $update_stock->execute();

        // 3️⃣ Mettre à jour le statut de la vente
        $stmt = $pdo->prepare("UPDATE ventes SET status='Annulée' WHERE vente_id=?");
        $stmt->execute([$vente_id]);

        $pdo->commit();
        header("Location: liste_ventes.php?annulee=1");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        die("Erreur lors de l'annulation de la vente : " . $e->getMessage());
    }
}?>
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
