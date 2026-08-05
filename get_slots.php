<?php
// ============================================================
// AJAX endpoint: which of APPT_SLOTS are still free for a given
// doctor on a given date? Polled by the booking form in index.php
// whenever the physician or date field changes, so a patient can't
// even select a slot that's already taken.
//
// This is the client-side half of the double-booking guard — the
// real guard is the server-side re-check inside index.php's
// book_appointment handler, since a client can always be bypassed.
// ============================================================
require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json');

require_role(['patient']);

$doctorId = (int) ($_GET['doctor_id'] ?? 0);
$date     = trim($_GET['appt_date'] ?? '');

if ($doctorId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'doctor_id and appt_date (YYYY-MM-DD) are required']);
    exit;
}

// Slots already taken for this doctor+date. A cancelled appointment
// frees the slot back up, so only non-cancelled statuses count as "taken".
$stmt = $pdo->prepare(
    "SELECT appt_time FROM appointments
     WHERE doctor_id = ? AND appt_date = ? AND status != 'Cancelled'"
);
$stmt->execute([$doctorId, $date]);
$taken = $stmt->fetchAll(PDO::FETCH_COLUMN);

$slots = [];
foreach (APPT_SLOTS as $slot) {
    $slots[] = ['time' => $slot, 'available' => !in_array($slot, $taken, true)];
}

echo json_encode(['slots' => $slots]);
