<?php
// ============================================================
// Session + role-guard helpers. include_once this at the very
// top of any page that needs to know who is logged in.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie before starting the session.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true, // uncomment once served over HTTPS
    ]);
    session_start();
}

// ------------------------------------------------------------
// CSRF protection. Every state-changing <form> should include
// csrf_field(), and every POST handler should call verify_csrf()
// before doing anything with $_POST.
// ------------------------------------------------------------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
}

// Call at the top of any POST handler. Kills the request with a 403
// if the token is missing/wrong (e.g. forged cross-site submission).
function verify_csrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(403);
        die('Security check failed (invalid or expired form token). Please go back, refresh the page, and try again.');
    }
}

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function current_role(): ?string {
    return $_SESSION['role'] ?? null;
}

// Redirect to login if nobody is logged in.
function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// Redirect to login if nobody is logged in, OR to their own
// dashboard if they're logged in but not one of the allowed roles.
// Usage: require_role(['admin','doctor']);
function require_role(array $allowedRoles): void {
    require_login();
    if (!in_array(current_role(), $allowedRoles, true)) {
        header('Location: ' . (current_role() === 'patient' ? 'index.php' : 'admin.php'));
        exit;
    }
}

// One-time "toast" message stored in the session and shown as a
// JS toast on the very next page load (used after redirects).
function flash(string $message, string $icon = 'ℹ️'): void {
    $_SESSION['flash'] = ['msg' => $message, 'icon' => $icon];
}

function render_flash_script(): void {
    if (!empty($_SESSION['flash'])) {
        $msg  = htmlspecialchars($_SESSION['flash']['msg'], ENT_QUOTES);
        $icon = htmlspecialchars($_SESSION['flash']['icon'], ENT_QUOTES);
        echo "<script>document.addEventListener('DOMContentLoaded',()=>{ if(typeof showToast==='function'){ showToast(\"{$msg}\", \"{$icon}\"); } });</script>";
        unset($_SESSION['flash']);
    }
}
