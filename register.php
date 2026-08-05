<?php
require_once 'auth.php';
require_once 'db.php';

if (is_logged_in()) {
    header('Location: ' . (current_role() === 'patient' ? 'index.php' : 'admin.php'));
    exit;
}

const DEPARTMENTS = [
    'Cardiology', 'Neurology', 'Orthopedics', 'Pediatrics',
    'General Medicine', 'Dermatology', 'ENT', 'Psychiatry',
    'Radiology', 'Oncology',
];

$error = null;
$role  = ($_POST['role'] ?? 'patient') === 'doctor' ? 'doctor' : 'patient';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    // Fields only relevant to doctor accounts
    $department = trim($_POST['department'] ?? '');
    $inviteCode = $_POST['invite_code'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Name, email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($role === 'doctor' && !in_array($department, DEPARTMENTS, true)) {
        $error = 'Please choose a valid department.';
    } elseif ($role === 'doctor' && !hash_equals(DOCTOR_INVITE_CODE, $inviteCode)) {
        $error = 'Invalid staff invite code. Ask your hospital administrator for the current code.';
    } else {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare('INSERT INTO users (role, full_name, email, password, phone) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$role, $name, $email, password_hash($password, PASSWORD_DEFAULT), $phone]);
                $userId = $pdo->lastInsertId();

                if ($role === 'doctor') {
                    $doctorCode = 'DR-' . random_int(10000, 99999);
                    $stmt = $pdo->prepare('INSERT INTO doctors (user_id, doctor_code, department) VALUES (?, ?, ?)');
                    $stmt->execute([$userId, $doctorCode, $department]);
                } else {
                    $patientCode = 'HC-' . random_int(10000, 99999);
                    $stmt = $pdo->prepare('INSERT INTO patients (user_id, patient_code) VALUES (?, ?)');
                    $stmt->execute([$userId, $patientCode]);
                }

                $pdo->commit();

                flash('Account created! You can now log in.', '🎉');
                header('Location: login.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Something went wrong creating your account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Healthcore Patient Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <main class="main-wrapper d-flex align-items-center justify-content-center" style="min-height:100vh;">
        <div class="card-custom p-4" style="max-width:480px; width:100%;">
            <div class="text-center mb-4">
                <a class="navbar-brand-custom" href="login.php"><span class="logo-icon">+</span> Healthcore</a>
                <p class="text-muted mb-0">Create your Healthcore account</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- Account type toggle -->
            <div class="btn-group w-100 mb-4" role="group" aria-label="Account type">
                <input type="radio" class="btn-check" name="roleToggle" id="roleToggle_patient" autocomplete="off" <?= $role === 'patient' ? 'checked' : '' ?>>
                <label class="btn btn-outline-custom" for="roleToggle_patient">🧑‍⚕️ Patient</label>

                <input type="radio" class="btn-check" name="roleToggle" id="roleToggle_doctor" autocomplete="off" <?= $role === 'doctor' ? 'checked' : '' ?>>
                <label class="btn btn-outline-custom" for="roleToggle_doctor">👨‍⚕️ Medical Staff</label>
            </div>

            <form method="POST" action="register.php" id="registerForm">
                <?= csrf_field() ?>
                <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($role) ?>">

                <div class="mb-3">
                    <label class="form-label manrope-600">Full Name</label>
                    <input type="text" name="name" class="form-control form-control-custom"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label manrope-600">Email Address</label>
                    <input type="email" name="email" class="form-control form-control-custom"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label manrope-600">Phone Number</label>
                    <input type="tel" name="phone" class="form-control form-control-custom"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                </div>

                <!-- Doctor-only fields -->
                <div id="doctorFields" class="border rounded p-3 mb-3" style="display:none;">
                    <p class="small text-muted mb-3">Medical staff accounts get access to the admin portal (patient records, queue control, appointments) — a staff invite code is required.</p>
                    <div class="mb-3">
                        <label class="form-label manrope-600">Department</label>
                        <select name="department" id="departmentSelect" class="form-select form-select-custom">
                            <option value="">Choose a department...</option>
                            <?php foreach (DEPARTMENTS as $dept): ?>
                                <option value="<?= htmlspecialchars($dept) ?>" <?= ($_POST['department'] ?? '') === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label manrope-600">Staff Invite Code</label>
                        <input type="text" name="invite_code" id="inviteCodeInput" class="form-control form-control-custom" placeholder="Provided by your hospital administrator">
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label manrope-600">Password</label>
                        <div class="position-relative">
                            <input type="password" name="password" id="regPassword" class="form-control form-control-custom pe-5" required>
                            <button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y me-1 p-1 border-0 bg-transparent" onclick="togglePasswordVisibility('regPassword', this)" tabindex="-1" title="Show password">👁️</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label manrope-600">Confirm Password</label>
                        <div class="position-relative">
                            <input type="password" name="confirm" id="regConfirm" class="form-control form-control-custom pe-5" required>
                            <button type="button" class="btn btn-sm position-absolute top-50 end-0 translate-middle-y me-1 p-1 border-0 bg-transparent" onclick="togglePasswordVisibility('regConfirm', this)" tabindex="-1" title="Show password">👁️</button>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary-custom w-100">Create Account</button>
            </form>

            <p class="text-center text-muted small mt-3 mb-0">
                Already have an account? <a href="login.php">Log in</a>
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

        // Toggle between Patient / Medical Staff field sets
        const patientRadio   = document.getElementById('roleToggle_patient');
        const doctorRadio    = document.getElementById('roleToggle_doctor');
        const doctorFields   = document.getElementById('doctorFields');
        const roleInput      = document.getElementById('roleInput');
        const departmentSel  = document.getElementById('departmentSelect');
        const inviteCodeIn   = document.getElementById('inviteCodeInput');

        function applyRoleView() {
            const isDoctor = doctorRadio.checked;
            doctorFields.style.display = isDoctor ? 'block' : 'none';
            roleInput.value = isDoctor ? 'doctor' : 'patient';
            // Only require doctor-only fields when that panel is visible,
            // so the hidden inputs don't block submitting as a patient.
            departmentSel.required = isDoctor;
            inviteCodeIn.required = isDoctor;
        }

        patientRadio.addEventListener('change', applyRoleView);
        doctorRadio.addEventListener('change', applyRoleView);
        applyRoleView();
    </script>
</body>

</html>
