<?php
// ============================================================
// One-time demo data seeder.
//
// Visit this file once in your browser after importing schema.sql:
//   http://localhost/healthcore-php/seed.php
//
// It creates one admin, one doctor, and one patient account (all
// using the password below) so you have something to log in with
// immediately. It refuses to run again once the `users` table is
// non-empty, so it's safe to leave in place — but delete it before
// deploying anywhere real.
// ============================================================
require_once 'db.php';

const DEMO_PASSWORD = 'password123';

$existing = (int) $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
if ($existing > 0) {
    die('Seed data was already created (the users table is not empty) — seed.php will not run again. Delete this file once you\'re done, or clear the `users` table if you really want to re-seed.');
}

$hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // --- Admin -------------------------------------------------
    $stmt = $pdo->prepare('INSERT INTO users (role, full_name, email, password, phone) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(['admin', 'Alex Morgan', 'admin@healthcore.com', $hash, '555-0100']);

    // --- Doctor (also gets a row in `doctors`) ------------------
    $stmt->execute(['doctor', 'Dr. Sarah Lee', 'sarah.lee@healthcore.com', $hash, '555-0101']);
    $doctorUserId = $pdo->lastInsertId();

    $doctorCode = 'DR-' . random_int(10000, 99999);
    $pdo->prepare('INSERT INTO doctors (user_id, doctor_code, department) VALUES (?, ?, ?)')
        ->execute([$doctorUserId, $doctorCode, 'Cardiology']);

    // --- Patient (also gets a row in `patients`) ----------------
    $stmt->execute(['patient', 'John Doe', 'john.doe@example.com', $hash, '555-0102']);
    $patientUserId = $pdo->lastInsertId();

    $patientCode = 'HC-' . random_int(10000, 99999);
    $pdo->prepare('INSERT INTO patients (user_id, patient_code) VALUES (?, ?)')
        ->execute([$patientUserId, $patientCode]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    die('Something went wrong seeding demo data: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Healthcore — Demo Accounts Created</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; max-width: 640px; margin: 60px auto; padding: 0 20px; color: #222; }
        h1 { font-size: 1.4rem; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #e2e2e2; font-size: 0.95rem; }
        th { background: #f7f7f7; }
        code { background: #f2f2f2; padding: 2px 6px; border-radius: 4px; }
        .warn { background: #fff8e1; border: 1px solid #ffe082; padding: 12px 16px; border-radius: 6px; margin-top: 24px; font-size: 0.9rem; }
    </style>
</head>
<body>
    <h1>✅ Demo accounts created</h1>
    <p>Password for every account below: <code><?= DEMO_PASSWORD ?></code></p>
    <table>
        <tr><th>Email</th><th>Role</th><th>Lands on</th></tr>
        <tr><td><?= htmlspecialchars('admin@healthcore.com') ?></td><td>Admin</td><td><code>admin.php</code> — full staff dashboard</td></tr>
        <tr><td><?= htmlspecialchars('sarah.lee@healthcore.com') ?></td><td>Doctor</td><td><code>admin.php</code> — scoped to her department (Cardiology)</td></tr>
        <tr><td><?= htmlspecialchars('john.doe@example.com') ?></td><td>Patient</td><td><code>index.php</code> — patient dashboard</td></tr>
    </table>
    <p><a href="login.php">Go to login →</a></p>
    <div class="warn">⚠️ Delete <code>seed.php</code> once you're done with it — good hygiene before deploying anywhere real.</div>
</body>
</html>
