# 🏥 Healthcore — Hospital Management Portal

A PHP + MySQL patient/staff portal with real-time appointment booking, per-department queue tracking, and role-based dashboards. No framework, no Composer, no build step — runs on plain Apache/PHP/MySQL (XAMPP-friendly).(HTML INCLUDED IN PHP FILE)

---

## 🚀 Quick Start (5 minutes)

| Step | What to do |
|---|---|
| 1 | Install **XAMPP** (or WAMP/MAMP) — bundles Apache, PHP and MySQL. |
| 2 | Copy this whole folder into your web root: `C:\xampp\htdocs\healthcore-php\` (Windows) or `/Applications/XAMPP/htdocs/healthcore-php/` (Mac). |
| 3 | Start **Apache** and **MySQL** in the XAMPP control panel. |
| 4 | Open `http://localhost/phpmyadmin` → **Import** → select `schema.sql` → **Go**. This creates the `healthcore` database and imports the demo accounts (see table below) along with sample appointments, records, and queue data. |
| 5 | Open `db.php` — defaults (`root` / no password / `localhost`) match a fresh XAMPP install. Only edit if yours differs. |
| 6 | Log in at `http://localhost/healthcore-php/login.php`. |

### Demo accounts (password: `password123` for all demo accounts)

| Email | Role | Lands on |
|---|---|---|
| `admin@healthcore.com` | Admin | `admin.php` — full staff dashboard |
| `sarah.lee@healthcore.com` | Doctor | `admin.php` — scoped to her department |
| `john.doe@example.com` | Patient | `index.php` — patient dashboard |

New patients can also self-register at `register.php`. Self-registering as a doctor requires the staff invite code set in `db.php` (`DOCTOR_INVITE_CODE`).

### 🎬 Suggested walkthrough (~2 min)

1. Log in as **patient** → book an appointment → watch the live "waiting" queue widget.
2. Log out, log in as **admin** → call the next ticket for that department, mark the appointment complete.
3. The patient's queue widget updates within 5 seconds — no page refresh (polls `queue_status.php`).



Something not working? Jump to **[Troubleshooting](#-troubleshooting)**.

---

## 📁 Project structure

| File | Purpose |
|---|---|
| `schema.sql` | Creates the `healthcore` database, all tables, and the demo accounts/sample data |
| `db.php` | MySQL connection + shared config (edit credentials here) |
| `auth.php` | Session/login helpers + CSRF protection (`csrf_field()` / `verify_csrf()`) |
| `login.php` / `register.php` / `logout.php` | Authentication |
| `index.php` | Patient dashboard — book/cancel appointments, queue status, records |
| `profile.php` | Patient profile |
| `settings.php` | Notification prefs, password change, 2FA toggle |
| `admin.php` | Staff/admin dashboard — appointments, queue control, records |
| `get_slots.php` | AJAX: which appointment slots are free for a doctor/date |
| `queue_status.php` | AJAX: live per-department queue numbers (polled every 5s) |
| `queue_helpers.php` | Shared queue read/write logic used by every page |
| `style.css` / `script.js` | Front end |

> **Note:** Each `.php` page (e.g. `login.php`, `index.php`, `admin.php`, `settings.php`) already contains its own HTML markup inline — the PHP logic and the page's HTML output live in the same file, so there's no separate `.html` template to copy or edit.

> **Security note:** the old static `admin.html` / `index.html` / `profile.html` / `settings.html` files have been removed. They had no login check — anyone could view the staff admin UI directly, and "Logout" didn't end a session. The `.php` pages are now the only versions, and every one is gated by `auth.php`'s `require_role()`.

---

## 🔐 How the login/role system works

- One `users` table with a `role` column (`patient`, `doctor`, `admin`); `patients` and `doctors` tables hold role-specific fields linked via `user_id`.
- `auth.php`'s `require_role([...])` guards every protected page — wrong role bounces you to your own dashboard, logged-out bounces you to `login.php`.
- Passwords use PHP's `password_hash()` / `password_verify()` — never stored in plain text.
- Every data-changing form (login, register, book/cancel, profile, settings, admin actions) is protected by a CSRF token (`csrf_field()` + `verify_csrf()`), so another site can't silently submit forms on a logged-in user's behalf.
- The session cookie is `HttpOnly` + `SameSite=Lax`. Once served over HTTPS, uncomment `'secure' => true` in `auth.php`'s `session_set_cookie_params()`.
- Ticket numbers reset per calendar day, so queue counters never drift upward forever.

---


## 🆘 Troubleshooting

| Symptom | Fix |
|---|---|
| **"Database connection failed"** | Check `db.php` credentials and confirm MySQL is running in XAMPP. |
| **Blank white page** | Check XAMPP's PHP error log, or temporarily add `ini_set('display_errors', 1); error_reporting(E_ALL);` to the top of the failing file. |
| **"No patient profile found"** | You're logged in as a `patient`-role user with no row in the `patients` table — shouldn't happen via `register.php`. |

---

© 2026 Healthcore Hospital Management System
