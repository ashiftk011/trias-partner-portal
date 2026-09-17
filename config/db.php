<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'trias_portal');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Partner Portal');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost:8181/TriasPartnerPortal');

// Global Exception Handler to capture unhandled errors and prevent blank 500 screens
set_exception_handler(function (\Throwable $e) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $errMsg = "Error: " . $e->getMessage() . " in " . basename($e->getFile()) . " on line " . $e->getLine();
    $_SESSION['flash_error'] = $errMsg;

    if (!headers_sent()) {
        http_response_code(500);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['error' => $errMsg, 'detail' => $e->getMessage()]);
            exit;
        }

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/';
        echo '<!DOCTYPE html><html><head><title>Application Error | ' . APP_NAME . '</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        </head><body class="bg-light d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <div class="card border-0 shadow-lg" style="max-width:600px; width:90%;">
            <div class="card-header bg-danger text-white py-3 fw-bold fs-5">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>Application Error (500)
            </div>
            <div class="card-body p-4">
                <div class="alert alert-danger font-monospace small mb-3 text-break">' . htmlspecialchars($errMsg) . '</div>
                <p class="text-muted small mb-2">Technical Exception Details:</p>
                <textarea class="form-control form-control-sm font-monospace bg-light mb-3" rows="5" readonly>' . htmlspecialchars($e->getTraceAsString()) . '</textarea>
                <div class="d-flex justify-content-between">
                    <button class="btn btn-outline-secondary btn-sm" onclick="history.back()"><i class="bi bi-arrow-left me-1"></i>Go Back</button>
                    <a href="' . $baseUrl . '" class="btn btn-primary btn-sm"><i class="bi bi-house me-1"></i>Dashboard</a>
                </div>
            </div>
        </div>
        </body></html>';
        exit;
    } else {
        echo "<div class='alert alert-danger m-3'><strong>System Error:</strong> " . htmlspecialchars($errMsg) . "</div>";
    }
});

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

