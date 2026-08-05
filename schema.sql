-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 05, 2026 at 07:06 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `healthcore`
--

-- --------------------------------------------------------

--
-- Table structure for table `allergies`
--

CREATE TABLE `allergies` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `name` varchar(120) NOT NULL,
  `severity` enum('Mild','Moderate','Severe') DEFAULT 'Mild'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `allergies`
--

INSERT INTO `allergies` (`id`, `patient_id`, `name`, `severity`) VALUES
(1, 1, 'Penicillin', 'Severe'),
(2, 1, 'Peanuts', 'Mild');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `department` varchar(100) NOT NULL,
  `appt_date` date NOT NULL,
  `appt_time` varchar(20) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('Pending','Confirmed','Arrived','Completed','Cancelled') DEFAULT 'Confirmed',
  `ticket_number` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `patient_id`, `doctor_id`, `department`, `appt_date`, `appt_time`, `reason`, `status`, `ticket_number`, `created_at`) VALUES
(2, 3, 1, 'Cardiology Department', '2026-08-21', '10:30 AM', 'i sakit', 'Cancelled', 68, '2026-08-05 01:15:54'),
(3, 3, 3, 'Cardiology Department', '2026-08-28', '02:00 PM', '', 'Cancelled', 1, '2026-08-05 02:49:10'),
(7, 3, 2, 'ENT Department', '2026-08-05', '02:00 PM', '', 'Completed', 1, '2026-08-05 03:30:42'),
(8, 1, 2, 'ENT Department', '2026-08-05', '09:00 AM', '', 'Completed', 2, '2026-08-05 04:07:18'),
(9, 1, 2, 'ENT Department', '2026-08-05', '10:30 AM', '', 'Completed', 3, '2026-08-05 04:15:56');

-- --------------------------------------------------------

--
-- Table structure for table `conditions`
--

CREATE TABLE `conditions` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `conditions`
--

INSERT INTO `conditions` (`id`, `patient_id`, `name`) VALUES
(1, 1, 'Mild Hypertension'),
(2, 1, 'Seasonal Asthma');

-- --------------------------------------------------------

--
-- Table structure for table `doctors`
--

CREATE TABLE `doctors` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `doctor_code` varchar(20) NOT NULL,
  `department` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctors`
--

INSERT INTO `doctors` (`id`, `user_id`, `doctor_code`, `department`) VALUES
(1, 2, 'DOC-882', 'Cardiology'),
(2, 6, 'DR-22021', 'ENT'),
(3, 7, 'DR-58567', 'Cardiology'),
(4, 8, 'DR-97001', 'Neurology');

-- --------------------------------------------------------

--
-- Table structure for table `medical_records`
--

CREATE TABLE `medical_records` (
  `id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `notes` text DEFAULT NULL,
  `record_date` date NOT NULL,
  `status` enum('Verified','Under Review') DEFAULT 'Under Review',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `medical_records`
--

INSERT INTO `medical_records` (`id`, `patient_id`, `doctor_id`, `title`, `notes`, `record_date`, `status`, `created_at`) VALUES
(1, 1, 1, 'Comprehensive Blood Panel', 'All values within normal range.', '2026-01-24', 'Verified', '2026-08-05 01:10:51'),
(2, 1, 1, 'ECG & Cardiology Evaluation', 'Sinus rhythm, no abnormalities detected.', '2025-12-10', 'Verified', '2026-08-05 01:10:51'),
(3, 3, 1, 'sakit hati', 'tiada sebab', '2026-08-05', 'Verified', '2026-08-05 01:54:19'),
(5, 3, 2, 'ent', 'ent', '2026-08-05', 'Under Review', '2026-08-05 03:36:19');

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `patient_code` varchar(20) NOT NULL,
  `dob` date DEFAULT NULL,
  `gender` enum('Male','Female','Other') DEFAULT 'Other',
  `blood_type` varchar(5) DEFAULT NULL,
  `height_cm` int(11) DEFAULT NULL,
  `weight_kg` int(11) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `emergency_name` varchar(120) DEFAULT NULL,
  `emergency_phone` varchar(30) DEFAULT NULL,
  `insurance_provider` varchar(150) DEFAULT NULL,
  `insurance_policy_no` varchar(60) DEFAULT NULL,
  `primary_doctor_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`id`, `user_id`, `patient_code`, `dob`, `gender`, `blood_type`, `height_cm`, `weight_kg`, `address`, `emergency_name`, `emergency_phone`, `insurance_provider`, `insurance_policy_no`, `primary_doctor_id`) VALUES
(1, 3, 'HC-99210', '1992-03-14', 'Male', 'A+', 178, 74, '742 Evergreen Terrace, Springfield, OR', 'Mary Doe', '+1 (555) 876-5432', 'Blue Cross Healthcare', 'BC-88392019-X', 1),
(3, 5, 'HC-73483', NULL, 'Other', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `queue_state`
--

CREATE TABLE `queue_state` (
  `id` int(11) NOT NULL,
  `department` varchar(100) NOT NULL,
  `currently_serving` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `queue_state`
--

INSERT INTO `queue_state` (`id`, `department`, `currently_serving`, `updated_at`) VALUES
(1, 'ENT', 1, '2026-08-05 04:41:18'),
(2, 'Cardiology', 0, '2026-08-05 04:44:43'),
(3, 'Neurology', 0, '2026-08-05 04:45:49');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `role` enum('patient','doctor','admin') NOT NULL DEFAULT 'patient',
  `full_name` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sms_notif` tinyint(1) NOT NULL DEFAULT 1,
  `email_notif` tinyint(1) NOT NULL DEFAULT 1,
  `lab_notif` tinyint(1) NOT NULL DEFAULT 1,
  `two_factor` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role`, `full_name`, `email`, `password`, `phone`, `created_at`, `sms_notif`, `email_notif`, `lab_notif`, `two_factor`) VALUES
(1, 'admin', 'Staff Admin', 'admin@healthcore.com', '$2y$10$WC2hYDil9KBNkXjhsI9wZ.OzrlcMKjpp9ESnRKIuGqL2hakIT5VzC', '+1 (555) 000-0001', '2026-08-05 01:10:51', 1, 1, 1, 0),
(2, 'doctor', 'Dr. Sarah Lee', 'sarah.lee@healthcore.com', '$2y$10$WC2hYDil9KBNkXjhsI9wZ.OzrlcMKjpp9ESnRKIuGqL2hakIT5VzC', '+1 (555) 000-0002', '2026-08-05 01:10:51', 1, 1, 1, 0),
(3, 'patient', 'John Doe', 'john.doe@example.com', '$2y$10$WC2hYDil9KBNkXjhsI9wZ.OzrlcMKjpp9ESnRKIuGqL2hakIT5VzC', '+1 (555) 234-5678', '2026-08-05 01:10:51', 1, 1, 1, 0),
(5, 'patient', 'zhengyuan', 'zhengyuan@gmail.com', '$2y$10$3w3P3/UnDCfZ4Bg249EKK.3iTtChd0V3Xx9uCSH2P7Lc41PH4scT6', '012345678', '2026-08-05 01:15:23', 1, 1, 1, 0),
(6, 'doctor', 'Zoih', 'Zoih@gmail.com', '$2y$10$OajZ/NbUS1AHxto/qtK.s.J1ojhTojbQ8VKujkpfcX7ZzjYcywmzy', '0124567890', '2026-08-05 02:40:21', 1, 1, 1, 0),
(7, 'doctor', 'hongzhi ooi', 'ooihongzhi0322@gmail.com', '$2y$10$AxcIr.VDv0kD8PpFuHZjmewPL/1Bn70jyy7rvnRymBeysm5MO0tFW', '0124567890', '2026-08-05 02:46:28', 1, 1, 1, 0),
(8, 'doctor', 'chin', 'chjin@mail.com', '$2y$10$r1F/Q1TghgGZMF67zSs3SOuvbGN6RprpTz0wYe.ZLoThaWLYEZLQW', '0124567890', '2026-08-05 04:45:34', 1, 1, 1, 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `allergies`
--
ALTER TABLE `allergies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `idx_appt_doctor_date_time` (`doctor_id`,`appt_date`,`appt_time`);

--
-- Indexes for table `conditions`
--
ALTER TABLE `conditions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `doctors`
--
ALTER TABLE `doctors`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `doctor_code` (`doctor_code`);

--
-- Indexes for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `doctor_id` (`doctor_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `patient_code` (`patient_code`),
  ADD KEY `primary_doctor_id` (`primary_doctor_id`);

--
-- Indexes for table `queue_state`
--
ALTER TABLE `queue_state`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `department` (`department`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `allergies`
--
ALTER TABLE `allergies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `conditions`
--
ALTER TABLE `conditions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `doctors`
--
ALTER TABLE `doctors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `medical_records`
--
ALTER TABLE `medical_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `queue_state`
--
ALTER TABLE `queue_state`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `allergies`
--
ALTER TABLE `allergies`
  ADD CONSTRAINT `allergies_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `conditions`
--
ALTER TABLE `conditions`
  ADD CONSTRAINT `conditions_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `doctors`
--
ALTER TABLE `doctors`
  ADD CONSTRAINT `doctors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `medical_records`
--
ALTER TABLE `medical_records`
  ADD CONSTRAINT `medical_records_ibfk_1` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `medical_records_ibfk_2` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `patients_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `patients_ibfk_2` FOREIGN KEY (`primary_doctor_id`) REFERENCES `doctors` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
