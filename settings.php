<?php
require_once 'auth.php';
require_once 'db.php';
require_role(['patient']);

$userId = $_SESSION['user_id'];
$error = null;

// Handle: Notification preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_notifications') {
    verify_csrf();
    $sms   = isset($_POST['sms_notif']) ? 1 : 0;
    $email = isset($_POST['email_notif']) ? 1 : 0;
    $lab   = isset($_POST['lab_notif']) ? 1 : 0;

    $pdo->prepare('UPDATE users SET sms_notif = ?, email_notif = ?, lab_notif = ? WHERE id = ?')
        ->execute([$sms, $email, $lab, $userId]);

    flash('Notification preferences saved', '🔔');
    header('Location: settings.php');
    exit;
}

// Handle: Password change + 2FA toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_security') {
    verify_csrf();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $twoFactor = isset($_POST['two_factor']) ? 1 : 0;

    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    // Password fields are optional on this form as a set — leave ALL THREE
    // blank to change nothing about your password and just save the 2FA
    // toggle. But if you touch any one of them, current_password is always
    // required to confirm the change.
    if ($current !== '' || $new !== '' || $confirm !== '') {
        if ($current === '') {
            $error = 'Enter your current password to confirm a password change.';
        } elseif (!$row || !password_verify($current, $row['password'])) {
            $error = 'Current password is incorrect.';
        } elseif ($new === '' || $confirm === '') {
            $error = 'Enter and confirm your new password.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New password and confirmation do not match.';
        } else {
            $pdo->prepare('UPDATE users SET password = ?, two_factor = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $twoFactor, $userId]);
            flash('Security settings updated', '🔒');
            header('Location: settings.php');
            exit;
        }
    } else {
        $pdo->prepare('UPDATE users SET two_factor = ? WHERE id = ?')->execute([$twoFactor, $userId]);
        flash('Security settings updated', '🔒');
        header('Location: settings.php');
        exit;
    }
}

$stmt = $pdo->prepare('SELECT full_name, sms_notif, email_notif, lab_notif, two_factor FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
$initial = strtoupper(substr($user['full_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Healthcore Patient Portal</title>
    <meta name="description" content="Manage your Healthcore account preferences, security settings, and notifications.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="navbar-layout">
            <a class="navbar-brand-custom" href="index.php">
                <span class="logo-icon">+</span> Healthcore
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon">☰</span>
            </button>

            <div class="collapse navbar-collapse justify-content-center" id="navbarMain">
                <ul class="navbar-nav gap-2">
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="index.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="index.php#appointments-section">Appointments</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="index.php#queue-section">Waiting Status</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="index.php#records-section">Medical Records</a></li>
                </ul>
            </div>

            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-custom p-2 rounded-circle" id="themeToggleBtn" title="Toggle Light/Dark Theme">🌙</button>
                <div class="dropdown">
                    <button class="profile-dropdown-btn dropdown-toggle" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar"><?= htmlspecialchars($initial) ?></span> <?= htmlspecialchars($user['full_name']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-custom" aria-labelledby="profileDropdown">
                        <li><a class="dropdown-item" href="profile.php">👤 My Profile</a></li>
                        <li><a class="dropdown-item active" href="settings.php">⚙️ Account Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php">🚪 Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-wrapper">
        <h2 class="manrope-800 mb-1">Account & System Settings</h2>
        <p class="text-muted mb-4">Customize your patient experience, communication preferences, and security options.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-8">

                <!-- Notification Settings -->
                <div class="card-custom mb-4">
                    <div class="card-header-custom">
                        <span>Notification Preferences</span>
                    </div>
                    <div class="card-body-custom">
                        <form method="POST" action="settings.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_notifications">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="sms_notif" id="smsNotif" <?= $user['sms_notif'] ? 'checked' : '' ?>>
                                <label class="form-check-label manrope-600" for="smsNotif">SMS Queue Position Alerts</label>
                                <small class="text-muted d-block">Receive instant text updates when your queue ticket is called.</small>
                            </div>
                            <hr>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="email_notif" id="emailNotif" <?= $user['email_notif'] ? 'checked' : '' ?>>
                                <label class="form-check-label manrope-600" for="emailNotif">Email Appointment Reminders</label>
                                <small class="text-muted d-block">Receive email alerts 24 hours and 2 hours before scheduled appointments.</small>
                            </div>
                            <hr>
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="lab_notif" id="labNotif" <?= $user['lab_notif'] ? 'checked' : '' ?>>
                                <label class="form-check-label manrope-600" for="labNotif">Lab Result Alerts</label>
                                <small class="text-muted d-block">Get notified immediately when new blood work or diagnostic reports are uploaded.</small>
                            </div>

                            <button type="submit" class="btn btn-primary-custom btn-sm mt-2">Save Preferences</button>
                        </form>
                    </div>
                </div>

                <!-- Password & Security -->
                <div class="card-custom mb-4">
                    <div class="card-header-custom">
                        <span>Security & Password</span>
                    </div>
                    <div class="card-body-custom">
                        <form method="POST" action="settings.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_security">
                            <div class="mb-3">
                                <label class="form-label manrope-600">Current Password</label>
                                <input type="password" name="current_password" class="form-control form-control-custom" placeholder="Required if setting a new password" autocomplete="current-password">
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label manrope-600">New Password</label>
                                    <input type="password" name="new_password" class="form-control form-control-custom" placeholder="Minimum 8 characters" autocomplete="new-password">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label manrope-600">Confirm New Password</label>
                                    <input type="password" name="confirm_password" class="form-control form-control-custom" placeholder="Re-enter new password" autocomplete="new-password">
                                </div>
                            </div>
                            <p class="text-muted small mb-3">Leave all three password fields blank to save only the 2FA setting below without changing your password.</p>

                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" name="two_factor" id="twoFactorAuth" <?= $user['two_factor'] ? 'checked' : '' ?>>
                                <label class="form-check-label manrope-600" for="twoFactorAuth">Enable Two-Factor Authentication (2FA)</label>
                                <small class="text-muted d-block">Require an SMS code when logging into your Healthcore account.</small>
                            </div>

                            <button type="submit" class="btn btn-primary-custom btn-sm">Update Security</button>
                        </form>
                    </div>
                </div>

                <!-- Theme & Visuals -->
                <div class="card-custom">
                    <div class="card-header-custom">
                        <span>Appearance & Display</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="mb-3">
                            <label class="form-label manrope-600">Theme Mode</label>
                            <div class="d-flex gap-3">
                                <button type="button" class="btn btn-outline-custom flex-grow-1" id="setLightThemeBtn">
                                    ☀️ Light Theme
                                </button>
                                <button type="button" class="btn btn-outline-custom flex-grow-1" id="setDarkThemeBtn">
                                    🌙 Dark Theme
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Side Card: Security Summary -->
            <div class="col-lg-4">
                <div class="card-custom">
                    <div class="card-header-custom">
                        <span>Security Summary</span>
                    </div>
                    <div class="card-body-custom">
                        <div class="alert alert-success py-2 small mb-3">
                            🔒 Account Protected with SSL Encryption
                        </div>
                        <ul class="list-unstyled text-muted small">
                            <li class="mb-2"><?= $user['two_factor'] ? '✔️ Two-Factor Authentication: On' : '⚠️ Two-Factor Authentication: Off' ?></li>
                            <li class="mb-2">✔️ Passwords stored using one-way hashing</li>
                            <li class="mb-2">✔️ HIPAA-style Health Portal Practices</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Toast Notifications -->
    <div class="toast-container-custom" id="toastContainer"></div>

    <!-- Footer -->
    <footer>
        <div class="container">
            <p class="mb-1">© 2026 Healthcore Hospital Management System. All rights reserved.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script.js"></script>
    <?php render_flash_script(); ?>
</body>

</html>
