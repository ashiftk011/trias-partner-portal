<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// Role permission map
const ROLE_PERMISSIONS = [
    'admin'    => ['dashboard','users','projects','plans','leads','clients','renewals','invoices','payments','settings','proposals','quotations','demos','regions','hr','expenses','accounts'],
    'finance'  => ['dashboard','invoices','clients','renewals','payments','hr','expenses','accounts'],
    'accounts' => ['dashboard','payments','invoices','hr','expenses','accounts'],
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

// Get all project IDs assigned to the currently logged-in telecall user
function getTelecallProjectIds(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT project_id FROM telecall_projects WHERE user_id=?");
        $stmt->execute([$_SESSION['user_id'] ?? 0]);
        $cached = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (\Exception $e) {
        $cached = []; // table may not exist yet
    }
    return $cached;
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
    foreach (['success', 'error', 'danger', 'warning', 'info'] as $type) {
        $msg = getFlash($type);
        if ($msg) {
            if ($type === 'error') {
                // Render exact error popup modal
                echo "<div class='modal fade' id='errorDetailModal' tabindex='-1' aria-labelledby='errorDetailModalLabel' aria-hidden='true'>
                        <div class='modal-dialog modal-dialog-centered'>
                          <div class='modal-content border-0 shadow-lg'>
                            <div class='modal-header bg-danger text-white py-3'>
                              <h5 class='modal-title fw-bold mb-0' id='errorDetailModalLabel'>
                                <i class='bi bi-exclamation-triangle-fill me-2'></i>Error Details
                              </h5>
                              <button type='button' class='btn-close btn-close-white' data-bs-dismiss='modal' aria-label='Close'></button>
                            </div>
                            <div class='modal-body p-4'>
                              <div class='alert alert-danger font-monospace small mb-3 text-break me-0'>
                                " . htmlspecialchars($msg) . "
                              </div>
                              <p class='text-muted small mb-2'>Exact error response received from system:</p>
                              <textarea id='errorModalCopyText' class='form-control form-control-sm font-monospace bg-light text-dark mb-2' rows='4' readonly>" . htmlspecialchars($msg) . "</textarea>
                            </div>
                            <div class='modal-footer bg-light py-2'>
                              <button type='button' class='btn btn-outline-secondary btn-sm' onclick='copyErrorModalMessage()'>
                                <i class='bi bi-clipboard me-1'></i>Copy Error
                              </button>
                              <button type='button' class='btn btn-danger btn-sm' data-bs-dismiss='modal'>Close</button>
                            </div>
                          </div>
                        </div>
                      </div>
                      <script>
                        document.addEventListener('DOMContentLoaded', function() {
                          var modalEl = document.getElementById('errorDetailModal');
                          if (modalEl && typeof bootstrap !== 'undefined') {
                            var bsModal = new bootstrap.Modal(modalEl);
                            bsModal.show();
                          }
                        });
                        function copyErrorModalMessage() {
                          var txt = document.getElementById('errorModalCopyText');
                          if (txt) {
                            txt.select();
                            navigator.clipboard.writeText(txt.value);
                            alert('Error text copied to clipboard!');
                          }
                        }
                      </script>";
            } else {
                $cls = ['success'=>'success','danger'=>'danger','warning'=>'warning','info'=>'info'][$type] ?? 'secondary';
                echo "<div class='alert alert-{$cls} alert-dismissible fade show' role='alert'>
                        <i class='bi bi-" . ($type==='success'?'check-circle':'exclamation-triangle') . "-fill me-2'></i>"
                        . htmlspecialchars($msg) . "
                        <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                      </div>";
            }
        }
    }
}

// Reliable sequential client code generator (e.g. CLT-0001, CLT-0002)
function generateClientCode(PDO $db): string {
    try {
        $stmt = $db->query("SELECT client_code FROM clients WHERE client_code LIKE 'CLT-%' ORDER BY id DESC LIMIT 100");
        $maxNum = 0;
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (preg_match('/CLT-(\d+)/i', $row['client_code'], $m)) {
                    $num = (int)$m[1];
                    if ($num > $maxNum) {
                        $maxNum = $num;
                    }
                }
            }
        }
        $nextNum = $maxNum + 1;
        
        do {
            $code = 'CLT-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
            $check = $db->prepare("SELECT id FROM clients WHERE client_code = ?");
            $check->execute([$code]);
            if (!$check->fetch()) {
                return $code;
            }
            $nextNum++;
        } while (true);
    } catch (\Throwable $e) {
        return 'CLT-' . str_pad(time() % 10000, 4, '0', STR_PAD_LEFT);
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

// Validate that a URL is a safe local relative or base URL (prevents open redirects)
function safeLocalUrl(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    // Reject scheme-relative (//example.com) or external schemes (http://, https://, javascript:)
    if (str_starts_with($url, '//') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        if (defined('BASE_URL') && BASE_URL !== '' && str_starts_with($url, BASE_URL)) {
            return $url;
        }
        return null;
    }
    if (str_starts_with($url, '/')) {
        return $url;
    }
    return null;
}

// Safe redirect with automatic return_url preservation
function redirect(string $url): void {
    $returnUrl = $_POST['return_url'] ?? $_GET['return_url'] ?? '';
    if (!empty($returnUrl)) {
        $safe = safeLocalUrl((string)$returnUrl);
        if ($safe !== null) {
            header('Location: ' . $safe);
            exit;
        }
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Return current page request URI for use in forms as return_url
 */
function getReturnUrl(string $fallback = ''): string {
    return $_SERVER['REQUEST_URI'] ?? $fallback;
}

/**
 * Persist and restore page filters in session across edits, reloads, and navigation.
 *
 * @param string $moduleKey    Unique identifier for the module (e.g. 'leads', 'invoices', 'clients')
 * @param array  $filterKeys   List of GET parameter keys that act as filters (e.g. ['status', 'project_id', 'q'])
 */
function restorePageFilters(string $moduleKey, array $filterKeys = []): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // 1. Explicit clear requested (e.g. ?clear=1 or ?clear_filters=1 or ?reset=1)
    if (isset($_GET['clear']) || isset($_GET['clear_filters']) || isset($_GET['reset'])) {
        unset($_SESSION['page_filters'][$moduleKey]);
        unset($_GET['clear'], $_GET['clear_filters'], $_GET['reset']);

        $uriParts = explode('?', $_SERVER['REQUEST_URI'] ?? '');
        $cleanUri = $uriParts[0];

        // Preserve non-filter query parameters if any (e.g. tab=xyz)
        $remaining = [];
        foreach ($_GET as $gk => $gv) {
            if (!in_array($gk, $filterKeys)) {
                $remaining[$gk] = $gv;
            }
        }
        $target = $cleanUri . (!empty($remaining) ? ('?' . http_build_query($remaining)) : '');
        header('Location: ' . $target);
        exit;
    }

    // 2. Check if any filter keys are currently present in $_GET
    $hasGetFilter = false;
    foreach ($filterKeys as $k) {
        if (isset($_GET[$k])) {
            $hasGetFilter = true;
            break;
        }
    }

    if ($hasGetFilter) {
        // User applied or submitted filters in $_GET -> Save to session
        $filtered = [];
        foreach ($filterKeys as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') {
                $filtered[$k] = $_GET[$k];
            }
        }
        if (!empty($filtered)) {
            $_SESSION['page_filters'][$moduleKey] = $filtered;
        } else {
            // User submitted an empty filter form (e.g. cleared inputs and clicked Search/Filter)
            unset($_SESSION['page_filters'][$moduleKey]);
        }
    } elseif (!empty($_SESSION['page_filters'][$moduleKey])) {
        // No filters in $_GET, but session has active filters -> Restore them by redirecting to URL with filters
        $saved = $_SESSION['page_filters'][$moduleKey];
        $uriParts = explode('?', $_SERVER['REQUEST_URI'] ?? '');
        $base = $uriParts[0];
        $merged = array_merge($saved, $_GET);
        $target = $base . '?' . http_build_query($merged);
        header('Location: ' . $target);
        exit;
    }
}

// Role display label
function roleLabel(string $role): string {
    return match($role) {
        'admin'    => '<span class="badge badge-soft-danger"><span class="badge-dot" style="background:#ef4444;"></span>Admin</span>',
        'finance'  => '<span class="badge badge-soft-success"><span class="badge-dot" style="background:#22c55e;"></span>Finance</span>',
        'accounts' => '<span class="badge" style="background:rgba(111,66,193,0.12); color:#6f42c1; border:1px solid rgba(111,66,193,0.2);"><span class="badge-dot" style="background:#6f42c1;"></span>Accounts</span>',
        'telecall' => '<span class="badge badge-soft-primary"><span class="badge-dot" style="background:#6366f1;"></span>Telecall</span>',
        'investor' => '<span class="badge badge-soft-warning"><span class="badge-dot" style="background:#f59e0b;"></span>Investor</span>',
        'csm'      => '<span class="badge badge-soft-info"><span class="badge-dot" style="background:#0ea5e9;"></span>CSM</span>',
        default    => '<span class="badge badge-soft-secondary"><span class="badge-dot" style="background:#64748b;"></span>' . htmlspecialchars($role) . '</span>',
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
        'trial'         => 'info',
        'trial_ended'   => 'secondary',
        'false_lead'    => 'dark',
        'pending'       => 'warning',
        'paid'          => 'success',
        'partial'       => 'info',
        'overdue'       => 'danger',
        'cancelled'     => 'secondary',
        'expired'       => 'danger',
        'suspended'     => 'danger',
    ];
    $cls = $map[$status] ?? 'secondary';
    $label = ucwords(str_replace('_', ' ', $status));
    return "<span class='badge badge-soft-{$cls}'><span class='badge-dot bg-{$cls}'></span>{$label}</span>";
}

function employmentTypeBadge(string $type): string {
    return match($type) {
        'contract'  => '<span class="badge badge-soft-warning"><i class="bi bi-file-earmark-text me-1"></i>Contract</span>',
        'freelance' => '<span class="badge badge-soft-info"><i class="bi bi-laptop me-1"></i>Freelance</span>',
        'intern'    => '<span class="badge badge-soft-secondary"><i class="bi bi-mortarboard me-1"></i>Intern</span>',
        default     => '<span class="badge badge-soft-primary"><i class="bi bi-person-workspace me-1"></i>Full-Time</span>',
    };
}

