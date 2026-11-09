-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 09, 2026 at 03:31 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `waste_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `collection_routes`
--

CREATE TABLE `collection_routes` (
  `id` int(11) NOT NULL,
  `route_name` varchar(100) NOT NULL,
  `route_type` enum('regular','special') NOT NULL DEFAULT 'regular',
  `collector_id` int(11) DEFAULT NULL,
  `status` enum('pending','assigned','in_progress','completed') NOT NULL DEFAULT 'pending',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collection_routes`
--

INSERT INTO `collection_routes` (`id`, `route_name`, `route_type`, `collector_id`, `status`, `created_by`, `created_at`) VALUES
(8, 'Regular Collection Route - 2026-11-07 04:54', 'regular', 17, 'assigned', 12, '2026-11-07 03:54:22');

-- --------------------------------------------------------

--
-- Table structure for table `collection_schedules`
--

CREATE TABLE `collection_schedules` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `notes` text NOT NULL,
  `collection_date` date NOT NULL,
  `requested_time` time NOT NULL,
  `collection_address` varchar(255) NOT NULL,
  `status` enum('Rejected','Approved','Completed','Pending') NOT NULL DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collection_schedules`
--

INSERT INTO `collection_schedules` (`id`, `user_id`, `collector_id`, `notes`, `collection_date`, `requested_time`, `collection_address`, `status`, `created_at`) VALUES
(5, 4, NULL, 'basura', '2026-11-28', '19:24:00', 'muntinlupa', 'Completed', '2026-11-02 06:19:15'),
(6, 15, NULL, 'Andami na', '2026-11-18', '13:00:00', 'Kanto lang po', 'Approved', '2026-11-03 02:45:53'),
(7, 15, NULL, 'Andami na naman', '2026-11-30', '17:00:00', 'Kanto lang po', 'Rejected', '2026-11-03 02:46:20'),
(8, 15, NULL, 'andami amo', '2026-11-30', '20:00:00', 'kanto', 'Pending', '2026-11-03 02:59:05'),
(9, 15, NULL, 'hi', '2026-11-13', '07:00:00', 'j', 'Pending', '2026-11-03 03:19:28'),
(10, 15, 5, 'hello dami na nito lude', '2026-11-07', '18:06:00', 'sa bahay ko po', 'Approved', '2026-11-05 14:41:24');

-- --------------------------------------------------------

--
-- Table structure for table `collector_account`
--

CREATE TABLE `collector_account` (
  `ID` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` int(11) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collector_account`
--

INSERT INTO `collector_account` (`ID`, `name`, `username`, `password`) VALUES
(1, 'lanceallen', 1111, '123');

-- --------------------------------------------------------

--
-- Table structure for table `collector_locations`
--

CREATE TABLE `collector_locations` (
  `id` int(11) NOT NULL,
  `collector_id` int(11) NOT NULL,
  `latitude` decimal(10,8) NOT NULL,
  `longitude` decimal(11,8) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `collector_locations`
--

INSERT INTO `collector_locations` (`id`, `collector_id`, `latitude`, `longitude`, `updated_at`) VALUES
(1, 5, 15.21866674, 120.58426006, '2026-11-05 15:42:13'),
(4, 17, 15.21853700, 120.58435100, '2026-11-03 15:34:29');

-- --------------------------------------------------------

--
-- Table structure for table `eco_points`
--

CREATE TABLE `eco_points` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `collection_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `eco_points`
--

INSERT INTO `eco_points` (`id`, `user_id`, `collection_id`, `points`, `description`, `created_at`) VALUES
(1, 1, NULL, 50, 'Test of eco points', '2026-11-03 01:15:45'),
(2, 4, NULL, 50, 'Test Eco Points for Allen', '2026-11-03 01:20:35'),
(3, 19, 9, 5, 'Properly segregated household waste', '2026-11-08 12:25:29'),
(10, 22, NULL, 20, 'Manual Eco Points awarded by Administrator', '2026-11-08 23:48:03');

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `feedback_type` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`id`, `user_id`, `feedback_type`, `message`, `created_at`) VALUES
(1, 4, 'Suggestion', 'eerrrr', '2026-11-04 03:01:37'),
(2, 4, 'Suggestion', '2e2qe22', '2026-11-04 03:02:27'),
(3, 4, 'Suggestion', 'dsffsf', '2026-11-04 03:11:44'),
(4, 4, 'Complaint', 'ydyryry', '2026-11-04 03:13:13'),
(5, 4, 'Complaint', 'oiu9iuio', '2026-11-04 03:13:59'),
(6, 4, 'Satisfaction', 'okay', '2026-11-04 03:15:00');

-- --------------------------------------------------------

--
-- Table structure for table `households`
--

CREATE TABLE `households` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `house_address` varchar(255) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `households`
--

INSERT INTO `households` (`id`, `user_id`, `house_address`, `latitude`, `longitude`, `created_at`) VALUES
(1, 4, 'jalibi', 15.22124164, 120.57309493, '2026-11-01 08:04:49'),
(2, 14, 'house 2', 15.22087144, 120.57558983, '2026-11-01 08:40:16'),
(3, 15, 'dau', 15.17986722, 120.58676559, '2026-11-02 03:46:23'),
(4, 16, 'jalibi', 15.22162427, 120.57529449, '2026-11-03 00:40:53'),
(5, 13, 'tabi-tabi', 15.21879803, 120.57650685, '2026-11-08 05:18:30'),
(6, 19, 'malapit sa kapehan', 15.22564098, 120.57319164, '2026-11-08 12:23:59');

-- --------------------------------------------------------

--
-- Table structure for table `route_stops`
--

CREATE TABLE `route_stops` (
  `id` int(11) NOT NULL,
  `route_id` int(11) NOT NULL,
  `household_id` int(11) NOT NULL,
  `stop_order` int(11) NOT NULL,
  `status` enum('pending','collected') NOT NULL DEFAULT 'pending',
  `collected_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `route_stops`
--

INSERT INTO `route_stops` (`id`, `route_id`, `household_id`, `stop_order`, `status`, `collected_at`) VALUES
(20, 8, 1, 1, 'pending', NULL),
(21, 8, 2, 2, 'pending', NULL),
(22, 8, 3, 3, 'pending', NULL),
(23, 8, 4, 4, 'pending', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `service_fee` decimal(10,2) NOT NULL DEFAULT 150.00,
  `months` int(11) NOT NULL DEFAULT 1,
  `original_amount` decimal(10,2) NOT NULL DEFAULT 150.00,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 150.00,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_due` decimal(10,2) NOT NULL DEFAULT 150.00,
  `subscription_status` varchar(50) NOT NULL DEFAULT 'Pending Approval',
  `payment_status` varchar(50) NOT NULL DEFAULT 'Unpaid',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `user_id`, `service_fee`, `months`, `original_amount`, `points_redeemed`, `discount_amount`, `total_amount`, `amount_paid`, `balance_due`, `subscription_status`, `payment_status`, `created_at`) VALUES
(1, 4, 150.00, 1, 150.00, 0, 0.00, 150.00, 0.00, 150.00, 'Pending Approval', 'Unpaid', '2026-10-31 00:57:04'),
(2, 13, 150.00, 1, 150.00, 0, 0.00, 150.00, 150.00, 0.00, 'Active', 'Paid', '2026-11-08 05:49:31'),
(3, 19, 150.00, 1, 150.00, 0, 0.00, 150.00, 150.00, 0.00, 'Active', 'Paid', '2026-11-08 12:11:05'),
(4, 20, 150.00, 1, 150.00, 0, 0.00, 150.00, 150.00, 0.00, 'Active', 'Paid', '2026-11-08 12:36:34'),
(5, 21, 150.00, 1, 150.00, 0, 0.00, 150.00, 150.00, 0.00, 'Active', 'Paid', '2026-11-08 13:29:10'),
(6, 22, 150.00, 1, 150.00, 20, 20.00, 130.00, 0.00, 130.00, 'Pending Approval', 'Pending Confirmation', '2026-11-09 00:09:05');

-- --------------------------------------------------------

--
-- Table structure for table `subscription_point_redemptions`
--

CREATE TABLE `subscription_point_redemptions` (
  `id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `points_redeemed` int(11) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subscription_point_redemptions`
--

INSERT INTO `subscription_point_redemptions` (`id`, `subscription_id`, `user_id`, `points_redeemed`, `discount_amount`, `created_at`) VALUES
(1, 6, 22, 20, 20.00, '2026-11-08 23:49:15');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(255) NOT NULL,
  `users` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `users`, `password`, `role`) VALUES
(1, 'Micah galang', 'micahg', '$2y$10$/bzjTXhaS3gfBZqIfhZMyOgXhPq6v/s03Jc9VKuWAzztUVmEGm7Re', 'admin'),
(2, 'james', 'jamest', '$2y$10$0ScarMneqO1XlbiaUGglmunlkEZeJXLI7KuzYBO7l1f1x3Wi9Fhny', 'residents'),
(3, 'Eryza', 'ery', '$2y$10$X.fXmiVtW/S9sp1Pwgn8cOa6va7EnRCms59MdlVCdxyKvRBkRUxG6', 'residents'),
(4, 'lance', 'allen', '$2y$10$jiEGRpWsdtAwCV7bPBJ1G.ldlSqobIx64rmQBOT9h.EIc0ilwYoCu', 'residents'),
(5, 'lancee', 'allenz', '$2y$10$kdk2tdutAuiy5qQpkPZFZuwCetnBNgebSSmOBVn.eR02bNOHUvakG', 'collector'),
(6, 'Vincent Natividad', 'vince', '$2y$10$pUoJndwNX5n4FLjN1EOa0.uLFYJnCpTPS1QcH/yb9fT/Aq0Dy5Lg.', 'residents'),
(7, 'Vincent Natividad', 'vincent', '$2y$10$2GAmEsQJ2e/A6NjZmXX1G.LekFkvf7tZpXtsy.qQJECOzimYce9u2', 'collector'),
(12, 'Vincent Natividad', 'admin', '$2y$10$bUpkMh3ghhoYT1lxeBfwj.k4dXudnFo2euXvYHk7w5J7UCaf9q5z2', 'admin'),
(13, 'Vincent Natividad', 'resident123', '$2y$10$rm78gk81F9.x0ZuKIPatPev8mQltr/TwNxW7dN9jxesr2IHC1YgNS', 'residents'),
(14, 'res1', 'res1', '$2y$10$x5NkfogxHIoqrz7eKiIrxew1FJCRG6Cg7Gv86yY0T.VhnsXF/2fIu', 'residents'),
(15, 'lancee', 'res2', '$2y$10$EaqCE9qoiXScp/12Gn1iaOWNMKX4BSWukJbbhIHLnevBCaaWbIkfy', 'residents'),
(16, 'resident', 'resident', '$2y$10$Ri0Htxi2tV7PGcgHGtuZF.guHDDZ3w9YEahj9TziEFMFd9VmXDai6', 'residents'),
(17, 'collector', 'col', '$2y$10$Z6Bsw5I1do3oGf2ryTV8HeY8vu9DUPJeGOulxKZ0JmjqkmenHvEoi', 'collector'),
(18, 'tisting', 'res3', '$2y$10$Tlq8.MQXCqaFLvyYPyqyUuYU4aOnoC1DwsQiYfh2H6SlP7sbH.gEC', 'residents'),
(19, 'Vincent', 'resident1', '$2y$10$f537dzxWRa1C/asl3t.hpuW6blVii6phmB46rSiq57Ko.TVhl6Bqq', 'residents'),
(20, 'vince', 'resident2', '$2y$10$gsQiiEpeni51A3jVl4G6UeakWB.AYz4awUj92.NteYHgSh3BMlaQW', 'residents'),
(21, 'Natividad', 'resident3', '$2y$10$XJ22g9wpBvR8BKPluTEYLecrK5HC8XV7/dHhIBWbJTEFvSz5GwxCC', 'residents'),
(22, 'test', 'testing12', '$2y$10$RT/xwwrIt38YYHM1KeiaSue.MADBnh561FNLAZ9YJ50LVEXckrmFu', 'residents');

-- --------------------------------------------------------

--
-- Table structure for table `waste_collections`
--

CREATE TABLE `waste_collections` (
  `id` int(11) NOT NULL,
  `household_id` int(11) NOT NULL,
  `collector_id` int(11) DEFAULT NULL,
  `collection_date` date NOT NULL,
  `segregation_status` enum('Properly Segregated','Not Properly Segregated') DEFAULT NULL,
  `collection_status` enum('Pending','Collected') DEFAULT 'Pending',
  `eco_points_awarded` int(11) DEFAULT 0,
  `collected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `waste_collections`
--

INSERT INTO `waste_collections` (`id`, `household_id`, `collector_id`, `collection_date`, `segregation_status`, `collection_status`, `eco_points_awarded`, `collected_at`, `created_at`) VALUES
(1, 5, 17, '2026-11-08', 'Not Properly Segregated', 'Collected', 0, '2026-11-08 20:02:05', '2026-11-08 12:02:05'),
(9, 6, 17, '2026-11-08', 'Properly Segregated', 'Collected', 5, '2026-11-08 20:25:29', '2026-11-08 12:25:29');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `collection_routes`
--
ALTER TABLE `collection_routes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `collection_routes_ibfk_1` (`collector_id`);

--
-- Indexes for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_collection_schedules_collector_id` (`collector_id`);

--
-- Indexes for table `collector_account`
--
ALTER TABLE `collector_account`
  ADD PRIMARY KEY (`ID`);

--
-- Indexes for table `collector_locations`
--
ALTER TABLE `collector_locations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collector_id` (`collector_id`);

--
-- Indexes for table `eco_points`
--
ALTER TABLE `eco_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `collection_id` (`collection_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `households`
--
ALTER TABLE `households`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `route_stops`
--
ALTER TABLE `route_stops`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `route_id` (`route_id`,`stop_order`),
  ADD UNIQUE KEY `route_id_2` (`route_id`,`household_id`),
  ADD KEY `household_id` (`household_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_redemption_user` (`user_id`),
  ADD KEY `idx_redemption_subscription` (`subscription_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `waste_collections`
--
ALTER TABLE `waste_collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_household_collection` (`household_id`,`collection_date`),
  ADD KEY `collector_id` (`collector_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `collection_routes`
--
ALTER TABLE `collection_routes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `collector_account`
--
ALTER TABLE `collector_account`
  MODIFY `ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `collector_locations`
--
ALTER TABLE `collector_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `eco_points`
--
ALTER TABLE `eco_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `households`
--
ALTER TABLE `households`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `route_stops`
--
ALTER TABLE `route_stops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `waste_collections`
--
ALTER TABLE `waste_collections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `collection_routes`
--
ALTER TABLE `collection_routes`
  ADD CONSTRAINT `collection_routes_ibfk_1` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `collection_routes_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `collection_schedules`
--
ALTER TABLE `collection_schedules`
  ADD CONSTRAINT `fk_collection_schedules_collector` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `collector_locations`
--
ALTER TABLE `collector_locations`
  ADD CONSTRAINT `collector_locations_ibfk_1` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `eco_points`
--
ALTER TABLE `eco_points`
  ADD CONSTRAINT `eco_points_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `eco_points_ibfk_2` FOREIGN KEY (`collection_id`) REFERENCES `waste_collections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `households`
--
ALTER TABLE `households`
  ADD CONSTRAINT `households_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `route_stops`
--
ALTER TABLE `route_stops`
  ADD CONSTRAINT `route_stops_ibfk_1` FOREIGN KEY (`route_id`) REFERENCES `collection_routes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `route_stops_ibfk_2` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `subscription_point_redemptions`
--
ALTER TABLE `subscription_point_redemptions`
  ADD CONSTRAINT `fk_redemption_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_redemption_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `waste_collections`
--
ALTER TABLE `waste_collections`
  ADD CONSTRAINT `waste_collections_ibfk_1` FOREIGN KEY (`household_id`) REFERENCES `households` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `waste_collections_ibfk_2` FOREIGN KEY (`collector_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
