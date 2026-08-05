-- ============================================================
-- Healthcore Hospital Management System - Database Schema
-- Import this into phpMyAdmin / MySQL before running the PHP.
-- ============================================================

CREATE DATABASE IF NOT EXISTS healthcore CHARACTER SET utf8mb4;
USE healthcore;

-- ------------------------------------------------------------
-- 1. USERS (login table for patients, doctors and admins)
-- ------------------------------------------------------------
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    role          ENUM('patient','doctor','admin') NOT NULL DEFAULT 'patient',
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password      VARCHAR(255) NOT NULL,   -- stores password_hash()
    phone         VARCHAR(30),
    sms_notif     TINYINT(1) NOT NULL DEFAULT 1,
    email_notif   TINYINT(1) NOT NULL DEFAULT 1,
    lab_notif     TINYINT(1) NOT NULL DEFAULT 1,
    two_factor    TINYINT(1) NOT NULL DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- 2. DOCTORS (extra profile info for role='doctor')
-- ------------------------------------------------------------
CREATE TABLE doctors (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL UNIQUE,
    doctor_code   VARCHAR(20) NOT NULL UNIQUE,
    department    VARCHAR(100) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- 3. PATIENTS (extra profile info for role='patient')
-- ------------------------------------------------------------
CREATE TABLE patients (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL UNIQUE,
    patient_code        VARCHAR(20) NOT NULL UNIQUE,
    dob                 DATE NULL,
    gender              ENUM('Male','Female','Other') DEFAULT 'Other',
    blood_type          VARCHAR(5) DEFAULT NULL,
    height_cm           INT DEFAULT NULL,
    weight_kg           INT DEFAULT NULL,
    address             VARCHAR(255) DEFAULT NULL,
    emergency_name      VARCHAR(120) DEFAULT NULL,
    emergency_phone     VARCHAR(30) DEFAULT NULL,
    insurance_provider  VARCHAR(150) DEFAULT NULL,
    insurance_policy_no VARCHAR(60) DEFAULT NULL,
    primary_doctor_id   INT DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (primary_doctor_id) REFERENCES doctors(id) ON DELETE SET NULL
);

-- ------------------------------------------------------------
-- 4. ALLERGIES / CONDITIONS (shown on profile.php)
-- ------------------------------------------------------------
CREATE TABLE allergies (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT NOT NULL,
    name        VARCHAR(120) NOT NULL,
    severity    ENUM('Mild','Moderate','Severe') DEFAULT 'Mild',
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

CREATE TABLE conditions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT NOT NULL,
    name        VARCHAR(150) NOT NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- 5. APPOINTMENTS
-- ------------------------------------------------------------
CREATE TABLE appointments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT NOT NULL,
    doctor_id      INT NOT NULL,
    department     VARCHAR(100) NOT NULL,
    appt_date      DATE NOT NULL,
    appt_time      VARCHAR(20) NOT NULL,
    reason         VARCHAR(255) DEFAULT NULL,
    status         ENUM('Pending','Confirmed','Arrived','Completed','Cancelled') DEFAULT 'Confirmed',
    ticket_number  INT DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- 6. MEDICAL RECORDS (EMR)
-- ------------------------------------------------------------
CREATE TABLE medical_records (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    patient_id   INT NOT NULL,
    doctor_id    INT NOT NULL,
    title        VARCHAR(200) NOT NULL,
    notes        TEXT,
    record_date  DATE NOT NULL,
    status       ENUM('Verified','Under Review') DEFAULT 'Under Review',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
);

-- ------------------------------------------------------------
-- 7. QUEUE STATE (one live outpatient queue PER DEPARTMENT)
-- One row per department, created on demand by queue_helpers.php's
-- get_or_create_queue() the first time that department's queue is
-- touched — you don't need to pre-insert a row for every department.
-- ------------------------------------------------------------
CREATE TABLE queue_state (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    department         VARCHAR(100) NOT NULL UNIQUE,
    currently_serving  INT NOT NULL DEFAULT 0,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- Index to make the doctor-time-slot availability check
-- (get_slots.php / the book_appointment double-booking guard)
-- fast even as the appointments table grows.
-- ------------------------------------------------------------
CREATE INDEX idx_appt_doctor_date_time ON appointments (doctor_id, appt_date, appt_time);

-- ============================================================
-- No seed data here on purpose: passwords must go through PHP's
-- password_hash(), not raw SQL. Run seed.php once in your browser
-- after importing this file — it creates a demo admin, doctor and
-- patient account for you, then you can delete seed.php.
-- ============================================================
