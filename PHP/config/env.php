<?php
// config/env.php - Fichier de configuration des variables d'environnement

// Charger les variables depuis le fichier .env.php si il existe
$envFile = __DIR__ . '/../.env.php';
if (file_exists($envFile)) {
    $env = include $envFile;
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Valeurs par défaut si non définies
$_ENV['EQUIPE_SECRET'] = $_ENV['EQUIPE_SECRET'] ?? '';
$_ENV['HIPPODATA_BEARER'] = $_ENV['HIPPODATA_BEARER'] ?? '';

// Vérifier que les clés sont bien définies
if (empty($_ENV['EQUIPE_SECRET'])) {
    error_log('Warning: EQUIPE_SECRET is not defined in .env.php');
}

if (empty($_ENV['HIPPODATA_BEARER'])) {
    error_log('Warning: HIPPODATA_BEARER is not defined in .env.php');
}
?>
