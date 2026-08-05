<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'queue_helpers.php';
require_role(['admin', 'doctor']);

// -------------------- Department scoping --------------------
// Admins see the whole hospital. Doctors only see/manage patients,
// appointments and records tied to their own department — "tied to"
// meaning: the patient's primary physician is in that department, OR
// they have an appointment with a doctor in that department, OR they
// already have a record from a doctor in that department.
$isDoctor      = current_role() === 'doctor';
$myDoctorId    = null;
$myDepartment  = null;

if ($isDoctor) {
    $meStmt = $pdo->prepare('SELECT id, department FROM doctors WHERE user_id = ?');
    $meStmt->execute([$_SESSION['user_id']]);
    $me = $meStmt->fetch();
    if (!$me) {
        die('No doctor profile found for this account.');
    }
    $myDoctorId   = (int) $me['id'];
    $myDepartment = $me['department'];
}

// True if $doctorId belongs to $department.
function doctor_in_department(PDO $pdo, int $doctorId, string $department): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM doctors WHERE id = ? AND department = ?');
    $stmt->execute([$doctorId, $department]);
    return (bool) $stmt->fetch();
}

// True if $patientId is associated with $department via primary
// physician, an appointment, or an existing record.
function patient_in_department(PDO $pdo, int $patientId, string $department): bool {
    $stmt = $pdo->prepare(
        'SELECT 1 FROM patients p
         LEFT JOIN doctors pd ON pd.id = p.primary_doctor_id
         WHERE p.id = ? AND (
             pd.department = ?
             OR EXISTS (SELECT 1 FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.patient_id = p.id AND d.department = ?)
             OR EXISTS (SELECT 1 FROM medical_records mr JOIN doctors d2 ON d2.id = mr.doctor_id WHERE mr.patient_id = p.id AND d2.department = ?)
         ) LIMIT 1'
    );
    $stmt->execute([$patientId, $department, $department, $department]);
    return (bool) $stmt->fetch();
}

// True if medical record $recordId was logged by a doctor in $department.
function record_in_department(PDO $pdo, int $recordId, string $department): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM medical_records mr JOIN doctors d ON d.id = mr.doctor_id WHERE mr.id = ? AND d.department = ?');
    $stmt->execute([$recordId, $department]);
    return (bool) $stmt->fetch();
}

// -------------------- Handle admin actions (PRG pattern) --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    // Which department's queue an action targets. A doctor can only ever
    // act on their own department — the POSTed value is ignored for them
    // rather than trusted, so editing the hidden form field can't be used
    // to call/reset another department's queue. An admin's POSTed value
    // is validated against active_departments() so it has to be a real one.
    $targetDepartment = trim($_POST['department'] ?? '');
    if ($isDoctor) {
        $targetDepartment = $myDepartment;
    } elseif (!in_array($targetDepartment, active_departments($pdo), true)) {
        $targetDepartment = null;
    }

    if ($action === 'call_next') {
        if (!$targetDepartment) {
            flash('Unknown department — nothing to call.', '⚠️');
        } else {
            // Real guard: don't advance the queue if nobody is actually
            // waiting in THIS department. Checked server-side (not just a
            // disabled button) so it can't be bypassed by resubmitting
            // the form directly.
            $stillWaiting = department_waiting_count($pdo, $targetDepartment);
            if ($stillWaiting > 0) {
                get_or_create_queue($pdo, $targetDepartment); // ensure the row exists
                $pdo->prepare('UPDATE queue_state SET currently_serving = currently_serving + 1 WHERE department = ?')->execute([$targetDepartment]);
                $row = get_or_create_queue($pdo, $targetDepartment);
                flash($targetDepartment . ': Called Ticket #' . $row['currently_serving'] . ' to the active station!', '🔊');
            } else {
                flash('No patients waiting in ' . $targetDepartment . ' — nothing to call.', '⚠️');
            }
        }
    }

    if ($action === 'reset_queue') {
        if (!$targetDepartment) {
            flash('Unknown department — nothing to reset.', '⚠️');
        } else {
            get_or_create_queue($pdo, $targetDepartment); // ensure the row exists
            $pdo->prepare('UPDATE queue_state SET currently_serving = 1 WHERE department = ?')->execute([$targetDepartment]);
            flash($targetDepartment . ' queue ticket counter reset to #1', '🔄');
        }
    }

    if ($action === 'complete_appointment') {
        $id = (int) ($_POST['appt_id'] ?? 0);
        $allowed = true;
        if ($isDoctor) {
            $chk = $pdo->prepare('SELECT 1 FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.id = ? AND d.department = ?');
            $chk->execute([$id, $myDepartment]);
            $allowed = (bool) $chk->fetch();
        }
        if ($allowed) {
            $pdo->prepare('UPDATE appointments SET status = "Completed" WHERE id = ?')->execute([$id]);
            flash('Consultation marked as completed', '✅');
        } else {
            flash('You can only manage appointments within your own department.', '⚠️');
        }
    }

    if ($action === 'add_record') {
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $doctorId  = (int) ($_POST['doctor_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');

        if ($isDoctor && (!doctor_in_department($pdo, $doctorId, $myDepartment) || ($patientId && !patient_in_department($pdo, $patientId, $myDepartment)))) {
            flash('You can only log records for physicians and patients in your own department.', '⚠️');
        } elseif ($patientId && $doctorId && $title) {
            $pdo->prepare('INSERT INTO medical_records (patient_id, doctor_id, title, notes, record_date, status) VALUES (?, ?, ?, ?, CURDATE(), "Under Review")')
                ->execute([$patientId, $doctorId, $title, $notes]);
            flash('New EMR medical record published to patient portal!', '📑');
        } else {
            flash('Please fill in patient, physician and record title.', '⚠️');
        }
    }

    if ($action === 'verify_record') {
        $id = (int) ($_POST['record_id'] ?? 0);
        if ($isDoctor && !record_in_department($pdo, $id, $myDepartment)) {
            flash('You can only manage records from your own department.', '⚠️');
        } else {
            $pdo->prepare('UPDATE medical_records SET status = "Verified" WHERE id = ?')->execute([$id]);
            flash('Record marked as Verified', '✔️');
        }
    }

    if ($action === 'edit_record') {
        $id        = (int) ($_POST['record_id'] ?? 0);
        $patientId = (int) ($_POST['patient_id'] ?? 0);
        $doctorId  = (int) ($_POST['doctor_id'] ?? 0);
        $title     = trim($_POST['title'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $recDate   = trim($_POST['record_date'] ?? '');

        if ($isDoctor && (!record_in_department($pdo, $id, $myDepartment) || !doctor_in_department($pdo, $doctorId, $myDepartment) || !patient_in_department($pdo, $patientId, $myDepartment))) {
            flash('You can only manage records within your own department.', '⚠️');
        } elseif ($id && $patientId && $doctorId && $title && $recDate) {
            $pdo->prepare('UPDATE medical_records SET patient_id = ?, doctor_id = ?, title = ?, notes = ?, record_date = ? WHERE id = ?')
                ->execute([$patientId, $doctorId, $title, $notes, $recDate, $id]);
            flash('Medical record updated', '📝');
        } else {
            flash('Please fill in every field to update the record.', '⚠️');
        }
    }

    if ($action === 'delete_record') {
        $id = (int) ($_POST['record_id'] ?? 0);
        if ($isDoctor && !record_in_department($pdo, $id, $myDepartment)) {
            flash('You can only manage records from your own department.', '⚠️');
        } else {
            $pdo->prepare('DELETE FROM medical_records WHERE id = ?')->execute([$id]);
            flash('Medical record deleted', '🗑️');
        }
    }

    header('Location: admin.php');
    exit;
}

// -------------------- Data for the page --------------------
if ($isDoctor) {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS c FROM (
            SELECT DISTINCT p.id FROM patients p
            LEFT JOIN doctors pd ON pd.id = p.primary_doctor_id
            LEFT JOIN appointments a ON a.patient_id = p.id
            LEFT JOIN doctors ad ON ad.id = a.doctor_id
            LEFT JOIN medical_records mr ON mr.patient_id = p.id
            LEFT JOIN doctors rd ON rd.id = mr.doctor_id
            WHERE pd.department = ? OR ad.department = ? OR rd.department = ?
        ) dp'
    );
    $stmt->execute([$myDepartment, $myDepartment, $myDepartment]);
    $totalPatients = $stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM appointments a JOIN doctors d ON d.id = a.doctor_id WHERE a.appt_date = CURDATE() AND a.status != 'Cancelled' AND d.department = ?");
    $stmt->execute([$myDepartment]);
    $todaysApptCount = $stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM medical_records mr JOIN doctors d ON d.id = mr.doctor_id WHERE mr.status = 'Under Review' AND d.department = ?");
    $stmt->execute([$myDepartment]);
    $pendingReports = $stmt->fetch()['c'];
} else {
    $totalPatients   = $pdo->query('SELECT COUNT(*) AS c FROM patients')->fetch()['c'];
    $todaysApptCount = $pdo->query("SELECT COUNT(*) AS c FROM appointments WHERE appt_date = CURDATE() AND status != 'Cancelled'")->fetch()['c'];
    $pendingReports  = $pdo->query("SELECT COUNT(*) AS c FROM medical_records WHERE status = 'Under Review'")->fetch()['c'];
}

// -------------------- Per-department queues --------------------
// A doctor only ever manages their own department's queue. An admin
// sees one card per department that currently has a doctor assigned.
$queueDepartments = $isDoctor ? [$myDepartment] : active_departments($pdo);

$departmentQueues = [];
$totalWaitingAllDepts = 0;
foreach ($queueDepartments as $dept) {
    $q = get_or_create_queue($pdo, $dept);
    $serving  = (int) $q['currently_serving'];
    // Scoped to today's tickets for THIS department only, so the queue
    // math doesn't drift upward forever and departments don't share
    // each other's ticket counters.
    $maxTicket = department_max_ticket($pdo, $dept);
    // Real count of today's appointments in this department still
    // pending service — NOT maxTicket - serving, which would also count
    // tickets that were later cancelled or completed and could sit on a
    // stale, inflated number that never reflects reality.
    $waitingHere = department_waiting_count($pdo, $dept);
    $departmentQueues[] = [
        'department'  => $dept,
        'slug'        => department_slug($dept),
        'serving'     => $serving,
        'maxTicket'   => $maxTicket,
        'waiting'     => $waitingHere,
        'progressPct' => $maxTicket > 0 ? min(100, round(($serving / $maxTicket) * 100)) : 0,
    ];
    $totalWaitingAllDepts += $waitingHere;
}

$recordsSql = "SELECT mr.*, p.patient_code, u1.full_name AS patient_name, u2.full_name AS doctor_name
                FROM medical_records mr
                JOIN patients p ON p.id = mr.patient_id
                JOIN users u1 ON u1.id = p.user_id
                JOIN doctors d ON d.id = mr.doctor_id
                JOIN users u2 ON u2.id = d.user_id";
$recordsParams = [];
if ($isDoctor) {
    $recordsSql .= ' WHERE d.department = ?';
    $recordsParams[] = $myDepartment;
}
$recordsSql .= ' ORDER BY mr.record_date DESC LIMIT 25';
$stmt = $pdo->prepare($recordsSql);
$stmt->execute($recordsParams);
$records = $stmt->fetchAll();

$apptSql = "SELECT a.*, u1.full_name AS patient_name, p.patient_code, u2.full_name AS doctor_name
            FROM appointments a
            JOIN patients p ON p.id = a.patient_id
            JOIN users u1 ON u1.id = p.user_id
            JOIN doctors d ON d.id = a.doctor_id
            JOIN users u2 ON u2.id = d.user_id
            WHERE a.appt_date = CURDATE()";
$apptParams = [];
if ($isDoctor) {
    $apptSql .= ' AND d.department = ?';
    $apptParams[] = $myDepartment;
}
$apptSql .= ' ORDER BY a.appt_time ASC';
$stmt = $pdo->prepare($apptSql);
$stmt->execute($apptParams);
$appointments = $stmt->fetchAll();

if ($isDoctor) {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT p.id, p.patient_code, u.full_name
         FROM patients p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN doctors pd ON pd.id = p.primary_doctor_id
         LEFT JOIN appointments a ON a.patient_id = p.id
         LEFT JOIN doctors ad ON ad.id = a.doctor_id
         LEFT JOIN medical_records mr ON mr.patient_id = p.id
         LEFT JOIN doctors rd ON rd.id = mr.doctor_id
         WHERE pd.department = ? OR ad.department = ? OR rd.department = ?
         ORDER BY u.full_name'
    );
    $stmt->execute([$myDepartment, $myDepartment, $myDepartment]);
    $patientsList = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT d.id, d.department, u.full_name FROM doctors d JOIN users u ON u.id = d.user_id WHERE d.department = ? ORDER BY u.full_name');
    $stmt->execute([$myDepartment]);
    $doctorsList = $stmt->fetchAll();
} else {
    $patientsList = $pdo->query("SELECT p.id, p.patient_code, u.full_name FROM patients p JOIN users u ON u.id = p.user_id ORDER BY u.full_name")->fetchAll();
    $doctorsList  = $pdo->query("SELECT d.id, d.department, u.full_name FROM doctors d JOIN users u ON u.id = d.user_id ORDER BY u.full_name")->fetchAll();
}

function statusBadge(string $status): string {
    return match ($status) {
        'Verified', 'Completed', 'Confirmed' => 'bg-success',
        'Under Review', 'Pending' => 'bg-warning text-dark',
        'Arrived' => 'bg-info text-dark',
        'Cancelled' => 'bg-danger',
        default => 'bg-secondary',
    };
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Admin Portal - Healthcore Hospital Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-custom sticky-top border-bottom border-primary">
        <div class="navbar-layout">
            <a class="navbar-brand-custom text-primary d-flex align-items-center gap-2" href="admin.php">
                <span class="logo-icon bg-primary text-white">+</span>
                <span>Healthcore <span class="admin-badge ms-1">ADMIN</span></span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
                <span class="navbar-toggler-icon">☰</span>
            </button>

            <div class="collapse navbar-collapse justify-content-center" id="adminNavbar">
                <ul class="navbar-nav gap-2">
                    <li class="nav-item"><a class="nav-link nav-link-custom active" href="admin.php">Overview</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#records-management">Patient Records</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#queue-controller">Queue Control</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="#appointments-management">Appointments</a></li>
                </ul>
            </div>

            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-custom p-2 rounded-circle" id="themeToggleBtn" title="Toggle Light/Dark Theme">🌙</button>
                <a href="logout.php" class="btn btn-outline-danger btn-sm rounded-pill px-3">🚪 Logout</a>
                <span class="badge bg-secondary p-2 d-none d-md-inline-block"><?= htmlspecialchars($_SESSION['full_name']) ?></span>
            </div>
        </div>
    </nav>

    <main class="main-wrapper">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
            <div>
                <h2 class="manrope-800 mb-1">Hospital Administration Portal 🩺</h2>
                <?php if ($isDoctor): ?>
                    <span class="badge bg-primary mt-2">🏥 Viewing: <?= htmlspecialchars($myDepartment) ?> Department only</span>
                <?php endif; ?>
            </div>
            <button class="btn btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addRecordModal">+ Log New Medical Record</button>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box">👥</div>
                    <div><small class="text-muted d-block"><?= $isDoctor ? 'Patients in Your Department' : 'Total Registered Patients' ?></small><h4 class="manrope-800 mb-0"><?= (int) $totalPatients ?></h4></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box">📅</div>
                    <div><small class="text-muted d-block">Today's Appointments</small><h4 class="manrope-800 mb-0"><?= (int) $todaysApptCount ?> Scheduled</h4></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box bg-success bg-opacity-10 text-success">🎫</div>
                    <?php if ($isDoctor): ?>
                        <div><small class="text-muted d-block">Currently Serving Ticket</small><h4 class="manrope-800 mb-0 text-success">#<?= $departmentQueues[0]['serving'] ?? 0 ?></h4></div>
                    <?php else: ?>
                        <div><small class="text-muted d-block">Total Waiting (All Depts)</small><h4 class="manrope-800 mb-0 text-success"><?= (int) $totalWaitingAllDepts ?> Patients</h4></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="kpi-card">
                    <div class="kpi-icon-box bg-warning bg-opacity-10 text-warning">📄</div>
                    <div><small class="text-muted d-block">Pending Lab Reports</small><h4 class="manrope-800 mb-0 text-warning"><?= (int) $pendingReports ?> Needs Review</h4></div>
                </div>
            </div>
        </div>

        <!-- Live Queue Controller — one card per department -->
        <section class="card-custom mb-4" id="queue-controller">
            <div class="card-header-custom">
                <div class="d-flex align-items-center gap-2">
                    <span>⚡ Outpatient Live Queue Controller</span>
                    <span class="queue-badge-live"><span class="pulse-dot"></span> <?= count($departmentQueues) ?> Department<?= count($departmentQueues) === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="card-body-custom">
                <?php if (!$departmentQueues): ?>
                    <p class="text-muted text-center py-3 mb-0">No departments have any doctors assigned yet.</p>
                <?php endif; ?>
                <?php foreach ($departmentQueues as $i => $dq): ?>
                    <div class="dept-queue-panel <?= $i > 0 ? 'border-top pt-4 mt-4' : '' ?>"
                         data-department="<?= htmlspecialchars($dq['department']) ?>"
                         data-slug="<?= htmlspecialchars($dq['slug']) ?>">
                        <h6 class="manrope-700 mb-3">🏥 <?= htmlspecialchars($dq['department']) ?> Department</h6>
                        <div class="row align-items-center">
                            <div class="col-lg-4 text-center border-end">
                                <small class="text-muted d-block">CURRENT TICKET CALLING</small>
                                <h1 class="display-2 manrope-800 text-primary my-1 dept-serving-ticket"><?= $dq['serving'] ?></h1>
                                <p class="text-muted small mb-0">Target Room: <?= htmlspecialchars($dq['department']) ?></p>
                            </div>
                            <div class="col-lg-5 p-4">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>Next Ticket in Line: <strong class="text-primary">#<span class="dept-next-ticket"><?= $dq['serving'] + 1 ?></span></strong></span>
                                    <span>Total Waiting: <strong class="text-warning"><span class="dept-waiting-count"><?= $dq['waiting'] ?></span> Patients</strong></span>
                                </div>
                                <div class="progress mb-3" style="height: 12px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-success dept-progress-bar" role="progressbar" style="width: <?= $dq['progressPct'] ?>%"></div>
                                </div>
                            </div>
                            <div class="col-lg-3 text-center p-3">
                                <form method="POST" action="admin.php" class="mb-2">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="call_next">
                                    <input type="hidden" name="department" value="<?= htmlspecialchars($dq['department']) ?>">
                                    <button type="submit" class="btn btn-success w-100 py-3 manrope-700 shadow-sm dept-call-next-btn" <?= $dq['waiting'] === 0 ? 'disabled' : '' ?>>🔊 CALL NEXT TICKET</button>
                                </form>
                                <form method="POST" action="admin.php" onsubmit="return confirm('Reset <?= htmlspecialchars($dq['department']) ?> queue serving counter to #1?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="reset_queue">
                                    <input type="hidden" name="department" value="<?= htmlspecialchars($dq['department']) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">🔄 Reset Queue Ticket</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- EMR Records -->
        <section class="card-custom mb-4" id="records-management">
            <div class="card-header-custom flex-wrap gap-2">
                <div>
                    <h5 class="manrope-700 mb-0">Electronic Medical Records (EMR)</h5>
                    <small class="text-muted">Inspect, verify, and log official medical reports for patients<?= $isDoctor ? ' — ' . htmlspecialchars($myDepartment) . ' Department' : '' ?></small>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <select class="form-select form-select-custom" id="adminStatusFilter" style="max-width:160px;">
                        <option value="">All Status</option>
                        <option value="Verified">Verified</option>
                        <option value="Under Review">Under Review</option>
                    </select>
                    <input type="text" class="form-control form-control-custom table-search-bar" id="adminRecordSearch" placeholder="🔍 Search patient name or ID...">
                    <button class="btn btn-primary-custom btn-sm" data-bs-toggle="modal" data-bs-target="#addRecordModal">+ Add Record</button>
                </div>
            </div>
            <div class="card-body-custom">
                <div class="records-table-container">
                    <table class="records-table">
                        <thead>
                            <tr><th>Patient ID</th><th>Patient Name</th><th>Record Title / Exam</th><th>Attending Physician</th><th>Date</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody id="adminRecordsTableBody">
                            <?php if (!$records): ?>
                                <tr><td colspan="7" class="text-muted text-center py-3">No medical records yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($records as $r): ?>
                                <tr data-status="<?= htmlspecialchars($r['status']) ?>">
                                    <td><strong>#<?= htmlspecialchars($r['patient_code']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['patient_name']) ?></td>
                                    <td><?= htmlspecialchars($r['title']) ?></td>
                                    <td><?= htmlspecialchars($r['doctor_name']) ?></td>
                                    <td><?= date('d M Y', strtotime($r['record_date'])) ?></td>
                                    <td><span class="badge <?= statusBadge($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-custom view-record-btn" title="View"
                                                data-patient="<?= htmlspecialchars($r['patient_name']) ?> (#<?= htmlspecialchars($r['patient_code']) ?>)"
                                                data-doctor="<?= htmlspecialchars($r['doctor_name']) ?>"
                                                data-date="<?= date('d M Y', strtotime($r['record_date'])) ?>"
                                                data-status="<?= htmlspecialchars($r['status']) ?>"
                                                data-title="<?= htmlspecialchars($r['title']) ?>"
                                                data-notes="<?= htmlspecialchars($r['notes'] ?? '') ?>">👁️</button>
                                        <button type="button" class="btn btn-sm btn-outline-custom edit-record-btn" title="Edit"
                                                data-id="<?= (int) $r['id'] ?>"
                                                data-patient-id="<?= (int) $r['patient_id'] ?>"
                                                data-doctor-id="<?= (int) $r['doctor_id'] ?>"
                                                data-title="<?= htmlspecialchars($r['title']) ?>"
                                                data-notes="<?= htmlspecialchars($r['notes'] ?? '') ?>"
                                                data-date="<?= htmlspecialchars($r['record_date']) ?>">✏️</button>
                                        <?php if ($r['status'] !== 'Verified'): ?>
                                            <form method="POST" action="admin.php" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="verify_record">
                                                <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success" title="Mark Verified">✔️</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" action="admin.php" class="d-inline" onsubmit="return confirm('Delete this medical record? This cannot be undone.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_record">
                                            <input type="hidden" name="record_id" value="<?= (int) $r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">🗑️</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Today's Appointments -->
        <section class="card-custom mb-4" id="appointments-management">
            <div class="card-header-custom">
                <h5 class="manrope-700 mb-0">Scheduled Consultations List</h5>
                <small class="text-muted">Today's appointments <?= $isDoctor ? ' — ' . htmlspecialchars($myDepartment) . ' Department' : '' ?></small>
            </div>
            <div class="card-body-custom">
                <div class="records-table-container">
                    <table class="records-table">
                        <thead>
                            <tr><th>Time Slot</th><th>Patient Name</th><th>Department</th><th>Physician</th><th>Status</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$appointments): ?>
                                <tr><td colspan="6" class="text-muted text-center py-3">No appointments scheduled for today.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($appointments as $a): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($a['appt_time']) ?></strong></td>
                                    <td><?= htmlspecialchars($a['patient_name']) ?> (#<?= htmlspecialchars($a['patient_code']) ?>)</td>
                                    <td><?= htmlspecialchars($a['department']) ?></td>
                                    <td><?= htmlspecialchars($a['doctor_name']) ?></td>
                                    <td><span class="badge <?= statusBadge($a['status']) ?>"><?= htmlspecialchars($a['status']) ?></span></td>
                                    <td>
                                        <?php if ($a['status'] === 'Completed'): ?>
                                            <button class="btn btn-sm btn-success" disabled>✔️ Done</button>
                                        <?php else: ?>
                                            <form method="POST" action="admin.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="complete_appointment">
                                                <input type="hidden" name="appt_id" value="<?= (int) $a['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success">Mark Completed</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <!-- MODAL: Add New Medical Record -->
    <div class="modal fade" id="addRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Log New Medical Record (EMR)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_record">
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Patient</label>
                                <select name="patient_id" class="form-select form-select-custom" required>
                                    <option value="">Choose a patient...</option>
                                    <?php foreach ($patientsList as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>">#<?= htmlspecialchars($p['patient_code']) ?> — <?= htmlspecialchars($p['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Attending Physician</label>
                                <select name="doctor_id" class="form-select form-select-custom" required>
                                    <option value="">Choose a physician...</option>
                                    <?php foreach ($doctorsList as $d): ?>
                                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['department']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label manrope-600">Record Title / Procedure</label>
                                <input type="text" name="title" class="form-control form-control-custom" placeholder="e.g. Chest X-Ray Scan" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Diagnostic Summary & Notes</label>
                            <textarea name="notes" class="form-control form-control-custom" rows="4" placeholder="Enter clinical findings, vitals, lab parameters, and prescription details..." required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer modal-footer-custom">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Save & Publish Record</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: View Medical Record -->
    <div class="modal fade" id="viewRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Medical Record Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Patient</small>
                            <strong id="viewRecPatient"></strong>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Attending Physician</small>
                            <strong id="viewRecDoctor"></strong>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Date</small>
                            <strong id="viewRecDate"></strong>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Status</small>
                            <strong id="viewRecStatus"></strong>
                        </div>
                    </div>
                    <hr>
                    <h6 class="manrope-700 mb-2" id="viewRecTitle"></h6>
                    <p class="text-secondary mb-0" id="viewRecNotes" style="white-space: pre-wrap;"></p>
                </div>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-primary-custom" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Edit Medical Record -->
    <div class="modal fade" id="editRecordModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Edit Medical Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_record">
                    <input type="hidden" name="record_id" id="editRecId">
                    <div class="modal-body p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Patient</label>
                                <select name="patient_id" id="editRecPatient" class="form-select form-select-custom" required>
                                    <?php foreach ($patientsList as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>">#<?= htmlspecialchars($p['patient_code']) ?> — <?= htmlspecialchars($p['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Attending Physician</label>
                                <select name="doctor_id" id="editRecDoctor" class="form-select form-select-custom" required>
                                    <?php foreach ($doctorsList as $d): ?>
                                        <option value="<?= (int) $d['id'] ?>"><?= htmlspecialchars($d['full_name']) ?> (<?= htmlspecialchars($d['department']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Record Date</label>
                                <input type="date" name="record_date" id="editRecDate" class="form-control form-control-custom" required max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Record Title / Procedure</label>
                                <input type="text" name="title" id="editRecTitle" class="form-control form-control-custom" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Diagnostic Summary & Notes</label>
                            <textarea name="notes" id="editRecNotes" class="form-control form-control-custom" rows="4" required></textarea>
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
            <p class="mb-1">© 2026 Healthcore Hospital Administration System. Confidentially Restricted to Authorized Staff.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script.js"></script>
    <?php render_flash_script(); ?>
</body>

</html>
