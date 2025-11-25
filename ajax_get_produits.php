<?php
// ajax_get_produit.php
// require_once 'includes/db.php';
// header('Content-Type: application/json; charset=utf-8');

// $code = trim((string)($_GET['code'] ?? ''));

// if ($code === '') {
//     echo json_encode(['found' => false, 'error' => 'code vide']);
//     exit;
// }

// try {
//     $stmt = $pdo->prepare("SELECT produit_id, nom, categorie, prix_achat, prix_vente, quantite, dimension, code_barre FROM produits WHERE code_barre = ? LIMIT 1");
//     $stmt->execute([$code]);
//     $p = $stmt->fetch(PDO::FETCH_ASSOC);
//     if ($p) {
//         // normaliser les valeurs numériques
//         $p['prix_achat'] = isset($p['prix_achat']) ? (float)$p['prix_achat'] : 0;
//         $p['prix_vente'] = isset($p['prix_vente']) ? (float)$p['prix_vente'] : 0;
//         $p['quantite'] = isset($p['quantite']) ? (int)$p['quantite'] : 0;

//         echo json_encode(['found' => true, 'produit' => $p], JSON_UNESCAPED_UNICODE);
//     } else {
//         echo json_encode(['found' => false]);
//     }
// } catch (PDOException $e) {
//     // Ne pas exposer message SQL en production
//     echo json_encode(['found' => false, 'error' => 'db_error']);
// }
// exit;
