<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// Role permission map
const ROLE_PERMISSIONS = [
    'admin'    => ['dashboard','users','projects','plans','leads','clients','renewals','invoices','settings','proposals','quotations','demos','regions'],
    'finance'  => ['dashboard','invoices','clients','renewals'],
    'telecall' => ['dashboard','leads'],
    'investor' => ['dashboard','clients','leads'],
    'csm'      => ['dashboard','leads','clients','proposals','quotations','demos'],
];

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/modules/auth/login.php');
        exit;
    }
}

function hasAccess(string $module): bool {
    if (!isLoggedIn()) return false;
    $role = $_SESSION['user_role'] ?? '';
    return in_array($module, ROLE_PERMISSIONS[$role] ?? []);
}

function requireAccess(string $module): void {
    requireLogin();
    if (!hasAccess($module)) {
        $_SESSION['flash_error'] = 'You do not have permission to access this section.';
        header('Location: ' . BASE_URL . '/modules/dashboard/index.php');
        exit;
    }
}

function currentUser(): array {
    return [
        'id'   => $_SESSION['user_id']   ?? 0,
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
        'email'=> $_SESSION['user_email'] ?? '',
    ];
}

function isRole(string ...$roles): bool {
    return in_array($_SESSION['user_role'] ?? '', $roles);
}

// Get the project ID assigned to the currently logged-in investor
function getInvestorProjectId(): int {
    static $cached = null;
    if ($cached !== null) return $cached;
    $db = getDB();
    $stmt = $db->prepare("SELECT project_id FROM investor_projects WHERE user_id=?");
    $stmt->execute([$_SESSION['user_id'] ?? 0]);
    $cached = (int)($stmt->fetchColumn() ?: 0);
    return $cached;
}

// CSRF helpers
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token. Please go back and try again.');
    }
}

// Flash messages
function setFlash(string $type, string $message): void {
    $_SESSION['flash_' . $type] = $message;
}

function getFlash(string $type): string {
    $msg = $_SESSION['flash_' . $type] ?? '';
    unset($_SESSION['flash_' . $type]);
    return $msg;
}

function displayFlash(): void {
    foreach (['success', 'error', 'warning', 'info'] as $type) {
        $msg = getFlash($type);
        if ($msg) {
            $cls = ['success'=>'success','error'=>'danger','warning'=>'warning','info'=>'info'][$type];
            echo "<div class='alert alert-{$cls} alert-dismissible fade show' role='alert'>
                    <i class='bi bi-" . ($type==='success'?'check-circle':'exclamation-circle') . "-fill me-2'></i>"
                    . htmlspecialchars($msg) . "
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                  </div>";
        }
    }
}

// Generate unique codes
function generateCode(string $prefix, string $table, string $column): string {
    $db = getDB();
    do {
        $code = $prefix . strtoupper(substr(uniqid(), -6));
        $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ?");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

// Safe redirect
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

// Role display label
function roleLabel(string $role): string {
    return match($role) {
        'admin'    => '<span class="badge bg-danger">Admin</span>',
        'finance'  => '<span class="badge bg-success">Finance</span>',
        'telecall' => '<span class="badge bg-primary">Telecall</span>',
        'investor' => '<span class="badge bg-warning text-dark">Investor</span>',
        'csm'      => '<span class="badge bg-info text-dark">CSM</span>',
        default    => '<span class="badge bg-secondary">' . htmlspecialchars($role) . '</span>',
    };
}

function statusBadge(string $status): string {
    $map = [
        'active'        => 'success',
        'inactive'      => 'secondary',
        'new'           => 'primary',
        'contacted'     => 'info',
        'interested'    => 'warning',
        'not_interested'=> 'danger',
        'follow_up'     => 'warning',
        'converted'     => 'success',
        'pending'       => 'warning',
        'paid'          => 'success',
        'partial'       => 'info',
        'overdue'       => 'danger',
        'cancelled'     => 'secondary',
        'expired'       => 'danger',
        'suspended'     => 'danger',
    ];
    $cls = $map[$status] ?? 'secondary';
    $label = ucfirst(str_replace('_', ' ', $status));
    return "<span class='badge bg-{$cls}'>{$label}</span>";
}
