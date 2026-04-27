<?php
// ============================================================
// MedRDV – Configuration Base de Données
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'medrdv');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

// ⚠️ Adaptez selon votre hébergement (localhost ou domaine en prod)
define('BASE_URL', 'http://localhost/FR/medrdv-php-fixed'); // Ex prod: 'https://votredomaine.tn/medrdv-php'

define('APP_NAME', 'MedRDV');

define('MAIL_HOST',      'smtp.gmail.com');
define('MAIL_PORT',      587);
define('MAIL_USER',      'eyaarfaoui45@gmail.com');
define('MAIL_PASS',      'glkf gzjy bytn ttfq');
define('MAIL_FROM',      'noreply@medrdv.tn');
define('MAIL_FROM_NAME', 'MedRDV');

// ⚠️  DEV_MODE = true  → pas d'envoi email réel, compte activé directement
// ⚠️  DEV_MODE = false → envoi email réel via SMTP (PRODUCTION)
define('DEV_MODE', FALSE); // ← Mettez FALSE en production

define('SESSION_LIFETIME', 7200);

// ─── Suppression de tous les warnings/notices PHP ────────────
// (évite que PHP pollue la réponse JSON avec du HTML)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Erreur base de données: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
