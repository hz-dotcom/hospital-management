<?php
// ============================================================
// Per-department queue helpers.
//
// queue_state now holds ONE ROW PER DEPARTMENT (department is
// UNIQUE) instead of a single global row with id=1. These helpers
// are the only place that should touch queue_state directly, so
// every page (admin.php, index.php, queue_status.php) agrees on
// how a department's queue is fetched, created, called, and reset.
// ============================================================

// Fetch the queue row for a department, creating it (currently_serving
// starting at 0) the first time it's ever needed. This means you don't
// have to pre-seed queue_state for every department up front — a brand
// new department just works the first time anyone looks at its queue.
function get_or_create_queue(PDO $pdo, string $department): array {
    $stmt = $pdo->prepare('SELECT * FROM queue_state WHERE department = ?');
    $stmt->execute([$department]);
    $row = $stmt->fetch();
    if ($row) {
        return $row;
    }

    // Race-safe: two simultaneous first-requests for the same brand-new
    // department could both miss the SELECT above. INSERT IGNORE means
    // only one of them actually creates the row; both then re-SELECT.
    $ins = $pdo->prepare('INSERT IGNORE INTO queue_state (department, currently_serving) VALUES (?, 0)');
    $ins->execute([$department]);

    $stmt->execute([$department]);
    $row = $stmt->fetch();

    if (!$row) {
        // Getting here almost always means queue_state still has the OLD
        // single-row schema (id INT PRIMARY KEY DEFAULT 1, not
        // AUTO_INCREMENT) — every INSERT then defaults to id=1, collides
        // with whichever row already has that id, and INSERT IGNORE
        // silently drops it instead of creating this department's row.
        // Run the queue_state migration in README.md ("If you already
        // imported the old schema.sql") to fix it.
        throw new RuntimeException(
            "Could not create a queue row for department \"{$department}\". " .
            "This usually means queue_state still has the old table structure " .
            "(id not AUTO_INCREMENT) — see the queue_state migration in README.md."
        );
    }

    return $row;
}

// All departments that currently have at least one doctor. This is what
// drives the admin "one card per department" queue view, and is used to
// validate that a department name POSTed from a form is a real one
// before we act on it (an admin should not be able to call/reset a
// queue for a made-up department name).
function active_departments(PDO $pdo): array {
    return $pdo->query('SELECT DISTINCT department FROM doctors ORDER BY department')->fetchAll(PDO::FETCH_COLUMN);
}

// Real, status-based count of patients still waiting in $department
// today — same rule used everywhere else in the app (not ticket-number
// arithmetic, so it never sits on a stale/inflated number).
function department_waiting_count(PDO $pdo, string $department): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = CURDATE()
           AND a.status IN ('Confirmed','Pending','Arrived')"
    );
    $stmt->execute([$department]);
    return (int) $stmt->fetch()['c'];
}

// Highest ticket number issued today for $department (tickets reset
// per department per day — see queue_helpers' next_ticket_number()).
function department_max_ticket(PDO $pdo, string $department): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(a.ticket_number), 0) AS t FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = ?"
    );
    $stmt->execute([$department, date('Y-m-d')]);
    return (int) $stmt->fetch()['t'];
}

// Next ticket number to hand out for a department on a given date.
// Tickets are scoped per department (not just per day) now that each
// department runs its own queue, so Cardiology and Neurology both get
// to start at #1 on the same day instead of competing for one counter.
function next_ticket_number(PDO $pdo, string $department, string $date): int {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(MAX(a.ticket_number), 0) + 1 AS t FROM appointments a
         JOIN doctors d ON d.id = a.doctor_id
         WHERE d.department = ? AND a.appt_date = ?"
    );
    $stmt->execute([$department, $date]);
    return (int) $stmt->fetch()['t'];
}

// Short, URL/DOM-id-safe slug for a department name (e.g. "General
// Medicine" -> "general-medicine"), used to give each per-department
// queue card its own unique element IDs on admin.php.
function department_slug(string $department): string {
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $department), '-'));
}
