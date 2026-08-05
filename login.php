<?php
require_once 'auth.php';
require_once 'db.php';

// If already logged in, skip straight to the right dashboard.
if (is_logged_in()) {
    header('Location: ' . (current_role() === 'patient' ? 'index.php' : 'admin.php'));
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];

            flash('Welcome back, ' . explode(' ', $user['full_name'])[0] . '!', '👋');
            header('Location: ' . ($user['role'] === 'patient' ? 'index.php' : 'admin.php'));
            exit;
        } else {
            $error = 'Incorrect email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Healthcore Patient Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <main class="main-wrapper d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <div class="card-custom p-4" style="max-width:420px; width:100%;">
            <div class="text-center mb-4">
                <a class="navbar-brand-custom" href="login.php"><span class="logo-icon">+</span> Healthcore</a>
                <p class="text-muted mb-0">Sign in to your patient or staff account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label manrope-600">Email Address</label>
                    <input type="email" name="email" class="form-control form-control-custom"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label manrope-600">Password</label>
                    <div class="position-relative">
                        <input type="password" name="password" id="loginPassword" class="form-control form-control-custom pe-5" required>
                        <button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y me-1 p-1 border-0 bg-transparent" onclick="togglePasswordVisibility('loginPassword', this)" tabindex="-1" title="Show password">👁️</button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary-custom w-100">Log In</button>
            </form>

            <p class="text-center text-muted small mt-3 mb-0">
                Don't have an account? <a href="register.php">Register NOW</a>
            </p>
        </div>
    </main>

    <script>
        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            btn.textContent = isHidden ? '🙈' : '👁️';
            btn.title = isHidden ? 'Hide password' : 'Show password';
        }
    </script>
</body>

</html>
