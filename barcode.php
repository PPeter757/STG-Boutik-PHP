<?php
require_once __DIR__ . '/includes/barcode/vendor/autoload.php';

use Picqer\Barcode\BarcodeGeneratorPNG;

if (!isset($_GET['code'])) {
    die("No code provided");
}

$code = preg_replace('/[^0-9A-Za-z]/', '', $_GET['code']); // sécurité

$generator = new BarcodeGeneratorPNG();

// Indiquer au navigateur que la réponse = une image PNG
header('Content-Type: image/png');

// Générer le code-barres
echo $generator->getBarcode($code, $generator::TYPE_CODE_128);
?>
