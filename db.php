<?php
// ============================================================
// Database connection (PDO + MySQL)
// Edit these 4 values to match your XAMPP / hosting setup.
// ============================================================
$DB_HOST = 'localhost';
$DB_NAME = 'healthcore';
$DB_USER = 'root';
$DB_PASS = '';

// ------------------------------------------------------------
// Staff invite code required on register.php to create a DOCTOR
// account. Without this, anyone could self-register as staff and
// get admin.php access to every patient's medical records. Change
// this to your own secret and share it only with staff you're
// onboarding (e.g. verbally, not over email).
// ------------------------------------------------------------
define('DOCTOR_INVITE_CODE', 'change-me-healthcore-staff-2026');

// ------------------------------------------------------------
// Bookable time slots. This is the single source of truth for
// what "a slot" is — used by the booking form (index.php), the
// AJAX availability check (get_slots.php), and the server-side
// double-booking guard (index.php's book_appointment handler).
// Change this array to change the slots hospital-wide.
// ------------------------------------------------------------
define('APPT_SLOTS', ['09:00 AM', '10:30 AM', '02:00 PM', '04:15 PM']);

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Database connection failed. Check db.php credentials and make sure the "healthcore" database has been imported. (' . $e->getMessage() . ')');
}
