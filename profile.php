<?php
require_once 'auth.php';
require_once 'db.php';
require_role(['patient']);

$userId = $_SESSION['user_id'];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    verify_csrf();
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $address    = trim($_POST['address'] ?? '');
    $emName     = trim($_POST['emergency_name'] ?? '');
    $emPhone    = trim($_POST['emergency_phone'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Please enter a valid email address.', '⚠️');
        header('Location: profile.php');
        exit;
    }

    $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $dup->execute([$email, $userId]);
    if ($dup->fetch()) {
        flash('That email address is already in use by another account.', '⚠️');
        header('Location: profile.php');
        exit;
    }

    try {
        $pdo->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?')
            ->execute([$name, $email, $phone, $userId]);
        $pdo->prepare('UPDATE patients SET address = ?, emergency_name = ?, emergency_phone = ? WHERE user_id = ?')
            ->execute([$address, $emName, $emPhone, $userId]);

        $_SESSION['full_name'] = $name;
        flash('Profile information updated', '👤');
    } catch (PDOException $e) {
        flash('Could not update your profile. Please try again.', '⚠️');
    }
    header('Location: profile.php');
    exit;
}

// Fetch patient + user info
$stmt = $pdo->prepare('SELECT u.full_name, u.email, u.phone, p.* FROM patients p JOIN users u ON u.id = p.user_id WHERE p.user_id = ?');
$stmt->execute([$userId]);
$patient = $stmt->fetch();

if (!$patient) {
    die('No patient profile found for this account.');
}
$patientId = $patient['id'];

// Primary physician
$doctorName = 'Not assigned';
if ($patient['primary_doctor_id']) {
    $d = $pdo->prepare('SELECT u.full_name, d.department FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.id = ?');
    $d->execute([$patient['primary_doctor_id']]);
    $doc = $d->fetch();
    if ($doc) {
        $doctorName = 'Dr. ' . str_replace('Dr. ', '', $doc['full_name']) . ' (' . $doc['department'] . ')';
    }
}

$allergies = $pdo->prepare('SELECT * FROM allergies WHERE patient_id = ?');
$allergies->execute([$patientId]);
$allergies = $allergies->fetchAll();

$conditions = $pdo->prepare('SELECT * FROM conditions WHERE patient_id = ?');
$conditions->execute([$patientId]);
$conditions = $conditions->fetchAll();

$initial = strtoupper(substr($patient['full_name'], 0, 1));
$initials2 = strtoupper(substr($patient['full_name'], 0, 2));
$age = $patient['dob'] ? (new DateTime())->diff(new DateTime($patient['dob']))->y : '—';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Healthcore Patient Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top">
        <div class="navbar-layout">
            <a class="navbar-brand-custom" href="index.php"><span class="logo-icon">+</span> Healthcore</a>

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
                        <span class="user-avatar"><?= htmlspecialchars($initial) ?></span> <?= htmlspecialchars($patient['full_name']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-custom" aria-labelledby="profileDropdown">
                        <li><a class="dropdown-item active" href="profile.php">👤 My Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php">⚙️ Account Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php">🚪 Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <main class="main-wrapper">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="manrope-800 mb-1">Patient Profile</h2>
                <p class="text-muted mb-0">Manage your personal demographics, health vitals, and emergency contacts.</p>
            </div>
            <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#editProfileModal">✏️ Edit Profile</button>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card-custom text-center p-4">
                    <div class="mx-auto mb-3" style="width: 100px; height: 100px; background: linear-gradient(135deg, #0284c7, #0d9488); border-radius: 50%; color: white; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 800;">
                        <?= htmlspecialchars($initials2) ?>
                    </div>
                    <h4 class="manrope-700 mb-1"><?= htmlspecialchars($patient['full_name']) ?></h4>
                    <span class="badge bg-primary mb-3">Patient ID: #<?= htmlspecialchars($patient['patient_code']) ?></span>

                    <hr>

                    <div class="row text-start g-3 my-2">
                        <div class="col-6">
                            <small class="text-muted d-block">AGE / GENDER</small>
                            <strong class="manrope-700"><?= $age ?> / <?= htmlspecialchars($patient['gender']) ?></strong>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">BLOOD TYPE</small>
                            <strong class="manrope-700 text-danger"><?= htmlspecialchars($patient['blood_type'] ?: '—') ?></strong>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">HEIGHT</small>
                            <strong class="manrope-700"><?= (int) $patient['height_cm'] ?: '—' ?> cm</strong>
                        </div>
                        <div class="col-6">
                            <small class="text-muted d-block">WEIGHT</small>
                            <strong class="manrope-700"><?= (int) $patient['weight_kg'] ?: '—' ?> kg</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card-custom mb-4">
                    <div class="card-header-custom">
                        <span>Personal Information</span>
                        <small class="text-muted">Verified Status</small>
                    </div>
                    <div class="card-body-custom">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="text-muted small d-block">Email Address</label>
                                <strong><?= htmlspecialchars($patient['email']) ?></strong>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small d-block">Phone Number</label>
                                <strong><?= htmlspecialchars($patient['phone'] ?: '—') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small d-block">Residential Address</label>
                                <strong><?= htmlspecialchars($patient['address'] ?: '—') ?></strong>
                            </div>
                            <div class="col-md-6">
                                <label class="text-muted small d-block">Primary Physician</label>
                                <strong><?= htmlspecialchars($doctorName) ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-custom mb-4">
                    <div class="card-header-custom"><span>Emergency Contact & Insurance</span></div>
                    <div class="card-body-custom">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <h6 class="manrope-700 text-primary">Emergency Contact</h6>
                                <p class="mb-1"><strong><?= htmlspecialchars($patient['emergency_name'] ?: '—') ?></strong></p>
                                <p class="text-muted mb-0">Phone: <?= htmlspecialchars($patient['emergency_phone'] ?: '—') ?></p>
                            </div>
                            <div class="col-md-6 border-start">
                                <h6 class="manrope-700 text-primary">Medical Insurance</h6>
                                <p class="mb-1"><strong><?= htmlspecialchars($patient['insurance_provider'] ?: '—') ?></strong></p>
                                <p class="text-muted mb-0">Policy #: <?= htmlspecialchars($patient['insurance_policy_no'] ?: '—') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card-custom">
                    <div class="card-header-custom"><span>Allergies & Medical Conditions</span></div>
                    <div class="card-body-custom">
                        <div class="mb-3">
                            <label class="text-muted small d-block mb-1">Known Allergies</label>
                            <div>
                                <?php if (!$allergies): ?><span class="text-muted small">None recorded</span><?php endif; ?>
                                <?php foreach ($allergies as $a):
                                    $badge = $a['severity'] === 'Severe' ? 'bg-danger' : ($a['severity'] === 'Moderate' ? 'bg-info text-dark' : 'bg-warning text-dark'); ?>
                                    <span class="badge <?= $badge ?> me-2 p-2"><?= htmlspecialchars($a['name']) ?> (<?= htmlspecialchars($a['severity']) ?>)</span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <label class="text-muted small d-block mb-1">Pre-existing Conditions</label>
                            <div>
                                <?php if (!$conditions): ?><span class="text-muted small">None recorded</span><?php endif; ?>
                                <?php foreach ($conditions as $c): ?>
                                    <span class="badge bg-secondary me-2 p-2"><?= htmlspecialchars($c['name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- MODAL: Edit Profile -->
    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Edit Personal Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="profile.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label manrope-600">Full Name</label>
                            <input type="text" name="name" class="form-control form-control-custom" value="<?= htmlspecialchars($patient['full_name']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Email Address</label>
                            <input type="email" name="email" class="form-control form-control-custom" value="<?= htmlspecialchars($patient['email']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Phone Number</label>
                            <input type="tel" name="phone" class="form-control form-control-custom" value="<?= htmlspecialchars($patient['phone']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Residential Address</label>
                            <input type="text" name="address" class="form-control form-control-custom" value="<?= htmlspecialchars($patient['address']) ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Emergency Contact Name & Phone</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="text" name="emergency_name" class="form-control form-control-custom" value="<?= htmlspecialchars($patient['emergency_name']) ?>" required>
                                </div>
                                <div class="col-6">
                                    <input type="text" name="emergency_phone" class="form-control form-control-custom" value="<?= htmlspecialchars($patient['emergency_phone']) ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer modal-footer-custom">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="toast-container-custom" id="toastContainer"></div>

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
