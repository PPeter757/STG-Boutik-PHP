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
checkRole(['administrateur', 'superviseur']);// adapter selon la page

header('Content-Type: application/json');

try {
    // 🔹 Paramètres de pagination
    $perPage = 11;
    $page = isset($_GET['page']) && is_numeric($_GET['page']) ? intval($_GET['page']) : 1;
    $offset = ($page - 1) * $perPage;

    // 🔹 Compter le total de produits
    $totalProduits = $pdo->query("SELECT COUNT(*) FROM produits")->fetchColumn();
    $totalPages = ceil($totalProduits / $perPage);

    // 🔹 Récupérer les produits paginés
    $stmt = $pdo->prepare("SELECT * FROM produits ORDER BY produit_id DESC LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 🔹 Génération du contenu du tableau (tbody)
    ob_start();
    foreach ($produits as $p): ?>
        <tr class="border-b hover:bg-gray-50">
            <td class="p-2 text-left"><?= htmlspecialchars($p['code_barre']) ?></td>
            <td class="p-2 text-left"><?= htmlspecialchars($p['nom']) ?></td>
            <td class="p-2 text-right"><?= htmlspecialchars($p['stock_precedent'] ?? 0) ?></td>
            <td class="p-2 text-right">
                <?php
                $ajustement = $p['ajustement'] ?? 0;
                if ($ajustement > 0) {
                    echo "<span class='text-green-600 font-semibold'>+$ajustement</span>";
                } elseif ($ajustement < 0) {
                    echo "<span class='text-red-600 font-semibold'>$ajustement</span>";
                } else {
                    echo "0";
                }
                ?>
            </td>
            <td class="p-3 text-right font-semibold text-blue-700"><?= htmlspecialchars($p['stock_actuel'] ?? $p['quantite']) ?></td>
            <td class="p-3 text-right"><?= number_format($p['prix_vente'], 2) ?> HTG</td>
            <td class="p-3 text-right font-semibold"><?= number_format(($p['stock_actuel'] ?? $p['quantite']) * $p['prix_vente'], 2) ?> HTG</td>
        </tr>
    <?php endforeach;
    $tbody = ob_get_clean();

    // 🔹 Génération de la pagination HTML (5 pages visibles max)
    ob_start();

    if ($totalPages > 1):
        $maxVisible = 5;
        $start = max(1, $page - floor($maxVisible / 2));
        $end = min($totalPages, $start + $maxVisible - 1);

        // Ajuster si on est proche de la fin
        if ($end - $start + 1 < $maxVisible) {
            $start = max(1, $end - $maxVisible + 1);
        }

        echo '<div class="flex justify-center space-x-1">';

        // Bouton précédent
        if ($page > 1) {
            echo '<a href="#" data-page="' . ($page - 1) . '" class="px-3 py-1 border rounded bg-white text-blue-600">«</a>';
        }

        // Points de suspension avant
        if ($start > 1) {
            echo '<span class="px-2 text-gray-500">...</span>';
        }

        // Liens de pages
        for ($i = $start; $i <= $end; $i++) {
            $classes = $i === $page
                ? 'bg-blue-600 text-white'
                : 'bg-white text-blue-600 border';
            echo '<a href="#" data-page="' . $i . '" class="px-3 py-1 rounded ' . $classes . '">' . $i . '</a>';
        }

        // Points de suspension après
        if ($end < $totalPages) {
            echo '<span class="px-2 text-gray-500">...</span>';
        }

        // Bouton suivant
        if ($page < $totalPages) {
            echo '<a href="#" data-page="' . ($page + 1) . '" class="px-3 py-1 border rounded bg-white text-blue-600">»</a>';
        }

        echo '</div>';
    endif;

    $pagination = ob_get_clean();

    // 🔹 Retour JSON
    echo json_encode([
        'tbody' => $tbody,
        'pagination' => $pagination
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>

