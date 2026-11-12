-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 09, 2026 at 09:14 AM
-- Server version: 10.11.16-MariaDB
-- PHP Version: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bella_vista_oasis`
--

-- --------------------------------------------------------

--
-- Table structure for table `administrators`
--

CREATE TABLE `administrators` (
  `id` int(11) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'Administrator',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `remember_token` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `administrators`
--

INSERT INTO `administrators` (`id`, `full_name`, `email`, `password`, `role`, `status`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'Wellness Director', 'director.wellness@bellavista-oasis.com', '$2y$12$yeVpqsXeHDUx.LB9AunXquvOWK6NZkg0uaHVqsJFNMb0UZEScFQta', 'Director', 'active', NULL, '2026-11-06 13:50:56', '2026-11-06 13:50:56');

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `therapist_id` int(11) DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `appointment_status` varchar(30) DEFAULT 'pending',
  `payment_status` varchar(30) DEFAULT 'pending',
  `total_amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`id`, `customer_id`, `service_id`, `room_id`, `therapist_id`, `appointment_date`, `start_time`, `end_time`, `appointment_status`, `payment_status`, `total_amount`) VALUES
(1, 1, 1, 1, 1, '2026-11-07', '09:00:00', '10:30:00', 'completed', 'paid', 3200.00),
(2, 1, 2, 2, 2, '2026-10-26', '10:00:00', '11:00:00', 'completed', 'paid', 2400.00),
(3, 1, 3, 2, 1, '2026-11-08', '10:00:00', '11:30:00', 'confirmed', 'deposit', 3600.00),
(4, 2, 2, 1, 2, '2026-11-09', '11:00:00', '12:00:00', 'confirmed', 'pending', 2400.00),
(5, 2, 2, 2, 2, '2026-11-07', '10:00:00', '11:00:00', 'completed', 'paid', 2400.00),
(6, 2, 4, 4, 4, '2026-10-29', '12:00:00', '13:00:00', 'no_show', 'pending', 2100.00),
(7, 3, 5, 3, 3, '2026-10-23', '14:00:00', '16:00:00', 'completed', 'paid', 7800.00),
(8, 3, 5, 3, 3, '2026-11-07', '11:00:00', '13:00:00', 'completed', 'paid', 7800.00),
(9, 4, 1, 1, 1, '2026-10-31', '11:00:00', '12:30:00', 'completed', 'paid', 3200.00),
(10, 4, 4, 4, 4, '2026-11-07', '12:30:00', '13:30:00', 'confirmed', 'deposit', 2100.00),
(11, 5, 3, 2, 3, '2026-11-02', '16:00:00', '17:30:00', 'completed', 'paid', 3600.00),
(12, 5, 6, 1, 5, '2026-11-07', '13:30:00', '14:30:00', 'confirmed', 'paid', 2600.00),
(13, 6, 3, 2, 3, '2026-11-07', '14:00:00', '15:30:00', 'confirmed', 'deposit', 3600.00),
(14, 6, 6, 1, 5, '2026-11-03', '09:00:00', '10:00:00', 'cancelled', 'refunded', 2600.00),
(15, 7, 1, 1, 1, '2026-11-07', '15:30:00', '17:00:00', 'pending', 'pending', 3200.00),
(16, 7, 3, 2, 3, '2026-10-18', '11:00:00', '12:30:00', 'cancelled', 'refunded', 3600.00),
(17, 7, 4, 4, 4, '2026-11-06', '10:00:00', '11:00:00', 'completed', 'paid', 2100.00),
(18, 8, 2, 2, 2, '2026-11-06', '13:00:00', '14:00:00', 'completed', 'paid', 2400.00),
(19, 8, 2, 2, 2, '2026-11-07', '16:00:00', '17:00:00', 'confirmed', 'pending', 2400.00),
(20, 9, 1, 1, 1, '2026-11-05', '14:00:00', '15:30:00', 'no_show', 'pending', 3200.00),
(21, 9, 4, 4, 4, '2026-11-07', '17:00:00', '18:00:00', 'pending', 'pending', 2100.00),
(22, 10, 1, 1, 1, '2026-11-07', '18:30:00', '20:00:00', 'cancelled', 'refunded', 3200.00),
(23, 10, 5, 3, 3, '2026-11-04', '15:00:00', '17:00:00', 'completed', 'paid', 7800.00),
(24, 4, 2, 4, 2, '2026-11-08', '07:45:00', '08:45:00', 'confirmed', 'pending', 2400.00),
(25, 6, 4, 2, 4, '2026-11-08', '08:30:00', '09:30:00', 'confirmed', 'pending', 2100.00);

-- --------------------------------------------------------

--
-- Table structure for table `appointment_checkins`
--

CREATE TABLE `appointment_checkins` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `checkin_token_hash` char(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `verification_status` enum('pending','verified','invalid','expired','not_used') NOT NULL DEFAULT 'pending',
  `verified_at` datetime DEFAULT NULL,
  `checkin_method` enum('qr','manual') NOT NULL DEFAULT 'qr',
  `checkin_status` enum('pending','checked_in') NOT NULL DEFAULT 'pending',
  `checked_in_at` datetime DEFAULT NULL,
  `checked_in_by` int(11) DEFAULT NULL,
  `checked_in_flag` tinyint(1) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `appointment_checkins`
--

INSERT INTO `appointment_checkins` (`id`, `appointment_id`, `checkin_token_hash`, `token_expires_at`, `verification_status`, `verified_at`, `checkin_method`, `checkin_status`, `checked_in_at`, `checked_in_by`, `checked_in_flag`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 4, '17e130eaa1509152ddc5f86e4c68183420cee0890441065b50f3cd720868a12c', '2026-11-09 12:00:00', 'verified', '2026-11-09 09:32:22', 'qr', 'checked_in', '2026-11-09 09:32:34', 1, 1, 1, '2026-11-09 09:30:59', '2026-11-09 09:32:34');

-- --------------------------------------------------------

--
-- Table structure for table `checkin_activity_logs`
--

CREATE TABLE `checkin_activity_logs` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `activity_type` varchar(40) NOT NULL,
  `activity_details` varchar(500) DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `checkin_activity_logs`
--

INSERT INTO `checkin_activity_logs` (`id`, `appointment_id`, `activity_type`, `activity_details`, `processed_by`, `ip_address`, `created_at`) VALUES
(1, NULL, 'window_updated', 'Check-in window set to 60 min early / 30 min late; pending bookings not allowed.', 1, '::1', '2026-11-09 09:19:28'),
(2, 4, 'qr_generated', 'QR code issued, valid until Nov 9, 12:00 PM.', 1, '::1', '2026-11-09 09:30:59'),
(3, 4, 'guest_verified', 'BVO-0004: QR verified on the server; awaiting receptionist confirmation.', 1, '::1', '2026-11-09 09:32:22'),
(4, 4, 'arrival_confirmed', 'BVO-0004: guest checked in via QR scan (early arrival acknowledged by staff).', 1, '::1', '2026-11-09 09:32:34');

-- --------------------------------------------------------

--
-- Table structure for table `checkin_settings`
--

CREATE TABLE `checkin_settings` (
  `id` int(11) NOT NULL,
  `early_minutes` smallint(5) UNSIGNED NOT NULL DEFAULT 60,
  `late_minutes` smallint(5) UNSIGNED NOT NULL DEFAULT 30,
  `allow_pending` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `checkin_settings`
--

INSERT INTO `checkin_settings` (`id`, `early_minutes`, `late_minutes`, `allow_pending`, `updated_by`, `updated_at`) VALUES
(1, 60, 30, 0, 1, '2026-11-09 09:19:28');

-- --------------------------------------------------------

--
-- Table structure for table `climate_settings`
--

CREATE TABLE `climate_settings` (
  `id` int(11) NOT NULL,
  `minimum_temperature` decimal(4,1) NOT NULL DEFAULT 22.0,
  `maximum_temperature` decimal(4,1) NOT NULL DEFAULT 26.0,
  `minimum_humidity` decimal(4,1) NOT NULL DEFAULT 40.0,
  `maximum_humidity` decimal(4,1) NOT NULL DEFAULT 60.0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `climate_settings`
--

INSERT INTO `climate_settings` (`id`, `minimum_temperature`, `maximum_temperature`, `minimum_humidity`, `maximum_humidity`, `updated_at`) VALUES
(1, 22.0, 26.0, 40.0, 60.0, '2026-11-08 19:18:34');

-- --------------------------------------------------------

--
-- Table structure for table `configuration_logs`
--

CREATE TABLE `configuration_logs` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `setting_name` varchar(120) NOT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `first_name` varchar(80) DEFAULT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `customer_status` varchar(20) NOT NULL DEFAULT 'active',
  `registered_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `preferences` text DEFAULT NULL,
  `special_requests` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `full_name`, `first_name`, `middle_name`, `last_name`, `email`, `phone`, `date_of_birth`, `gender`, `address`, `profile_photo`, `customer_status`, `registered_at`, `notes`, `preferences`, `special_requests`, `updated_at`) VALUES
(1, 'Sophia Laurent', NULL, NULL, NULL, 'sophia.laurent@example.com', '0917 555 0101', NULL, NULL, NULL, NULL, 'active', '2026-10-26 00:00:00', NULL, NULL, NULL, NULL),
(2, 'Marcus Vance', NULL, NULL, NULL, 'marcus.vance@example.com', '0917 555 0102', NULL, NULL, NULL, NULL, 'active', '2026-10-29 00:00:00', NULL, NULL, NULL, NULL),
(3, 'Elena & Rodrigo Sen', NULL, NULL, NULL, 'sen.couple@example.com', '0917 555 0103', NULL, NULL, NULL, NULL, 'active', '2026-10-23 00:00:00', NULL, NULL, NULL, NULL),
(4, 'Camille Dupont', NULL, NULL, NULL, 'camille.dupont@example.com', '0917 555 0104', NULL, NULL, NULL, NULL, 'active', '2026-10-31 00:00:00', NULL, NULL, NULL, NULL),
(5, 'Isabelle Cruz', NULL, NULL, NULL, 'isabelle.cruz@example.com', '0917 555 0105', NULL, NULL, NULL, NULL, 'active', '2026-11-02 00:00:00', NULL, NULL, NULL, NULL),
(6, 'Thomas Reid', NULL, NULL, NULL, 'thomas.reid@example.com', '0917 555 0106', NULL, NULL, NULL, NULL, 'active', '2026-11-03 00:00:00', NULL, NULL, NULL, NULL),
(7, 'Hana Kobayashi', NULL, NULL, NULL, 'hana.k@example.com', '0917 555 0107', NULL, NULL, NULL, NULL, 'active', '2026-10-18 00:00:00', NULL, NULL, NULL, NULL),
(8, 'Diego Alvarez', NULL, NULL, NULL, 'diego.alvarez@example.com', '0917 555 0108', NULL, NULL, NULL, NULL, 'active', '2026-11-06 00:00:00', NULL, NULL, NULL, NULL),
(9, 'Priya Nair', NULL, NULL, NULL, 'priya.nair@example.com', '0917 555 0109', NULL, NULL, NULL, NULL, 'active', '2026-11-05 00:00:00', NULL, NULL, NULL, NULL),
(10, 'Olivia Bennett', NULL, NULL, NULL, 'olivia.bennett@example.com', '0917 555 0110', NULL, NULL, NULL, NULL, 'active', '2026-11-04 00:00:00', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `gift_certificates`
--

CREATE TABLE `gift_certificates` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `recipient_name` varchar(150) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `gift_certificates`
--

INSERT INTO `gift_certificates` (`id`, `code`, `recipient_name`, `amount`, `expiry_date`) VALUES
(1, 'BVO-GIFT0001', 'Olivia Bennett', 5000.00, '2027-05-06'),
(2, 'BVO-GIFT0002', 'Priya Nair', 2500.00, '2027-02-05');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_attempt` datetime NOT NULL,
  `locked_until` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loyalty_settings`
--

CREATE TABLE `loyalty_settings` (
  `setting_key` varchar(40) NOT NULL,
  `setting_value` varchar(120) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `loyalty_settings`
--

INSERT INTO `loyalty_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('auto_award', '1', '2026-11-09 00:10:22'),
('membership_term_months', '12', '2026-11-09 00:10:22'),
('peso_per_point', '100', '2026-11-09 00:10:22');

-- --------------------------------------------------------

--
-- Table structure for table `loyalty_transactions`
--

CREATE TABLE `loyalty_transactions` (
  `id` int(11) NOT NULL,
  `transaction_code` varchar(30) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `transaction_type` varchar(20) NOT NULL,
  `points` int(11) NOT NULL,
  `reference_type` varchar(30) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'completed',
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `loyalty_transactions`
--

INSERT INTO `loyalty_transactions` (`id`, `transaction_code`, `customer_id`, `transaction_type`, `points`, `reference_type`, `reference_id`, `description`, `notes`, `status`, `processed_by`, `created_at`) VALUES
(1, 'LT-261109-68F3B3', 1, 'earned', 48, 'payment', 1, 'Completed appointment BVO-0001 · Gold 1.5x', NULL, 'completed', NULL, '2026-11-07 01:00:00'),
(2, 'LT-261109-409ED6', 1, 'earned', 36, 'payment', 2, 'Completed appointment BVO-0002 · Gold 1.5x', NULL, 'completed', NULL, '2026-10-26 02:00:00'),
(3, 'LT-261109-355BCC', 2, 'earned', 30, 'payment', 3, 'Completed appointment BVO-0005 · Silver 1.25x', NULL, 'completed', NULL, '2026-11-07 02:00:00'),
(4, 'LT-261109-4B548A', 3, 'earned', 156, 'payment', 4, 'Completed appointment BVO-0007 · Platinum 2x', NULL, 'completed', NULL, '2026-10-23 06:00:00'),
(5, 'LT-261109-7FB514', 3, 'earned', 156, 'payment', 5, 'Completed appointment BVO-0008 · Platinum 2x', NULL, 'completed', NULL, '2026-11-07 03:00:00'),
(6, 'LT-261109-25FE0A', 4, 'earned', 40, 'payment', 6, 'Completed appointment BVO-0009 · Silver 1.25x', NULL, 'completed', NULL, '2026-10-31 03:00:00'),
(7, 'LT-261109-5A0076', 5, 'earned', 54, 'payment', 7, 'Completed appointment BVO-0011 · Gold 1.5x', NULL, 'completed', NULL, '2026-11-02 08:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `memberships`
--

CREATE TABLE `memberships` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `membership_type` varchar(50) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `membership_status` varchar(30) DEFAULT 'active',
  `tier_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `memberships`
--

INSERT INTO `memberships` (`id`, `customer_id`, `membership_type`, `start_date`, `expiry_date`, `membership_status`, `tier_id`) VALUES
(1, 1, 'Gold', '2026-07-10', '2027-07-10', 'active', 3),
(2, 2, 'Silver', '2026-09-08', '2026-11-27', 'active', 2),
(3, 3, 'Platinum', '2026-04-21', '2027-04-21', 'active', 4),
(4, 4, 'Silver', '2026-08-09', '2027-08-09', 'active', 2),
(5, 5, 'Gold', '2026-10-08', '2027-10-08', 'active', 3);

-- --------------------------------------------------------

--
-- Table structure for table `membership_history`
--

CREATE TABLE `membership_history` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `previous_tier_id` int(11) DEFAULT NULL,
  `new_tier_id` int(11) DEFAULT NULL,
  `change_type` varchar(20) NOT NULL DEFAULT 'change',
  `changed_by` int(11) DEFAULT NULL,
  `change_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `membership_tiers`
--

CREATE TABLE `membership_tiers` (
  `id` int(11) NOT NULL,
  `tier_name` varchar(60) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `minimum_spending` decimal(12,2) NOT NULL DEFAULT 0.00,
  `minimum_points` int(11) NOT NULL DEFAULT 0,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `points_multiplier` decimal(4,2) NOT NULL DEFAULT 1.00,
  `tier_color` varchar(9) NOT NULL DEFAULT '#C4B58D',
  `benefits` text DEFAULT NULL,
  `privileges` text DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `membership_tiers`
--

INSERT INTO `membership_tiers` (`id`, `tier_name`, `description`, `minimum_spending`, `minimum_points`, `discount_percentage`, `points_multiplier`, `tier_color`, `benefits`, `privileges`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Bronze', 'Entry membership for returning guests.', 0.00, 0, 3.00, 1.00, '#B08968', 'Member-only promotions\nBirthday greeting', 'Standard booking', 1, 'active', '2026-11-09 00:10:22', '2026-11-09 00:10:22'),
(2, 'Silver', 'For guests who visit regularly.', 5000.00, 0, 5.00, 1.25, '#A3AA91', 'Treatment discount\n1.25x loyalty points\nBirthday add-on', 'Priority booking', 2, 'active', '2026-11-09 00:10:22', '2026-11-09 00:10:22'),
(3, 'Gold', 'For loyal guests with frequent treatments.', 15000.00, 0, 10.00, 1.50, '#C4B58D', 'Treatment discount\n1.5x loyalty points\nBirthday treatment upgrade\nExclusive promotions', 'Priority booking\nComplimentary welcome drink', 3, 'active', '2026-11-09 00:10:22', '2026-11-09 00:10:22'),
(4, 'Platinum', 'Top tier for our most valued guests.', 30000.00, 0, 15.00, 2.00, '#173D30', 'Treatment discount\n2x loyalty points\nBirthday treatment\nExclusive promotions\nWellness upgrade', 'Priority booking\nPrivate suite preference\nDedicated concierge', 4, 'active', '2026-11-09 00:10:22', '2026-11-09 00:10:22');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `admin_id`, `title`, `message`, `created_at`) VALUES
(1, NULL, 'Pending booking requests', 'Several appointments are waiting for confirmation.', '2026-11-06 18:16:09'),
(2, NULL, 'Suite maintenance', 'Bamboo Suite 6 is marked under maintenance.', '2026-11-06 18:16:09'),
(3, NULL, 'Guest checked in', 'Marcus Vance checked in for Deep Tissue Therapy (Jade Suite 1) via QR scan.', '2026-11-09 01:32:34');

-- --------------------------------------------------------

--
-- Table structure for table `operational_announcements`
--

CREATE TABLE `operational_announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` varchar(30) DEFAULT 'normal'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `operational_announcements`
--

INSERT INTO `operational_announcements` (`id`, `title`, `description`, `priority`) VALUES
(1, 'Mandatory 30-min Cleaning Gap', 'Allow a 30-minute reset between every suite booking for sanitation and linen change.', 'high'),
(2, 'Guest Pre-Arrival Window', 'Confirm each guest 24 hours ahead and note allergies or pressure preferences.', 'normal'),
(3, 'Therapist Hygiene Standard', 'Fresh uniform, sanitized hands and prepared oils before every session.', 'normal');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_status` varchar(30) DEFAULT 'pending',
  `payment_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `appointment_id`, `customer_id`, `amount`, `payment_method`, `payment_status`, `payment_date`) VALUES
(1, 1, 1, 3200.00, 'GCash', 'paid', '2026-11-07 09:00:00'),
(2, 2, 1, 2400.00, 'GCash', 'paid', '2026-10-26 10:00:00'),
(3, 5, 2, 2400.00, 'GCash', 'paid', '2026-11-07 10:00:00'),
(4, 7, 3, 7800.00, 'GCash', 'paid', '2026-10-23 14:00:00'),
(5, 8, 3, 7800.00, 'GCash', 'paid', '2026-11-07 11:00:00'),
(6, 9, 4, 3200.00, 'GCash', 'paid', '2026-10-31 11:00:00'),
(7, 11, 5, 3600.00, 'GCash', 'paid', '2026-11-02 16:00:00'),
(8, 12, 5, 2600.00, 'GCash', 'paid', '2026-11-07 13:30:00'),
(9, 17, 7, 2100.00, 'GCash', 'paid', '2026-11-06 10:00:00'),
(10, 18, 8, 2400.00, 'GCash', 'paid', '2026-11-06 13:00:00'),
(11, 23, 10, 7800.00, 'GCash', 'paid', '2026-11-04 15:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `rewards`
--

CREATE TABLE `rewards` (
  `id` int(11) NOT NULL,
  `reward_name` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `reward_image` varchar(80) DEFAULT NULL,
  `reward_category` varchar(40) NOT NULL DEFAULT 'Add-on',
  `required_points` int(11) NOT NULL,
  `available_quantity` int(11) DEFAULT NULL,
  `redeemed_count` int(11) NOT NULL DEFAULT 0,
  `maximum_per_member` int(11) DEFAULT NULL,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `minimum_tier_id` int(11) DEFAULT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rewards`
--

INSERT INTO `rewards` (`id`, `reward_name`, `description`, `reward_image`, `reward_category`, `required_points`, `available_quantity`, `redeemed_count`, `maximum_per_member`, `valid_from`, `valid_until`, `minimum_tier_id`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Spa Treatment Discount', 'Enjoy ₱200 off your next eligible spa treatment.', 'rw_271ea674113b891e.png', 'Discount', 200, 12, 0, 1, '2026-11-09', '2026-12-09', 1, 'active', '2026-11-09 00:10:22', '2026-11-09 00:32:31'),
(2, 'Signature Treatment Discount', 'Discount on a signature treatment.', NULL, 'Discount', 250, NULL, 0, 2, NULL, NULL, NULL, 'active', '2026-11-09 00:10:22', '2026-11-09 00:10:22'),
(3, 'Complimentary Wellness Upgrade', 'Upgrade your session to the next wellness tier.', NULL, 'Upgrade', 400, NULL, 0, 1, NULL, NULL, NULL, 'active', '2026-11-09 00:10:22', '2026-11-09 00:10:22'),
(4, 'Exclusive Spa Experience', 'A private curated spa experience.', NULL, 'Experience', 800, 20, 0, 1, NULL, NULL, NULL, 'active', '2026-11-09 00:10:22', '2026-11-09 00:10:22');

-- --------------------------------------------------------

--
-- Table structure for table `reward_redemptions`
--

CREATE TABLE `reward_redemptions` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `reward_id` int(11) NOT NULL,
  `points_used` int(11) NOT NULL,
  `redemption_reference` varchar(30) NOT NULL,
  `request_token` varchar(64) DEFAULT NULL,
  `redemption_status` varchar(12) NOT NULL DEFAULT 'completed',
  `transaction_id` int(11) DEFAULT NULL,
  `redeemed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reward_tiers`
--

CREATE TABLE `reward_tiers` (
  `reward_id` int(11) NOT NULL,
  `tier_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `reward_tiers`
--

INSERT INTO `reward_tiers` (`reward_id`, `tier_id`) VALUES
(1, 4);

-- --------------------------------------------------------

--
-- Table structure for table `rooms`
--

CREATE TABLE `rooms` (
  `id` int(11) NOT NULL,
  `room_name` varchar(150) NOT NULL,
  `room_type` varchar(100) DEFAULT NULL,
  `room_status` varchar(30) DEFAULT 'available',
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `rooms`
--

INSERT INTO `rooms` (`id`, `room_name`, `room_type`, `room_status`, `description`) VALUES
(1, 'Jade Suite 1', 'Single Massage Suite', 'available', 'Stone-wall single suite'),
(2, 'Cypress Suite 2', 'Deep Tissue Suite', 'available', 'Quiet suite with garden view'),
(3, 'Family Soul Lounge 3', 'Couples Suite', 'available', 'Double suite with soaking tub'),
(4, 'Lotus Facial Room', 'Facial Studio', 'available', 'Soft-light facial studio'),
(5, 'Sandalwood Suite 5', 'Hot Stone Suite', 'cleaning', 'Heated stone station'),
(6, 'Bamboo Suite 6', 'Single Massage Suite', 'available', 'Under repair');

-- --------------------------------------------------------

--
-- Table structure for table `room_climate_logs`
--

CREATE TABLE `room_climate_logs` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `temperature` decimal(4,1) NOT NULL,
  `humidity` decimal(4,1) NOT NULL,
  `condition_notes` varchar(500) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `recorded_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `room_climate_logs`
--

INSERT INTO `room_climate_logs` (`id`, `room_id`, `temperature`, `humidity`, `condition_notes`, `recorded_by`, `recorded_at`) VALUES
(1, 1, 12.0, 15.0, NULL, 1, '2026-11-08 19:43:00'),
(2, 1, 15.0, 51.0, NULL, 1, '2026-11-08 19:46:00'),
(3, 1, 23.5, 48.0, 'Comfortable room condition; within target range.', 1, '2026-11-08 08:00:00'),
(4, 2, 24.0, 50.0, 'Stable temperature and humidity.', 1, '2026-11-08 08:05:00'),
(5, 3, 23.0, 52.0, 'Comfortable for couples treatment.', 1, '2026-11-08 08:10:00'),
(6, 4, 24.5, 45.0, 'Facial room condition is normal.', 1, '2026-11-08 08:15:00'),
(7, 5, 25.0, 55.0, 'Room is being prepared for cleaning and reset.', 1, '2026-11-08 08:20:00'),
(8, 6, 26.5, 62.0, 'Room is under maintenance; inspect ventilation before reopening.', 1, '2026-11-08 08:25:00'),
(9, 1, 11.8, 4.0, NULL, 1, '2026-11-09 03:16:00'),
(10, 1, 11.6, 1.8, NULL, 1, '2026-11-09 03:17:00'),
(11, 1, 22.0, 26.0, NULL, 1, '2026-11-09 03:17:00');

-- --------------------------------------------------------

--
-- Table structure for table `room_details`
--

CREATE TABLE `room_details` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `amenities` varchar(500) DEFAULT NULL,
  `maximum_capacity` smallint(6) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `room_details`
--

INSERT INTO `room_details` (`id`, `room_id`, `amenities`, `maximum_capacity`) VALUES
(1, 1, 'Massage bed, aromatherapy diffuser, towel warmer, private shower', 1),
(2, 2, 'Deep tissue massage bed, garden view, sound system, private shower', 1),
(3, 3, 'Two massage beds, soaking tub, lounge seating, tea station', 2),
(4, 4, 'Facial bed, magnifying lamp, skincare station, soft lighting', 1),
(5, 5, 'Heated stone station, massage bed, towel warmer, ambient lighting', 1),
(6, 6, 'Massage bed, storage cabinet, private shower', 1);

-- --------------------------------------------------------

--
-- Table structure for table `room_maintenance_logs`
--

CREATE TABLE `room_maintenance_logs` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `maintenance_title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `maintenance_status` varchar(20) NOT NULL DEFAULT 'scheduled',
  `scheduled_date` date NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `room_maintenance_logs`
--

INSERT INTO `room_maintenance_logs` (`id`, `room_id`, `maintenance_title`, `description`, `maintenance_status`, `scheduled_date`, `completed_at`, `created_by`, `created_at`) VALUES
(1, 5, 'Post-treatment cleaning and linen reset', 'Replace used linens, sanitize surfaces, and prepare the room for the next guest.', 'completed', '2026-11-08', '2026-11-09 03:06:35', 1, '2026-11-08 11:52:11'),
(2, 6, 'Repair ventilation and inspect air-conditioning', 'Check the ventilation system and air-conditioning unit. Keep the suite unavailable until inspection is complete.', 'completed', '2026-11-08', '2026-11-09 03:05:59', 1, '2026-11-08 11:52:11'),
(3, 1, 'Routine equipment inspection', 'Inspect massage bed, diffuser, towel warmer, and electrical connections.', 'completed', '2026-11-10', '2026-11-09 03:06:30', 1, '2026-11-08 11:52:11'),
(4, 4, 'Facial equipment sanitation', 'Deep-clean facial equipment and verify the magnifying lamp.', 'completed', '2026-11-11', '2026-11-09 03:06:29', 1, '2026-11-08 11:52:11');

-- --------------------------------------------------------

--
-- Table structure for table `room_pricing`
--

CREATE TABLE `room_pricing` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `rate_type` varchar(30) NOT NULL DEFAULT 'per_session',
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(11) NOT NULL,
  `service_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `duration` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `service_name`, `description`, `duration`, `price`, `created_at`) VALUES
(1, 'Signature Aromatherapy Massage', 'Full-body massage with essential oils', 90, 3200.00, '2026-11-06 18:15:15'),
(2, 'Deep Tissue Therapy', 'Targeted muscle release', 60, 2400.00, '2026-11-06 18:15:15'),
(3, 'Hot Stone Ritual', 'Heated basalt stone massage', 90, 3600.00, '2026-11-06 18:15:15'),
(4, 'Botanical Facial', 'Cleansing and hydrating facial', 60, 2100.00, '2026-11-06 18:15:15'),
(5, 'Couples Sanctuary Retreat', 'Shared suite, massage and tea ceremony', 120, 7800.00, '2026-11-06 18:15:15'),
(6, 'Prenatal Comfort Massage', 'Gentle massage for expectant mothers', 60, 2600.00, '2026-11-06 18:15:15');

-- --------------------------------------------------------

--
-- Table structure for table `spa_configuration`
--

CREATE TABLE `spa_configuration` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(80) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `spa_configuration`
--

INSERT INTO `spa_configuration` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'cleaning_gap_enabled', '1', '2026-11-09 15:57:09'),
(2, 'cleaning_gap_minutes', '30', '2026-11-09 15:57:09'),
(3, 'cancellation_notice_hours', '48', '2026-11-09 15:57:09'),
(4, 'hvac_automation', '1', '2026-11-09 15:57:09'),
(5, 'housekeeping_standard', '1', '2026-11-09 15:57:09'),
(6, 'housekeeping_note', 'Surfaces sanitized, linens replaced and oils restocked before the next guest.', '2026-11-09 15:57:09'),
(7, 'cleaning_verification', '1', '2026-11-09 15:57:09'),
(8, 'equipment_readiness', '1', '2026-11-09 15:57:09'),
(9, 'block_maintenance_rooms', '1', '2026-11-09 15:57:09');

-- --------------------------------------------------------

--
-- Table structure for table `spa_operating_hours`
--

CREATE TABLE `spa_operating_hours` (
  `id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL COMMENT '1 = Monday ... 7 = Sunday',
  `opening_time` time NOT NULL DEFAULT '09:00:00',
  `closing_time` time NOT NULL DEFAULT '21:00:00',
  `is_open` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `spa_operating_hours`
--

INSERT INTO `spa_operating_hours` (`id`, `day_of_week`, `opening_time`, `closing_time`, `is_open`, `updated_at`) VALUES
(1, 1, '09:00:00', '21:00:00', 1, '2026-11-09 15:57:09'),
(2, 2, '09:00:00', '21:00:00', 1, '2026-11-09 15:57:09'),
(3, 3, '09:00:00', '21:00:00', 1, '2026-11-09 15:57:09'),
(4, 4, '09:00:00', '21:00:00', 1, '2026-11-09 15:57:09'),
(5, 5, '09:00:00', '21:00:00', 1, '2026-11-09 15:57:09'),
(6, 6, '09:00:00', '21:00:00', 1, '2026-11-09 15:57:09'),
(7, 7, '09:00:00', '21:00:00', 1, '2026-11-09 15:57:09');

-- --------------------------------------------------------

--
-- Table structure for table `therapists`
--

CREATE TABLE `therapists` (
  `id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `specialization` varchar(150) DEFAULT NULL,
  `availability_status` varchar(30) DEFAULT 'available',
  `first_name` varchar(80) DEFAULT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(300) DEFAULT NULL,
  `profile_photo` varchar(120) DEFAULT NULL,
  `years_experience` smallint(6) DEFAULT NULL,
  `certifications` varchar(500) DEFAULT NULL,
  `employment_date` date DEFAULT NULL,
  `employment_type` varchar(20) DEFAULT NULL,
  `employment_status` varchar(20) NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `therapists`
--

INSERT INTO `therapists` (`id`, `full_name`, `specialization`, `availability_status`, `first_name`, `middle_name`, `last_name`, `email`, `phone`, `address`, `profile_photo`, `years_experience`, `certifications`, `employment_date`, `employment_type`, `employment_status`) VALUES
(1, 'Maria Santos', 'Aromatherapy & Swedish', 'available', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active'),
(2, 'Jerome Reyes', 'Deep Tissue & Sports', 'available', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active'),
(3, 'Liza Mercado', 'Hot Stone & Reflexology', 'available', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active'),
(4, 'Carlo Dizon', 'Facials & Skin Care', 'available', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active'),
(5, 'Aya Tanaka', 'Prenatal & Shiatsu', 'available', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active'),
(6, 'Nico Villanueva', 'Deep Tissue', 'available', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `therapist_assignment_logs`
--

CREATE TABLE `therapist_assignment_logs` (
  `id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `previous_therapist_id` int(11) DEFAULT NULL,
  `new_therapist_id` int(11) DEFAULT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `therapist_assignment_logs`
--

INSERT INTO `therapist_assignment_logs` (`id`, `appointment_id`, `previous_therapist_id`, `new_therapist_id`, `assigned_by`, `notes`, `created_at`) VALUES
(1, 24, 5, 2, 1, NULL, '2026-11-08 12:56:45'),
(2, 3, 3, 1, 1, 'Override: not a specialization match.', '2026-11-08 12:59:07');

-- --------------------------------------------------------

--
-- Table structure for table `therapist_capacity`
--

CREATE TABLE `therapist_capacity` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `maximum_sessions` smallint(6) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `therapist_leaves`
--

CREATE TABLE `therapist_leaves` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `reason` varchar(300) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `therapist_shifts`
--

CREATE TABLE `therapist_shifts` (
  `id` int(11) NOT NULL,
  `therapist_id` int(11) NOT NULL,
  `day_of_week` tinyint(4) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `administrators`
--
ALTER TABLE `administrators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `appointment_checkins`
--
ALTER TABLE `appointment_checkins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_checkin_token` (`checkin_token_hash`),
  ADD UNIQUE KEY `uq_checkin_once` (`appointment_id`,`checked_in_flag`),
  ADD KEY `idx_checkin_when` (`checked_in_at`),
  ADD KEY `fk_checkin_by` (`checked_in_by`),
  ADD KEY `fk_checkin_creator` (`created_by`);

--
-- Indexes for table `checkin_activity_logs`
--
ALTER TABLE `checkin_activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_checkin_log_created` (`created_at`),
  ADD KEY `idx_checkin_log_appt` (`appointment_id`),
  ADD KEY `idx_checkin_log_type` (`activity_type`,`created_at`),
  ADD KEY `fk_checkin_log_admin` (`processed_by`);

--
-- Indexes for table `checkin_settings`
--
ALTER TABLE `checkin_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_checkin_settings_admin` (`updated_by`);

--
-- Indexes for table `climate_settings`
--
ALTER TABLE `climate_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `configuration_logs`
--
ALTER TABLE `configuration_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_changed` (`changed_at`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_customers_email` (`email`),
  ADD KEY `idx_customers_status` (`customer_status`);

--
-- Indexes for table `gift_certificates`
--
ALTER TABLE `gift_certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email_ip` (`email`,`ip_address`);

--
-- Indexes for table `loyalty_settings`
--
ALTER TABLE `loyalty_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `loyalty_transactions`
--
ALTER TABLE `loyalty_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_loyalty_code` (`transaction_code`),
  ADD UNIQUE KEY `uq_loyalty_ref` (`reference_type`,`reference_id`,`transaction_type`),
  ADD KEY `idx_loyalty_customer` (`customer_id`),
  ADD KEY `idx_loyalty_created` (`created_at`);

--
-- Indexes for table `memberships`
--
ALTER TABLE `memberships`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_memberships_customer` (`customer_id`),
  ADD KEY `idx_memberships_tier` (`tier_id`);

--
-- Indexes for table `membership_history`
--
ALTER TABLE `membership_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mhist_customer` (`customer_id`);

--
-- Indexes for table `membership_tiers`
--
ALTER TABLE `membership_tiers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tier_name` (`tier_name`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `operational_announcements`
--
ALTER TABLE `operational_announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token_hash`),
  ADD KEY `fk_reset_admin` (`admin_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rewards`
--
ALTER TABLE `rewards`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_reward_name` (`reward_name`);

--
-- Indexes for table `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_redemption_ref` (`redemption_reference`),
  ADD UNIQUE KEY `uq_redemption_token` (`request_token`),
  ADD KEY `idx_redemption_customer` (`customer_id`),
  ADD KEY `idx_redemption_reward` (`reward_id`);

--
-- Indexes for table `reward_tiers`
--
ALTER TABLE `reward_tiers`
  ADD PRIMARY KEY (`reward_id`,`tier_id`);

--
-- Indexes for table `rooms`
--
ALTER TABLE `rooms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `room_climate_logs`
--
ALTER TABLE `room_climate_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_climate_room_time` (`room_id`,`recorded_at`),
  ADD KEY `fk_climate_admin` (`recorded_by`);

--
-- Indexes for table `room_details`
--
ALTER TABLE `room_details`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_room_details_room` (`room_id`);

--
-- Indexes for table `room_maintenance_logs`
--
ALTER TABLE `room_maintenance_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_maint_room` (`room_id`,`scheduled_date`),
  ADD KEY `fk_maint_admin` (`created_by`);

--
-- Indexes for table `room_pricing`
--
ALTER TABLE `room_pricing`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_room` (`room_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `spa_configuration`
--
ALTER TABLE `spa_configuration`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_key` (`setting_key`);

--
-- Indexes for table `spa_operating_hours`
--
ALTER TABLE `spa_operating_hours`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_day` (`day_of_week`);

--
-- Indexes for table `therapists`
--
ALTER TABLE `therapists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_therapists_email` (`email`);

--
-- Indexes for table `therapist_assignment_logs`
--
ALTER TABLE `therapist_assignment_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_log_appointment` (`appointment_id`),
  ADD KEY `fk_log_admin` (`assigned_by`);

--
-- Indexes for table `therapist_capacity`
--
ALTER TABLE `therapist_capacity`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_capacity_therapist` (`therapist_id`);

--
-- Indexes for table `therapist_leaves`
--
ALTER TABLE `therapist_leaves`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leave_therapist` (`therapist_id`,`start_date`),
  ADD KEY `fk_leave_admin` (`created_by`);

--
-- Indexes for table `therapist_shifts`
--
ALTER TABLE `therapist_shifts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_shift_day` (`therapist_id`,`day_of_week`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `administrators`
--
ALTER TABLE `administrators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `appointment_checkins`
--
ALTER TABLE `appointment_checkins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `checkin_activity_logs`
--
ALTER TABLE `checkin_activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `configuration_logs`
--
ALTER TABLE `configuration_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `gift_certificates`
--
ALTER TABLE `gift_certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `loyalty_transactions`
--
ALTER TABLE `loyalty_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `memberships`
--
ALTER TABLE `memberships`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `membership_history`
--
ALTER TABLE `membership_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `membership_tiers`
--
ALTER TABLE `membership_tiers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `operational_announcements`
--
ALTER TABLE `operational_announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `rewards`
--
ALTER TABLE `rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `reward_redemptions`
--
ALTER TABLE `reward_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rooms`
--
ALTER TABLE `rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `room_climate_logs`
--
ALTER TABLE `room_climate_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `room_details`
--
ALTER TABLE `room_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `room_maintenance_logs`
--
ALTER TABLE `room_maintenance_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `room_pricing`
--
ALTER TABLE `room_pricing`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `spa_configuration`
--
ALTER TABLE `spa_configuration`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `spa_operating_hours`
--
ALTER TABLE `spa_operating_hours`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `therapists`
--
ALTER TABLE `therapists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `therapist_assignment_logs`
--
ALTER TABLE `therapist_assignment_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `therapist_capacity`
--
ALTER TABLE `therapist_capacity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `therapist_leaves`
--
ALTER TABLE `therapist_leaves`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `therapist_shifts`
--
ALTER TABLE `therapist_shifts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointment_checkins`
--
ALTER TABLE `appointment_checkins`
  ADD CONSTRAINT `fk_checkin_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_checkin_by` FOREIGN KEY (`checked_in_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_checkin_creator` FOREIGN KEY (`created_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `checkin_activity_logs`
--
ALTER TABLE `checkin_activity_logs`
  ADD CONSTRAINT `fk_checkin_log_admin` FOREIGN KEY (`processed_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_checkin_log_appt` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `checkin_settings`
--
ALTER TABLE `checkin_settings`
  ADD CONSTRAINT `fk_checkin_settings_admin` FOREIGN KEY (`updated_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_reset_admin` FOREIGN KEY (`admin_id`) REFERENCES `administrators` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `room_climate_logs`
--
ALTER TABLE `room_climate_logs`
  ADD CONSTRAINT `fk_climate_admin` FOREIGN KEY (`recorded_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_climate_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `room_details`
--
ALTER TABLE `room_details`
  ADD CONSTRAINT `fk_room_details_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `room_maintenance_logs`
--
ALTER TABLE `room_maintenance_logs`
  ADD CONSTRAINT `fk_maint_admin` FOREIGN KEY (`created_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_maint_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `room_pricing`
--
ALTER TABLE `room_pricing`
  ADD CONSTRAINT `fk_room_pricing_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `therapist_assignment_logs`
--
ALTER TABLE `therapist_assignment_logs`
  ADD CONSTRAINT `fk_log_admin` FOREIGN KEY (`assigned_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_log_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `therapist_capacity`
--
ALTER TABLE `therapist_capacity`
  ADD CONSTRAINT `fk_capacity_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `therapist_leaves`
--
ALTER TABLE `therapist_leaves`
  ADD CONSTRAINT `fk_leave_admin` FOREIGN KEY (`created_by`) REFERENCES `administrators` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_leave_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE;

--
-- Table structure for table `blocked_dates`
--

CREATE TABLE `blocked_dates` (
  `id` int(11) NOT NULL,
  `blocked_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `blocked_dates`
--

INSERT INTO `blocked_dates` (`id`, `blocked_date`, `reason`, `created_at`) VALUES
(1, '2026-11-15', 'Special private event', '2026-11-09 09:00:00'),
(2, '2026-11-22', 'Maintenance & deep clean', '2026-11-09 09:00:00');

--
-- Indexes for table `blocked_dates`
--
ALTER TABLE `blocked_dates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `blocked_date` (`blocked_date`);

--
-- AUTO_INCREMENT for table `blocked_dates`
--
ALTER TABLE `blocked_dates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for table `therapist_shifts`
--
ALTER TABLE `therapist_shifts`
  ADD CONSTRAINT `fk_shift_therapist` FOREIGN KEY (`therapist_id`) REFERENCES `therapists` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
