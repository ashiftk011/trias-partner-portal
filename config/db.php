<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'trias_portal');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Partner Portal');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost:8181/TriasPartnerPortal');

function updateExpiredTrials(PDO $db): void {
    static $run = false;
    if (!$run) {
        try {
            $db->query("UPDATE leads SET status = 'trial_ended', updated_at = NOW() WHERE status = 'trial' AND trial_end_date < CURRENT_DATE()");
        } catch (Exception $e) {
            // Ignore error if database migration is not run yet or table doesn't exist
        }
        $run = true;
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            updateExpiredTrials($pdo);
        } catch (PDOException $e) {
            die('<div style="font-family:sans-serif;padding:20px;background:#fee;border:1px solid #f00;margin:20px;border-radius:6px;"><strong>Database Connection Failed:</strong> ' . htmlspecialchars($e->getMessage()) . '</div>');
        }
    }
    return $pdo;
}
