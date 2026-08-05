# Healthcore — PHP Backend Setup

## What's in this folder
- `schema.sql` — creates the `healthcore` database and all tables
- `db.php` — MySQL connection (edit credentials here)
- `auth.php` — session/login helpers used by every protected page, plus CSRF token helpers (`csrf_field()` / `verify_csrf()`)
- `login.php`, `register.php`, `logout.php` — authentication
- `seed.php` — one-time script that creates demo accounts (delete after running)
- `index.php` — patient dashboard
- `profile.php` — patient profile
- `settings.php` — account settings: notification preferences, password change, 2FA toggle — all persisted to the database
- `admin.php` — staff/admin dashboard
- `style.css`, `script.js` — front end

**The old static `admin.html` / `index.html` / `profile.html` / `settings.html` files have been removed.** They had no login check at all, so anyone who requested them directly could see the full staff admin UI (or a patient dashboard shell) without authenticating, and their "Logout" link didn't actually end a session. The `.php` versions are the only pages now — every one of them is gated by `auth.php`'s `require_role()`.

## 1. Install a local server stack
Install **XAMPP** (or WAMP/MAMP) — it bundles Apache, PHP and MySQL together.

## 2. Put the files in place
Copy this whole folder into your server's web root, e.g.:
```
C:\xampp\htdocs\healthcore-php\      (Windows)
/Applications/XAMPP/htdocs/healthcore-php/   (Mac)
```

## 3. Create the database
1. Start Apache **and** MySQL in the XAMPP control panel.
2. Open `http://localhost/phpmyadmin`.
3. Click **Import**, choose `schema.sql`, click **Go**.
   This creates the `healthcore` database with empty tables.

## 4. Check your DB credentials
Open `db.php` — the defaults (`root` / no password / `localhost`) match
a fresh XAMPP install. Change them if your MySQL setup is different.

## 5. Create demo accounts
Visit `http://localhost/healthcore-php/seed.php` in your browser once.
It will print three login combinations, all using the password
`password123`:
- `admin@healthcore.com` (admin)
- `sarah.lee@healthcore.com` (doctor — also uses admin.php)
- `john.doe@example.com` (patient)

**Delete `seed.php` after this** — it refuses to run twice (it checks
the users table is empty first), but it's good hygiene to remove it
from a real server regardless.

## 6. Log in
Go to `http://localhost/healthcore-php/login.php` and sign in.
Patients land on `index.php`; admins/doctors land on `admin.php`.
New patients can also self-register at `register.php`.

## How the login/role system works
- Every user lives in one `users` table with a `role` column
  (`patient`, `doctor`, `admin`).
- `patients` and `doctors` tables hold role-specific extra fields and
  link back to `users` via `user_id`.
- `auth.php`'s `require_role([...])` guards each page — visiting
  `admin.php` as a patient bounces you back to `index.php`, and
  visiting any protected page while logged out bounces you to
  `login.php`.
- Passwords are stored with PHP's `password_hash()` / verified with
  `password_verify()` — never in plain text.
- Every form that changes data (login, register, book/cancel appointment,
  profile edit, settings, and every admin action) includes a CSRF token via
  `csrf_field()`, checked with `verify_csrf()` at the top of each POST
  handler. This stops another website from silently submitting these forms
  on a logged-in user's behalf.
- The session cookie is set `HttpOnly` + `SameSite=Lax` in `auth.php`. Once
  you serve this over HTTPS, uncomment the `'secure' => true` line in the
  `session_set_cookie_params()` call there.
- Outpatient ticket numbers reset per calendar day (scoped to
  `appt_date`), so the queue counters don't drift upward forever as
  appointments accumulate over time.

## Notes on the conversion from your original HTML/JS
Your original pages used `localStorage` + hardcoded arrays in
`script.js` to fake data (appointments, queue numbers, records).
The PHP versions now read/write real rows in MySQL instead. I kept
`script.js` for the parts that are still purely cosmetic (theme
toggle, navbar scroll-spy, toast notifications, the client-side
record search box) and removed the JS's hooks into forms that now
submit for real (booking, cancelling, editing profile, calling the
next ticket, adding records, marking appointments complete) — those
go through normal PHP form submissions now, so refreshing the page
always shows the real database state, not a cached local copy.

## Known decorative stubs
Two buttons on the patient dashboard are intentionally cosmetic and don't
hit the database: "📥 Export All (PDF)" (just shows a toast, no real file
is generated) and "💊 Refill Rx" (there's no prescriptions table yet). Ask
if you want either built out for real.

## If you already imported the old `schema.sql`
The `users` table now has four extra columns (`sms_notif`, `email_notif`,
`lab_notif`, `two_factor`) used by `settings.php`. If your database already
exists, run this once in phpMyAdmin's SQL tab instead of re-importing everything:
```sql
ALTER TABLE users
  ADD COLUMN sms_notif   TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN email_notif TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN lab_notif   TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN two_factor  TINYINT(1) NOT NULL DEFAULT 0;
```

`queue_state` also changed shape — it used to be a single row (`id=1`)
for the whole hospital and is now one row per department (see "Per-
department queues" below). If your database already has the old
single-row version, run this instead of re-importing everything:
```sql
DROP TABLE queue_state;
CREATE TABLE queue_state (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    department         VARCHAR(100) NOT NULL UNIQUE,
    currently_serving  INT NOT NULL DEFAULT 0,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE INDEX idx_appt_doctor_date_time ON appointments (doctor_id, appt_date, appt_time);
```
(Dropping and recreating is safe here — the old table only ever held a
live counter, no historical data worth preserving. New department rows
get created automatically the first time each department's queue is
touched, so you don't need to seed them by hand.)

## New: Doctor time-slot management (no more double-booking)
- `get_slots.php` is a small AJAX endpoint: given a `doctor_id` and
  `appt_date`, it returns which of the bookable slots (defined once, in
  `APPT_SLOTS` in `db.php`) are already taken for that doctor that day.
- The booking form in `index.php` calls it whenever the physician or
  date changes, and disables already-booked slots in the dropdown.
- That's just the UI convenience — the real guard is server-side:
  `index.php`'s `book_appointment` handler locks (`SELECT ... FOR
  UPDATE`) any existing appointment for that doctor/date/time inside a
  transaction before inserting, so two people booking the exact same
  slot at the same moment can't both succeed, even if they bypass the
  JS entirely.
- This is still same-day fixed slots (`APPT_SLOTS`), not a real
  calendar/availability system — a doctor can't yet mark themselves
  unavailable on a given day, and there's no per-doctor working-hours
  configuration. That would be the next step if you want to go further.

## New: Per-department queues
- `queue_state` now holds **one row per department** instead of a
  single global row — see the migration note above if you're upgrading.
- `queue_helpers.php` is the one place that reads/writes `queue_state`;
  every page goes through it so they can't disagree with each other.
- Ticket numbers now reset **per department per day** rather than one
  hospital-wide counter, so e.g. Cardiology and Neurology both get to
  start at ticket #1 on the same morning.
- `admin.php`'s Queue Controller now shows one card per department: a
  doctor only ever sees and controls their own department's card; an
  admin sees a card for every department that currently has a doctor
  assigned, and can call/reset each independently.
- `queue_status.php` (the JSON endpoint the browser polls every 5s)
  now takes a `?department=` parameter. A patient's own department is
  always derived server-side from their upcoming appointment (never
  trusted from the query string); a doctor's is always forced to their
  own department; only an admin's request can ask for any department.

## If something doesn't work
- **"Database connection failed"** → check `db.php` credentials and
  that MySQL is running in XAMPP.
- **Blank white page** → open XAMPP's PHP error log, or temporarily
  add `ini_set('display_errors', 1); error_reporting(E_ALL);` at the
  very top of the file that's failing.
- **"No patient profile found"** → you're logged in as a user whose
  role is patient but who has no row in the `patients` table (this
  shouldn't happen if you used `register.php` or `seed.php`).
