<?php
require_once 'auth.php';
require_once 'db.php';
require_once 'queue_helpers.php';
require_role(['patient']);

$userId = $_SESSION['user_id'];

// Fetch the patient row for this logged-in user
$stmt = $pdo->prepare('SELECT * FROM patients WHERE user_id = ?');
$stmt->execute([$userId]);
$patient = $stmt->fetch();

if (!$patient) {
    die('No patient profile found for this account.');
}
$patientId = $patient['id'];

// Handle: Book Appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'book_appointment') {
    verify_csrf();
    $doctorId = (int) ($_POST['doctor_id'] ?? 0);
    $date     = trim($_POST['appt_date'] ?? '');
    $time     = trim($_POST['appt_time'] ?? '');
    $reason   = trim($_POST['reason'] ?? '');

    $docStmt = $pdo->prepare('SELECT id, department FROM doctors WHERE id = ?');
    $docStmt->execute([$doctorId]);
    $doc = $docStmt->fetch();
    // Raw department name (e.g. "Cardiology") — used for the queue and
    // the doctor time-slot check. $department below (with " Department"
    // appended) stays a separate display string on the appointment row.
    $rawDepartment = $doc['department'] ?? null;
    $department    = $doc ? ($doc['department'] . ' Department') : null;

    if (!$department || !$date || !$time) {
        flash('Please choose a physician, date and time to book an appointment.', '⚠️');
    } elseif (!in_array($time, APPT_SLOTS, true)) {
        flash('Please choose a valid time slot.', '⚠️');
    } else {
        // -------- Doctor time-slot management: prevent double-booking --------
        // The dropdown already hides taken slots via get_slots.php, but that
        // can be bypassed (disabled DOM, race with another tab, etc.), so the
        // real guard lives here. Lock any existing row for this doctor+date+
        // time inside a transaction, check it's actually free, then insert —
        // so two near-simultaneous bookings for the same slot can't both win.
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare(
                "SELECT id FROM appointments
                 WHERE doctor_id = ? AND appt_date = ? AND appt_time = ? AND status != 'Cancelled'
                 FOR UPDATE"
            );
            $lock->execute([$doctorId, $date, $time]);

            if ($lock->fetch()) {
                $pdo->rollBack();
                flash('That time slot was just booked by someone else — please pick another.', '⚠️');
            } else {
                // Ticket numbers reset per department per day, scoped to
                // that department's bookings (each department runs its own
                // queue now — see queue_helpers.php).
                $nextTicket = next_ticket_number($pdo, $rawDepartment, $date);

                $ins = $pdo->prepare('INSERT INTO appointments (patient_id, doctor_id, department, appt_date, appt_time, reason, status, ticket_number) VALUES (?, ?, ?, ?, ?, ?, "Confirmed", ?)');
                $ins->execute([$patientId, $doctorId, $department, $date, $time, $reason, $nextTicket]);
                $pdo->commit();
                flash('Appointment booked for ' . date('d M Y', strtotime($date)) . ' at ' . $time, '📅');
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            flash('Something went wrong booking that appointment. Please try again.', '⚠️');
        }
    }
    header('Location: index.php');
    exit;
}

// Handle: Cancel Appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_appointment') {
    verify_csrf();
    $apptId = (int) ($_POST['appt_id'] ?? 0);
    $upd = $pdo->prepare('UPDATE appointments SET status = "Cancelled" WHERE id = ? AND patient_id = ?');
    $upd->execute([$apptId, $patientId]);
    flash('Appointment cancelled.', '🚫');
    header('Location: index.php');
    exit;
}

// Next upcoming appointment (soonest confirmed/pending one from today onward)
$stmt = $pdo->prepare("SELECT a.*, u.full_name AS doctor_name, d.department AS doctor_department FROM appointments a
                        JOIN doctors d ON d.id = a.doctor_id
                        JOIN users u ON u.id = d.user_id
                        WHERE a.patient_id = ? AND a.status IN ('Confirmed','Pending','Arrived') AND a.appt_date >= CURDATE()
                        ORDER BY a.appt_date ASC, a.appt_time ASC LIMIT 1");
$stmt->execute([$patientId]);
$nextAppt = $stmt->fetch();

// Live queue state — scoped to the department of the patient's next
// appointment, since each department now runs its own queue. If they
// have no upcoming appointment there's nothing to look up.
$myDeptForQueue = $nextAppt['doctor_department'] ?? null;
$queue = $myDeptForQueue ? get_or_create_queue($pdo, $myDeptForQueue) : null;
$currentlyServing = $queue['currently_serving'] ?? 0;
// Only show a live queue ticket if $nextAppt is actually TODAY.
// $nextAppt can be a future-dated appointment (the query above looks
// ahead with appt_date >= CURDATE()), but "Currently Serving" is only
// ever today's counter — showing a future day's ticket number next to
// today's counter compares two unrelated queues and looks arbitrarily
// high/wrong. If it's not today, there's no live ticket yet.
$myTicket = ($nextAppt && $nextAppt['appt_date'] === date('Y-m-d')) ? (int) $nextAppt['ticket_number'] : 0;
// Same real, status-based counting as admin's "Total Waiting" — count
// of today's still-open appointments in the SAME DEPARTMENT with a
// smaller ticket number — not ticket-number arithmetic, so the two
// numbers agree with each other (and don't count tickets that were
// cancelled, already done, or belong to a different department's queue).
$ahead = 0;
if ($myTicket && $myDeptForQueue) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM appointments a JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = CURDATE()
           AND a.status IN ('Confirmed','Pending','Arrived') AND a.ticket_number < ?"
    );
    $stmt->execute([$myDeptForQueue, $myTicket]);
    $ahead = (int) $stmt->fetch()['c'];
}
$estWaitMins = (int) ceil($ahead * 1.5);

// Recent medical records for this patient
$stmt = $pdo->prepare("SELECT mr.*, u.full_name AS doctor_name FROM medical_records mr
                        JOIN doctors d ON d.id = mr.doctor_id
                        JOIN users u ON u.id = d.user_id
                        WHERE mr.patient_id = ? ORDER BY mr.record_date DESC");
$stmt->execute([$patientId]);
$records = $stmt->fetchAll();

// All doctors, for the "book appointment" dropdown
$doctors = $pdo->query("SELECT d.id, u.full_name, d.department FROM doctors d JOIN users u ON u.id = d.user_id")->fetchAll();

$firstName = explode(' ', $_SESSION['full_name'])[0];
$initial = strtoupper(substr($_SESSION['full_name'], 0, 1));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Healthcore - Patient Portal & Hospital Management</title>
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
                    <li class="nav-item"><a class="nav-link nav-link-custom active" href="index.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="index.php#appointments-section">Appointments</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="index.php#queue-section">Waiting Status</a></li>
                    <li class="nav-item"><a class="nav-link nav-link-custom" href="index.php#records-section">Medical Records</a></li>
                </ul>
            </div>

            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-outline-custom p-2 rounded-circle" id="themeToggleBtn" title="Toggle Light/Dark Theme">🌙</button>
                <div class="dropdown">
                    <button class="profile-dropdown-btn dropdown-toggle" type="button" id="profileDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar"><?= htmlspecialchars($initial) ?></span> <?= htmlspecialchars($_SESSION['full_name']) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-custom" aria-labelledby="profileDropdown">
                        <li><a class="dropdown-item" href="profile.php">👤 My Profile</a></li>
                        <li><a class="dropdown-item" href="settings.php">⚙️ Account Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php">🚪 Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <main class="main-wrapper">
        <section class="welcome-header">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h2 class="manrope-800 mb-2">Welcome back, <?= htmlspecialchars($firstName) ?>! 👋</h2>
                    <p class="mb-0 opacity-90">Here is your health overview and upcoming schedules for Healthcore General Hospital.</p>
                </div>
                <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                    <button class="btn btn-light manrope-700 px-4 py-2" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
                        + Book New Appointment
                    </button>
                </div>
            </div>
        </section>

        <div class="action-grid">
            <a href="#" class="action-tile" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
                <span class="action-icon">📅</span>
                <strong class="d-block manrope-700">Book Visit</strong>
                <small class="text-muted">Schedule consultation</small>
            </a>
            <a href="#queue-section" class="action-tile">
                <span class="action-icon">🎫</span>
                <strong class="d-block manrope-700">Live Ticket</strong>
                <small class="text-muted">Check queue position</small>
            </a>
            <a href="#records-section" class="action-tile">
                <span class="action-icon">📄</span>
                <strong class="d-block manrope-700">Lab Results</strong>
                <small class="text-muted">View recent reports</small>
            </a>
            <a href="#" class="action-tile" id="refillRxBtn">
                <span class="action-icon">💊</span>
                <strong class="d-block manrope-700">Refill Rx</strong>
                <small class="text-muted">Request medication</small>
            </a>
        </div>

        <div class="card-grid">
            <!-- Appointment Card Widget -->
            <div class="card-custom appointment-card" id="appointments-section">
                <div class="card-header-custom">
                    <span>Upcoming Appointment</span>
                    <?php if ($nextAppt): ?>
                        <span class="badge bg-primary rounded-pill"><?= htmlspecialchars($nextAppt['status']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body-custom">
                    <?php if ($nextAppt): ?>
                        <div class="appointment-date-badge">
                            📅 <?= date('d F Y', strtotime($nextAppt['appt_date'])) ?> • <?= htmlspecialchars($nextAppt['appt_time']) ?>
                        </div>
                        <div class="doctor-info-box">
                            <div class="doctor-avatar"><?= strtoupper(substr($nextAppt['doctor_name'], 0, 2)) ?></div>
                            <div>
                                <h6 class="manrope-700 mb-0"><?= htmlspecialchars($nextAppt['doctor_name']) ?></h6>
                                <small class="text-muted"><?= htmlspecialchars($nextAppt['department']) ?></small>
                            </div>
                        </div>
                        <p class="text-secondary small mb-4">Please arrive 15 minutes before your scheduled appointment time for routine vitals check.</p>
                        <div class="mt-auto d-flex gap-2">
                            <button class="btn btn-primary-custom flex-grow-1" data-bs-toggle="modal" data-bs-target="#appointmentDetailModal">
                                View Appointment Details
                            </button>
                            <form method="POST" action="index.php" onsubmit="return confirm('Cancel this appointment?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel_appointment">
                                <input type="hidden" name="appt_id" value="<?= (int) $nextAppt['id'] ?>">
                                <button type="submit" class="btn btn-outline-custom" title="Cancel">✖️</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">No upcoming appointments. </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Live Queue Widget -->
            <div class="card-custom queue-card" id="queue-section" data-my-ticket="<?= (int) $myTicket ?>" data-department="<?= htmlspecialchars($myDeptForQueue ?? '') ?>">
                <div class="card-header-custom">
                    <span>Waiting Status</span>
                    <span class="queue-badge-live"><span class="pulse-dot"></span> Live Queue</span>
                </div>
                <div class="card-body-custom">
                    <div class="queue-box">
                        <p class="text-muted mb-0 small">YOUR QUEUE TICKET</p>
                        <div class="queue-number-large" id="myQueueNumber"><?= $myTicket ?: '—' ?></div>
                        <?php if (!$myTicket && $nextAppt): ?>
                            <small class="text-muted" id="queueNoTicketNote">Ticket opens on your appointment day (<?= date('d M Y', strtotime($nextAppt['appt_date'])) ?>)</small>
                        <?php endif; ?>
                    </div>
                    <div class="queue-details">
                        <div>
                            <small class="text-muted d-block">Currently Serving</small>
                            <strong class="manrope-700 fs-5 text-success" id="currentQueueNumber"><?= (int) $currentlyServing ?></strong>
                        </div>
                        <div class="border-start"></div>
                        <div>
                            <small class="text-muted d-block">Patients Ahead</small>
                            <strong class="manrope-700 fs-5 text-warning" id="patientsAheadCount"><?= $myTicket ? $ahead : '—' ?></strong>
                        </div>
                        <div class="border-start"></div>
                        <div>
                            <small class="text-muted d-block">Est. Wait Time</small>
                            <strong class="manrope-700 fs-5 text-primary" id="estimatedWaitTime"><?= $myTicket ? $estWaitMins . ' mins' : '—' ?></strong>
                        </div>
                    </div>
                    <div class="mt-auto d-flex gap-2">
                        <button class="btn btn-primary-custom flex-grow-1" data-bs-toggle="modal" data-bs-target="#queueDetailModal">
                            View Queue Tracker
                        </button>
                        <a href="index.php" class="btn btn-outline-custom" title="Refresh Live Ticket">🔄</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Medical Records -->
        <section class="card-custom mb-4" id="records-section">
            <div class="card-header-custom">
                <div>
                    <h5 class="manrope-700 mb-0">Recent Medical Records & Reports</h5>
                    <small class="text-muted">Access lab test results, doctor notes, and clinical histories</small>
                </div>
                <button class="btn btn-outline-custom btn-sm" id="downloadAllRecordsBtn">📥 Export All (PDF)</button>
            </div>
            <div class="card-body-custom">
                <div class="records-table-container">
                    <table class="records-table">
                        <thead>
                            <tr><th>Date</th><th>Record Type</th><th>Physician</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php if (!$records): ?>
                                <tr><td colspan="4" class="text-muted text-center py-3">No medical records yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><?= date('d M Y', strtotime($r['record_date'])) ?></td>
                                    <td><strong><?= htmlspecialchars($r['title']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['doctor_name']) ?></td>
                                    <td>
                                        <?php if ($r['status'] === 'Verified'): ?>
                                            <span class="badge bg-success">Verified</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Under Review</span>
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

    <footer>
        <div class="container">
            <p class="mb-1">© 2026 Healthcore Hospital Management System. All rights reserved.</p>
            <small>For medical emergencies, please dial emergency services (911 / 112) immediately.</small>
        </div>
    </footer>

    <!-- MODAL: Book Appointment -->
    <div class="modal fade" id="bookAppointmentModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Schedule New Appointment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="index.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="book_appointment">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label manrope-600">Select Physician</label>
                            <select name="doctor_id" class="form-select form-select-custom" id="doctorSelectPHP" required>
                                <option value="">Choose a doctor...</option>
                                <?php foreach ($doctors as $doc): ?>
                                    <option value="<?= (int) $doc['id'] ?>">
                                        <?= htmlspecialchars($doc['full_name']) ?> (<?= htmlspecialchars($doc['department']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Date</label>
                                <input type="date" name="appt_date" id="apptDateSelectPHP" class="form-control form-control-custom" required min="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label manrope-600">Time Slot</label>
                                <select name="appt_time" class="form-select form-select-custom" id="apptTimeSelectPHP" required>
                                    <?php foreach (APPT_SLOTS as $slot): ?>
                                        <option value="<?= htmlspecialchars($slot) ?>"><?= htmlspecialchars($slot) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted" id="slotAvailabilityNote">Choose a physician and date to see open slots.</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label manrope-600">Reason for Visit / Symptoms</label>
                            <textarea name="reason" class="form-control form-control-custom" rows="3" placeholder="Briefly describe your symptoms or visit purpose..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer modal-footer-custom">
                        <button type="button" class="btn btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary-custom">Confirm Appointment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL: Appointment Detail -->
    <div class="modal fade" id="appointmentDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Appointment Overview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <?php if ($nextAppt): ?>
                <div class="modal-body p-4">
                    <div class="p-3 rounded mb-3 border">
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">REFERENCE #</small>
                            <strong class="text-primary">APP-<?= str_pad($nextAppt['id'], 6, '0', STR_PAD_LEFT) ?></strong>
                        </div>
                        <h5 class="manrope-700 mb-1"><?= htmlspecialchars($nextAppt['doctor_name']) ?></h5>
                        <p class="text-muted mb-2"><?= htmlspecialchars($nextAppt['department']) ?></p>
                        <hr class="my-2">
                        <div class="row text-center mt-3">
                            <div class="col-6"><small class="text-muted d-block">DATE</small><strong><?= date('d M Y', strtotime($nextAppt['appt_date'])) ?></strong></div>
                            <div class="col-6 border-start"><small class="text-muted d-block">TIME</small><strong><?= htmlspecialchars($nextAppt['appt_time']) ?></strong></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-primary-custom" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Queue Detail -->
    <div class="modal fade" id="queueDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-custom">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title manrope-700">Queue Tracker Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4 text-center">
                    <span class="badge bg-primary mb-2" id="modalQueueDept"><?= htmlspecialchars($queue['department'] ?? '') ?></span>
                    <h1 class="display-3 manrope-800 text-primary my-2" id="modalMyQueue"><?= $myTicket ?: '—' ?></h1>
                    <p class="text-muted">Your Assigned Ticket Number</p>
                    <div class="row g-3 mt-3">
                        <div class="col-6">
                            <div class="p-3 border rounded">
                                <small class="text-muted d-block">Now Serving</small>
                                <h3 class="text-success mb-0 manrope-700" id="modalServingQueue"><?= (int) $currentlyServing ?></h3>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="p-3 border rounded">
                                <small class="text-muted d-block">Patients Ahead</small>
                                <h3 class="text-warning mb-0 manrope-700" id="modalAheadQueue"><?= $ahead ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer modal-footer-custom">
                    <button type="button" class="btn btn-primary-custom" data-bs-dismiss="modal">Got It</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container-custom" id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="script.js"></script>
    <?php render_flash_script(); ?>
</body>

</html>
