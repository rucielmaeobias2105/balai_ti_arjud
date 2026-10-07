-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: balai_ti_arjud
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `model_type` varchar(255) DEFAULT NULL,
  `model_id` varchar(255) DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_logs_user_id_action_index` (`user_id`,`action`),
  CONSTRAINT `activity_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `activity_logs`
--

LOCK TABLES `activity_logs` WRITE;
/*!40000 ALTER TABLE `activity_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `activity_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `admins`
--

DROP TABLE IF EXISTS `admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `username` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin') NOT NULL DEFAULT 'admin',
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `admins_username_unique` (`username`),
  UNIQUE KEY `admins_email_unique` (`email`),
  KEY `admins_role_index` (`role`),
  KEY `admins_is_active_index` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (1,'Balai ti','Arjud','admin','admin@balaitiarjud.test','$2y$12$KgCnGBdBXgyR6OCdMSYrXuGj9t6LtVRo7j5Smngy4birbrPUeqm22','admin',NULL,1,'2026-10-04 19:27:01','QUQdwH933o8ispE0SE1rHlI7SAmv3R6ZLd7ByDpjyEyvlkrQWoscAn5LKI8x','2026-09-27 03:10:39','2026-10-04 19:27:01',NULL);
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_items`
--

DROP TABLE IF EXISTS `appointment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointment_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint(20) unsigned DEFAULT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `package_id` bigint(20) unsigned DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `appointment_items_service_id_foreign` (`service_id`),
  KEY `appointment_items_package_id_foreign` (`package_id`),
  KEY `appointment_items_appointment_id_index` (`appointment_id`),
  CONSTRAINT `appointment_items_appointment_id_foreign` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_items_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointment_items_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_items`
--

LOCK TABLES `appointment_items` WRITE;
/*!40000 ALTER TABLE `appointment_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_service`
--

DROP TABLE IF EXISTS `appointment_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointment_service` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `service_variant_id` bigint(20) unsigned DEFAULT NULL,
  `service_name` varchar(255) NOT NULL,
  `variant_name` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `display_price` varchar(50) DEFAULT NULL,
  `duration_minutes` smallint(5) unsigned NOT NULL,
  `quantity` smallint(5) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `appointment_service_service_id_foreign` (`service_id`),
  KEY `appointment_service_service_variant_id_foreign` (`service_variant_id`),
  KEY `appointment_service_appointment_id_service_id_index` (`appointment_id`,`service_id`),
  CONSTRAINT `appointment_service_service_variant_id_foreign` FOREIGN KEY (`service_variant_id`) REFERENCES `service_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_service`
--

LOCK TABLES `appointment_service` WRITE;
/*!40000 ALTER TABLE `appointment_service` DISABLE KEYS */;
INSERT INTO `appointment_service` VALUES (3,3,82,NULL,'Glow Manicure',NULL,119.00,'119.00',0,1,'2026-09-28 19:30:54','2026-09-28 19:30:54'),(4,3,83,NULL,'Nail Extension',NULL,799.00,'799.00',0,1,'2026-09-28 19:30:54','2026-09-28 19:30:54'),(25,25,83,NULL,'Nail Extension',NULL,799.00,'799.00',0,1,'2026-09-30 06:39:15','2026-09-30 06:39:15'),(26,26,82,NULL,'Glow Manicure',NULL,119.00,'119.00',0,1,'2026-09-30 07:23:49','2026-09-30 07:23:49'),(27,27,102,NULL,'Temp Range Service',NULL,249.00,'249/499',0,2,'2026-10-01 05:42:23','2026-10-01 05:42:23'),(28,28,104,NULL,'Repair (per nail)',NULL,100.00,'100+',0,2,'2026-10-01 07:01:07','2026-10-01 07:01:07'),(29,31,93,NULL,'Gel Removal (Off- House)',NULL,100.00,'100.00',0,1,'2026-10-01 18:17:28','2026-10-01 18:17:28'),(30,33,97,NULL,'Foot Paraffin',NULL,299.00,'299.00',0,1,'2026-10-01 19:42:27','2026-10-01 19:42:27'),(31,33,98,NULL,'Leg Paraffin',NULL,399.00,'399.00',0,1,'2026-10-01 19:42:27','2026-10-01 19:42:27'),(32,34,93,NULL,'Gel Removal (Off- House)',NULL,100.00,'100.00',0,1,'2026-10-04 16:14:24','2026-10-04 16:14:24'),(33,35,96,NULL,'Arm Paraffin',NULL,299.00,'299.00',0,1,'2026-10-04 19:37:13','2026-10-04 19:37:13'),(34,35,149,NULL,'Inner Thighs',NULL,250.00,'250',0,1,'2026-10-04 19:37:13','2026-10-04 19:37:13'),(35,35,147,NULL,'Underarm',NULL,250.00,'250',0,1,'2026-10-04 19:37:13','2026-10-04 19:37:13');
/*!40000 ALTER TABLE `appointment_service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointment_status_history`
--

DROP TABLE IF EXISTS `appointment_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointment_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `appointment_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `from_status` enum('pending','confirmed','in_progress','completed','cancelled') DEFAULT NULL,
  `to_status` enum('pending','confirmed','in_progress','completed','cancelled') DEFAULT NULL,
  `changed_by` enum('admin','customer','system') NOT NULL DEFAULT 'system',
  `changed_by_id` bigint(20) unsigned DEFAULT NULL,
  `changed_by_name` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `appointment_status_history_appointment_id_foreign` (`appointment_id`),
  CONSTRAINT `appointment_status_history_appointment_id_foreign` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_status_history`
--

LOCK TABLES `appointment_status_history` WRITE;
/*!40000 ALTER TABLE `appointment_status_history` DISABLE KEYS */;
INSERT INTO `appointment_status_history` VALUES (3,3,NULL,NULL,'2026-09-28 19:30:54','2026-09-28 19:30:54',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(4,3,NULL,NULL,'2026-09-28 19:32:11','2026-09-28 19:32:11','pending','confirmed','admin',1,'Maia Arjud',NULL),(5,3,NULL,NULL,'2026-09-28 19:33:06','2026-09-28 19:33:06','confirmed','completed','admin',1,'Maia Arjud',NULL),(15,3,NULL,NULL,'2026-09-29 13:40:36','2026-09-29 13:40:36','completed','in_progress','admin',1,'Maia Arjud',NULL),(16,3,NULL,NULL,'2026-09-29 13:40:38','2026-09-29 13:40:38','in_progress','cancelled','admin',1,'Maia Arjud',NULL),(17,3,NULL,NULL,'2026-09-29 13:40:53','2026-09-29 13:40:53','cancelled','in_progress','admin',1,'Maia Arjud',NULL),(18,3,NULL,NULL,'2026-09-29 13:40:56','2026-09-29 13:40:56','in_progress','completed','admin',1,'Maia Arjud',NULL),(23,3,NULL,NULL,'2026-09-29 14:34:35','2026-09-29 14:34:35','completed','in_progress','admin',1,'Maia Arjud',NULL),(24,25,NULL,NULL,'2026-09-30 06:39:15','2026-09-30 06:39:15',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(25,3,NULL,NULL,'2026-09-30 07:15:36','2026-09-30 07:15:36','in_progress','completed','admin',1,'Maia Arjud',NULL),(26,25,NULL,NULL,'2026-09-30 07:18:28','2026-09-30 07:18:28','pending','confirmed','admin',1,'Maia Arjud',NULL),(27,25,NULL,NULL,'2026-09-30 07:19:02','2026-09-30 07:19:02','confirmed','in_progress','admin',1,'Maia Arjud',NULL),(28,26,NULL,NULL,'2026-09-30 07:23:49','2026-09-30 07:23:49',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(29,26,NULL,NULL,'2026-09-30 18:18:05','2026-09-30 18:18:05','pending','cancelled','customer',13,'Ruciel Mae Obias','Cancelled by customer. Reason: Schedule conflict'),(30,25,NULL,NULL,'2026-10-01 04:44:39','2026-10-01 04:44:39','in_progress','completed','admin',1,'Maia Arjud',NULL),(31,27,NULL,NULL,'2026-10-01 05:42:23','2026-10-01 05:42:23',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(32,28,NULL,NULL,'2026-10-01 07:01:07','2026-10-01 07:01:07',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(33,31,NULL,NULL,'2026-10-01 18:17:28','2026-10-01 18:17:28',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(34,33,NULL,NULL,'2026-10-01 19:42:27','2026-10-01 19:42:27',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(35,30,NULL,NULL,'2026-10-02 01:35:58','2026-10-02 01:35:58','pending','confirmed','admin',1,'Aivee Arjud',NULL),(36,31,NULL,NULL,'2026-10-02 01:36:08','2026-10-02 01:36:08','pending','confirmed','admin',1,'Aivee Arjud',NULL),(37,33,NULL,NULL,'2026-10-02 01:36:13','2026-10-02 01:36:13','pending','in_progress','admin',1,'Aivee Arjud',NULL),(38,31,NULL,NULL,'2026-10-02 01:36:25','2026-10-02 01:36:25','confirmed','in_progress','admin',1,'Aivee Arjud',NULL),(39,31,NULL,NULL,'2026-10-02 01:36:54','2026-10-02 01:36:54','in_progress','cancelled','admin',1,'Aivee Arjud',NULL),(40,30,NULL,NULL,'2026-10-02 04:56:14','2026-10-02 04:56:14','confirmed','cancelled','customer',13,'Ruciel Mae Obias','Cancelled by customer.'),(41,34,NULL,NULL,'2026-10-04 16:14:24','2026-10-04 16:14:24',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(42,34,NULL,NULL,'2026-10-04 16:15:24','2026-10-04 16:15:24','pending','confirmed','admin',1,'Balai ti Arjud',NULL),(43,33,NULL,NULL,'2026-10-04 19:27:09','2026-10-04 19:27:09','in_progress','completed','admin',1,'Balai ti Arjud',NULL),(44,35,NULL,NULL,'2026-10-04 19:37:13','2026-10-04 19:37:13',NULL,'pending','customer',13,'Ruciel Mae Obias','Appointment submitted by customer.'),(45,35,NULL,NULL,'2026-10-04 19:37:55','2026-10-04 19:37:55','pending','confirmed','admin',1,'Balai ti Arjud',NULL),(46,34,NULL,NULL,'2026-10-04 19:38:06','2026-10-04 19:38:06','confirmed','cancelled','admin',1,'Balai ti Arjud',NULL);
/*!40000 ALTER TABLE `appointment_status_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `staff_member_id` bigint(20) unsigned DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `last_service_availed` varchar(255) DEFAULT NULL,
  `preferred_stylist` varchar(255) DEFAULT NULL,
  `special_request` text DEFAULT NULL,
  `booking_reference` varchar(255) DEFAULT NULL,
  `down_payment_reference` varchar(255) DEFAULT NULL,
  `down_payment_status` varchar(255) NOT NULL DEFAULT 'not_required',
  `appointment_date` date DEFAULT NULL,
  `appointment_time` time DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `admin_seen_at` timestamp NULL DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `reference_number` varchar(32) NOT NULL DEFAULT '0',
  `idempotency_key` char(36) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL DEFAULT '0',
  `customer_phone` varchar(32) NOT NULL DEFAULT '0',
  `customer_email` varchar(255) DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `last_services_availed` text DEFAULT NULL,
  `preferred_stylist_id` bigint(20) unsigned DEFAULT NULL,
  `technician_id` bigint(20) unsigned DEFAULT NULL,
  `down_payment_amount` decimal(10,2) DEFAULT NULL,
  `source` varchar(24) NOT NULL DEFAULT 'web',
  `admin_notes` text DEFAULT NULL,
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `reschedule_reason` varchar(255) DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `archived_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `appointments_booking_reference_unique` (`booking_reference`),
  UNIQUE KEY `appointments_user_idempotency_unique` (`user_id`,`idempotency_key`),
  KEY `appointments_staff_member_id_foreign` (`staff_member_id`),
  KEY `appointments_user_id_appointment_date_index` (`user_id`,`appointment_date`),
  KEY `appointments_appointment_date_status_index` (`appointment_date`,`status`),
  KEY `appointments_technician_id_foreign` (`technician_id`),
  KEY `appointments_archived_by_foreign` (`archived_by`),
  KEY `appointments_admin_seen_at_index` (`admin_seen_at`),
  CONSTRAINT `appointments_archived_by_foreign` FOREIGN KEY (`archived_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_staff_member_id_foreign` FOREIGN KEY (`staff_member_id`) REFERENCES `staff_members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_technician_id_foreign` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (3,13,NULL,'Latex, MNJ',NULL,NULL,'GYTY',NULL,'2125412','unverified',NULL,NULL,'completed',NULL,918.00,NULL,'2026-09-28 19:30:54','2026-09-30 18:10:56','2026-09-30 18:10:56','BTA-20260929-E3A8',NULL,'Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-09-30','11:30:00','rEBONND',1,NULL,459.00,'web',NULL,NULL,NULL,NULL,'2026-09-28 19:32:11','2026-09-30 07:15:35','2026-09-29 13:40:36',NULL,NULL),(25,13,NULL,'Latex, Nickel',NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'completed','2026-10-01 19:33:55',799.00,NULL,'2026-09-30 06:39:15','2026-10-02 04:56:06','2026-10-02 04:56:06','BTA-20260930-3928',NULL,'Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-10-20','13:00:00',NULL,NULL,2,399.50,'web',NULL,NULL,NULL,NULL,'2026-09-30 07:18:28','2026-10-01 04:44:39','2026-09-30 07:19:02','2026-10-01 15:35:52',1),(26,13,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'cancelled','2026-10-01 19:33:55',119.00,NULL,'2026-09-30 07:23:49','2026-10-02 04:56:09','2026-10-02 04:56:09','BTA-20260930-7CFC',NULL,'Ruciel Mae Obias','+639318467600','rucielmaeobias277@gmail.com','2026-10-02','12:30:00','Glow Manicure',NULL,2,59.50,'web',NULL,'Schedule conflict','2026-09-30 18:18:05',NULL,NULL,NULL,NULL,'2026-10-01 15:35:52',1),(27,13,NULL,'N/A',NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'pending',NULL,498.00,NULL,'2026-10-01 05:42:23','2026-10-01 05:44:12','2026-10-01 05:44:12','BTA-20261001-4204',NULL,'Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-10-04','10:00:00','N/A',NULL,NULL,249.00,'web',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(28,13,NULL,'N/A',NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'pending',NULL,200.00,NULL,'2026-10-01 07:01:07','2026-10-01 07:01:36','2026-10-01 07:01:36','BTA-20261001-35BF',NULL,'Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-10-04','10:00:00','N/A',NULL,NULL,100.00,'web',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL),(29,13,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'completed','2026-10-01 19:33:55',500.00,NULL,'2026-10-01 15:36:12','2026-10-02 05:00:08','2026-10-02 05:00:08','BTA-20261001-0528',NULL,'Ruciel Mae Obias','09171234567','rucielmaeobias277@gmail.com','2026-10-03','10:00:00',NULL,NULL,NULL,NULL,'web',NULL,NULL,NULL,NULL,NULL,'2026-10-01 15:36:12',NULL,NULL,NULL),(30,13,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'cancelled','2026-10-01 19:33:55',500.00,NULL,'2026-10-01 16:06:20','2026-10-02 04:56:30','2026-10-02 04:56:30','BTA-20261002-6D99',NULL,'Ruciel Mae Obias','09171234567','rucielmaeobias277@gmail.com','2026-10-04','11:00:00',NULL,NULL,NULL,NULL,'web',NULL,NULL,'2026-10-02 04:56:14',NULL,'2026-10-02 01:35:58',NULL,NULL,NULL,NULL),(31,13,NULL,'Allergy to shellfish / marine products',NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'cancelled','2026-10-01 19:33:55',100.00,NULL,'2026-10-01 18:17:28','2026-10-02 05:13:18',NULL,'BTA-20261002-0B2C',NULL,'Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-10-03','11:00:00','Nail Extension',NULL,NULL,50.00,'web',NULL,NULL,'2026-10-02 01:36:54',NULL,'2026-10-02 01:36:08',NULL,'2026-10-02 01:36:25','2026-10-02 05:13:18',1),(33,13,NULL,'Allergy to shellfish / marine products',NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'completed','2026-10-01 19:42:46',698.00,NULL,'2026-10-01 19:42:27','2026-10-04 19:27:09',NULL,'BTA-20261002-3D21',NULL,'Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-10-03','10:30:00','Nail Extension',NULL,4,349.00,'web',NULL,NULL,NULL,NULL,NULL,'2026-10-04 19:27:09','2026-10-02 01:36:13',NULL,NULL),(34,13,NULL,'None',NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'cancelled','2026-10-04 16:15:14',100.00,NULL,'2026-10-04 16:14:24','2026-10-04 19:38:06',NULL,'BTA-20261005-859F','5941ea01-f22e-4b44-b2ec-648a71e02525','Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-10-06','13:00:00','none',NULL,3,NULL,'web',NULL,NULL,'2026-10-04 19:38:06',NULL,'2026-10-04 16:15:24',NULL,NULL,NULL,NULL),(35,13,NULL,'none',NULL,NULL,NULL,NULL,NULL,'not_required',NULL,NULL,'confirmed','2026-10-04 19:37:53',799.00,NULL,'2026-10-04 19:37:13','2026-10-04 19:37:55',NULL,'BTA-20261005-2AF4','4455b683-ba3b-4a2c-8bc5-28eea66a0561','Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-10-14','12:00:00','Foot Paraffin, Leg Paraffin',NULL,NULL,NULL,'web',NULL,NULL,NULL,NULL,'2026-10-04 19:37:55',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blocked_slots`
--

DROP TABLE IF EXISTS `blocked_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blocked_slots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `blocked_slots_created_by_foreign` (`created_by`),
  KEY `blocked_slots_date_index` (`date`),
  CONSTRAINT `blocked_slots_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blocked_slots`
--

LOCK TABLES `blocked_slots` WRITE;
/*!40000 ALTER TABLE `blocked_slots` DISABLE KEYS */;
INSERT INTO `blocked_slots` VALUES (8,'2026-10-09',NULL,NULL,NULL,NULL,'2026-10-03 19:33:02','2026-10-03 19:33:02'),(9,'2026-10-10',NULL,NULL,NULL,NULL,'2026-10-04 16:02:38','2026-10-04 16:02:38'),(10,'2026-10-15',NULL,NULL,NULL,NULL,'2026-10-04 19:36:37','2026-10-04 19:36:37');
/*!40000 ALTER TABLE `blocked_slots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cart_items`
--

DROP TABLE IF EXISTS `cart_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cart_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` bigint(20) unsigned DEFAULT NULL,
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `package_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(2,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cart_items_cart_id_foreign` (`cart_id`),
  KEY `cart_items_service_id_foreign` (`service_id`),
  KEY `cart_items_package_id_foreign` (`package_id`),
  CONSTRAINT `cart_items_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cart_items_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cart_items`
--

LOCK TABLES `cart_items` WRITE;
/*!40000 ALTER TABLE `cart_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `cart_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `carts`
--

DROP TABLE IF EXISTS `carts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `carts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `carts_user_id_unique` (`user_id`),
  KEY `carts_session_id_index` (`session_id`),
  CONSTRAINT `carts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `carts`
--

LOCK TABLES `carts` WRITE;
/*!40000 ALTER TABLE `carts` DISABLE KEYS */;
/*!40000 ALTER TABLE `carts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `categories_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Manicure & Pedicure','manicure-pedicure-xxyc','Complete nail grooming, gel polish, and paraffin treatments.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(2,'Nail Art & Extension','nail-art-extension-de5w','Extensions, repairs, and little added flair.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(3,'Spa Services','spa-services-5ong','Hand, foot, and hair pampering sessions.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(4,'Nail Care Packages','nail-care-packages-7eyt','Bundled nail care offers ? see the Packages section below.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(5,'Brow & Lash Extension','brow-lash-extension-khz7','Lash extensions, lifts, tints, and brow lamination.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(6,'Hair Waxing Removal','hair-waxing-removal-jey5','Hair removal treatments for face and body.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(7,'Bleaching','bleaching-tldk','Gentle skin brightening for smaller areas or the whole body.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(8,'Glutathione Push OR Drip','glutathione-push-or-drip-dclv','Skin brightening infusion services.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(9,'Massage','massage-hyvt','Relaxing Swedish, Shiatsu, ventosa, and hot stone therapy.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(10,'Co Spa Packages','co-spa-packages-cvg4','Bonding spa days for groups ? see the Packages section below.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(11,'Hair Care','hair-care-u7gl','Haircuts, color, keratin, and deep conditioning treatments.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(12,'Threading','threading-nuja','Precise eyebrow and facial hair shaping.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(13,'Kiddie Services','kiddie-services-zgo2','Gentle nail and spa services for little ones.','2026-09-25 15:43:47','2026-09-25 15:43:47');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `topic` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `admin_reply` text DEFAULT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `resolved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `replied_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_messages`
--

LOCK TABLES `contact_messages` WRITE;
/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
INSERT INTO `contact_messages` VALUES (2,'Liza Mercado','liza@example.test','pricing_question','Hi! Do you have a package for hair colour plus a blowout? Also, how long does rebonding usually take?',NULL,1,0,'2026-09-27 06:45:54','2026-10-01 15:36:54',1,NULL),(3,'Toni Alvarez','toni@example.test','booking_question','Can I reschedule my appointment to Saturday morning instead? Thank you!',NULL,1,0,'2026-09-27 06:45:54','2026-10-01 15:36:54',1,NULL),(4,'Grace Lim','grace@example.test','service_question','I have a gel allergy. Which of your nail services would you recommend instead?','Hi Grace! We suggest the Glow Manicure or Classic Pedicure with regular polish — both avoid gel products entirely. Reply here or call us to book.',1,0,'2026-09-27 06:45:54','2026-09-27 06:45:54',1,'2026-09-25 06:45:54'),(5,'Test One','t1@example.test','general_inquiry','Hello',NULL,1,0,'2026-10-01 16:06:10','2026-10-01 16:07:18',1,NULL),(6,'Test Two','t2@example.test','booking_question','Hi',NULL,1,0,'2026-10-01 16:06:10','2026-10-01 16:07:18',1,NULL);
/*!40000 ALTER TABLE `contact_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `homepage_settings`
--

DROP TABLE IF EXISTS `homepage_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `homepage_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) DEFAULT NULL,
  `value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value`)),
  `section` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `homepage_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `homepage_settings`
--

LOCK TABLES `homepage_settings` WRITE;
/*!40000 ALTER TABLE `homepage_settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `homepage_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_items`
--

DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_name` varchar(255) DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `quantity_in_stock` int(10) unsigned NOT NULL DEFAULT 0,
  `min_reorder_level` int(10) unsigned NOT NULL DEFAULT 0,
  `unit` varchar(255) NOT NULL DEFAULT 'pcs',
  `date_in` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `cost_per_unit` decimal(10,2) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `service_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reorder_threshold` decimal(12,2) DEFAULT NULL,
  `status_tag` enum('available','low_stock','best_seller','sold_out') NOT NULL DEFAULT 'available',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_items_sku_unique` (`sku`),
  KEY `inventory_items_service_id_foreign` (`service_id`),
  KEY `inventory_items_category_index` (`category`),
  KEY `inventory_items_quantity_in_stock_min_reorder_level_index` (`quantity_in_stock`,`min_reorder_level`),
  CONSTRAINT `inventory_items_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=78 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
INSERT INTO `inventory_items` VALUES (45,NULL,'GEL-TOP-COAT','Nail Art & Extension',0,0,'pcs','2026-09-01','2028-09-27',NULL,NULL,'active',NULL,'2026-09-30 05:41:39','2026-10-02 03:06:55',NULL,'Gel  Glossy Top Coat',2.00,NULL,'available',NULL,1),(46,NULL,'MATTE-TOP-COAT','Manicure & Pedicure',0,0,'pcs','2026-10-02','2028-01-13',NULL,NULL,'active',NULL,'2026-10-02 03:05:42','2026-10-02 03:05:42',NULL,'Matte Top Coat',2.00,NULL,'available',NULL,1),(47,NULL,'REGULAR-NAIL-POLISHES','General',0,0,'pcs','2026-10-02','2029-02-02',NULL,NULL,'active',NULL,'2026-10-02 04:04:33','2026-10-02 04:04:33',NULL,'Regular Nail Polishes',2.00,NULL,'available',NULL,1),(48,NULL,'GEL-NAIL-POLISHES','General',0,0,'pcs','2026-10-02','2029-10-28',NULL,NULL,'active',NULL,'2026-10-02 04:04:56','2026-10-02 04:04:56',NULL,'Gel Nail Polishes',2.00,NULL,'available',NULL,1),(49,NULL,'DEHYDRATORS-ACID-FREE-NAIL-PRIMERS','General',0,0,'pcs','2026-10-02','2029-05-30',NULL,NULL,'active',NULL,'2026-10-02 04:19:57','2026-10-04 19:36:14','2026-10-04 19:36:14','Dehydrators & Acid-Free Nail Primers',3.00,NULL,'available',NULL,1),(50,NULL,'POLYGEL','General',0,0,'pcs','2026-10-20','2026-10-20',NULL,NULL,'active',NULL,'2026-10-02 04:20:20','2026-10-02 04:20:20',NULL,'Polygel',5.00,NULL,'available',NULL,1),(51,NULL,'HARD-GEL','General',0,0,'pcs','2026-09-30','2030-03-20',NULL,NULL,'active',NULL,'2026-10-02 04:20:48','2026-10-02 04:20:48',NULL,'Hard Gel',5.00,NULL,'available',NULL,1),(52,NULL,'BUILDER-GEL','General',0,0,'pcs','2026-09-23','2028-07-03',NULL,NULL,'active',NULL,'2026-10-02 04:21:08','2026-10-02 04:21:08',NULL,'Builder Gel',6.00,NULL,'available',NULL,1),(53,NULL,'ACRYLIC-POWDERS-LIQUID-MONOMER','General',0,0,'pcs','2026-09-16','2028-07-06',NULL,NULL,'active',NULL,'2026-10-02 04:21:31','2026-10-04 19:36:08',NULL,'Acrylic Powders & Liquid Monomer',9.00,NULL,'available',NULL,1),(54,NULL,'FULL-COVER-GEL-TIPS','General',0,0,'pcs','2026-10-02','2028-07-18',NULL,NULL,'active',NULL,'2026-10-02 04:22:05','2026-10-02 04:22:05',NULL,'Full-cover Gel Tips',6.00,NULL,'available',NULL,1),(55,NULL,'ACRYLIC-TIPS','General',0,0,'pcs','2026-09-08','2029-09-12',NULL,NULL,'active',NULL,'2026-10-02 04:22:30','2026-10-02 04:22:30',NULL,'Acrylic Tips',5.00,NULL,'available',NULL,1),(56,NULL,'NAIL-FORM-STICKERS','General',0,0,'pcs','2026-09-02','2028-10-17',NULL,NULL,'active',NULL,'2026-10-02 04:22:53','2026-10-02 04:22:53',NULL,'Nail Form Stickers',4.00,NULL,'available',NULL,1),(57,NULL,'EXTENSION-MOLDS','General',0,0,'pcs','2026-09-16','2029-06-19',NULL,NULL,'active',NULL,'2026-10-02 04:23:33','2026-10-02 04:23:33',NULL,'Extension Molds',5.00,NULL,'available',NULL,1),(58,NULL,'RHINESTONES','General',0,0,'pcs','2026-08-25','2029-10-02',NULL,NULL,'active',NULL,'2026-10-02 04:34:14','2026-10-02 04:47:14','2026-10-02 04:47:14','Rhinestones,',10.00,NULL,'available',NULL,1),(59,NULL,'RHINESTONES-2','General',0,0,'pcs','2026-08-12','2029-06-13',NULL,NULL,'active',NULL,'2026-10-02 04:41:51','2026-10-02 04:41:51',NULL,'Rhinestones',15.00,NULL,'available',NULL,1),(60,NULL,'NAIL-STONES-STUDS','General',0,0,'pcs','2026-07-07','2029-10-15',NULL,NULL,'active',NULL,'2026-10-02 04:42:26','2026-10-02 04:42:26',NULL,'Nail Stones & Studs',20.00,NULL,'available',NULL,1),(61,NULL,'CUTICLE-SOFTENER-CUTICLE-OIL','General',0,0,'pcs','2026-06-29','2030-10-09',NULL,NULL,'active',NULL,'2026-10-02 04:42:59','2026-10-02 04:42:59',NULL,'Cuticle Softener & Cuticle Oil',5.00,NULL,'available',NULL,1),(62,NULL,'ISOPROPYL-ALCOHOL','General',0,0,'pcs','2026-04-01','2030-07-09',NULL,NULL,'active',NULL,'2026-10-02 04:43:29','2026-10-02 04:43:29',NULL,'Isopropyl Alcohol',5.00,NULL,'available',NULL,1),(63,NULL,'CLEANSER-WIPES','General',0,0,'pcs','2026-04-22','2032-10-07',NULL,NULL,'active',NULL,'2026-10-02 04:43:52','2026-10-02 04:43:52',NULL,'Cleanser Wipes',10.00,NULL,'available',NULL,1),(64,NULL,'PARAFFIN-WAX-BEADS','General',0,0,'pcs','2026-06-17','2030-07-09',NULL,NULL,'active',NULL,'2026-10-02 04:44:16','2026-10-02 04:44:16',NULL,'Paraffin Wax Beads',5.00,NULL,'available',NULL,1),(65,NULL,'PARAFFIN-WAX-BEADS-2','General',0,0,'pcs','2026-06-17','2030-07-09',NULL,NULL,'active',NULL,'2026-10-02 04:44:46','2026-10-02 04:44:46',NULL,'Paraffin Wax Beads',5.00,NULL,'available',NULL,1),(66,NULL,'EXFOLIATING-FOOT-HAND-SCRUBS','General',0,0,'pcs','2026-05-12','2027-04-13',NULL,NULL,'active',NULL,'2026-10-02 04:45:06','2026-10-02 04:45:06',NULL,'Exfoliating Foot & Hand Scrubs',5.00,NULL,'available',NULL,1),(67,NULL,'DEEP-MOISTURIZING-CREAMS-FOOT-MASKS','General',0,0,'pcs','2026-04-15','2026-12-30',NULL,NULL,'active',NULL,'2026-10-02 04:45:28','2026-10-02 04:45:28',NULL,'Deep Moisturizing Creams & Foot Masks',5.00,NULL,'available',NULL,1),(68,NULL,'CALLUS-SOFTENER','General',0,0,'pcs','2026-01-13','2027-03-18',NULL,NULL,'active',NULL,'2026-10-02 04:45:56','2026-10-02 04:45:56',NULL,'Callus Softener',5.00,NULL,'available',NULL,1),(69,NULL,'CALLUS-SOFTENER-2','General',0,0,'pcs','2026-08-06','2030-06-03',NULL,NULL,'active',NULL,'2026-10-02 04:47:00','2026-10-02 04:47:00',NULL,'Callus Softener',4.00,NULL,'available',NULL,1),(70,NULL,'FIRE-CUPPING-GLASS','General',0,0,'pcs','2026-10-06','2026-10-09',NULL,NULL,'active',NULL,'2026-10-02 04:49:17','2026-10-02 04:49:17',NULL,'Fire Cupping Glass',50.00,NULL,'available',NULL,1),(71,NULL,'SILICONE-VENTOSA-CUPS','General',0,0,'pcs','2026-10-01','2026-11-03',NULL,NULL,'active',NULL,'2026-10-02 04:49:46','2026-10-02 04:49:46',NULL,'Silicone Ventosa Cups',25.00,NULL,'available',NULL,1),(72,NULL,'BASALT-HOT-STONES','General',0,0,'pcs','2026-10-22','2027-04-13',NULL,NULL,'active',NULL,'2026-10-02 04:50:09','2026-10-02 04:50:09',NULL,'Basalt Hot Stones',25.00,NULL,'available',NULL,1),(73,NULL,'TORCH-COTTON-BALLS-FOR-VENTOSA','General',0,0,'pcs','2026-07-08','2029-03-07',NULL,NULL,'active',NULL,'2026-10-02 04:50:38','2026-10-02 04:50:38',NULL,'Torch Cotton Balls for Ventosa',25.00,NULL,'available',NULL,1),(74,NULL,'INDIVIDUAL-LASH-TRAY-EXTENSIONS','General',0,0,'pcs','2026-01-01','2030-10-21',NULL,NULL,'active',NULL,'2026-10-02 04:51:36','2026-10-02 04:51:36',NULL,'Individual Lash Tray Extensions',15.00,NULL,'available',NULL,1),(75,NULL,'PROFESSIONAL-LASH-ADHESIVE','General',0,0,'pcs','2026-04-08','2030-06-02',NULL,NULL,'active',NULL,'2026-10-02 04:51:57','2026-10-02 04:51:57',NULL,'Professional Lash Adhesive /',15.00,NULL,'available',NULL,1),(76,NULL,'GLUE-LASH-REMOVER-GEL','General',0,0,'pcs','2026-04-15','2027-04-15',NULL,NULL,'active',NULL,'2026-10-02 04:52:22','2026-10-02 04:52:22',NULL,'Glue & Lash Remover Gel',18.00,NULL,'available',NULL,1),(77,NULL,'LASH-PERMING','General',0,0,'pcs','2026-05-14','2029-07-11',NULL,NULL,'active',NULL,'2026-10-02 04:52:49','2026-10-02 04:52:49',NULL,'Lash Perming',12.00,NULL,'available',NULL,1);
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_logs`
--

DROP TABLE IF EXISTS `inventory_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inventory_item_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `change_type` varchar(255) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_logs_user_id_foreign` (`user_id`),
  KEY `inventory_logs_inventory_item_id_created_at_index` (`inventory_item_id`,`created_at`),
  CONSTRAINT `inventory_logs_inventory_item_id_foreign` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_logs`
--

LOCK TABLES `inventory_logs` WRITE;
/*!40000 ALTER TABLE `inventory_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_09_22_140656_create_categories_table',1),(5,'2026_09_22_140657_create_services_table',1),(6,'2026_09_22_140658_create_packages_table',1),(7,'2026_09_22_140659_create_staff_members_table',1),(8,'2026_09_22_140700_create_appointments_table',1),(9,'2026_09_22_140701_create_appointment_items_table',1),(10,'2026_09_22_140702_create_inventory_items_table',1),(11,'2026_09_22_140703_create_inventory_logs_table',1),(12,'2026_09_26_000100_create_service_variants_table',1),(13,'2026_09_26_000101_create_service_inventory_table',1),(14,'2026_09_26_000102_create_appointment_status_history_table',1),(15,'2026_09_26_000103_create_password_reset_codes_table',1),(16,'2026_09_26_000104_create_blocked_dates_table',1),(17,'2026_09_26_000105_create_terms_and_conditions_table',1),(18,'2026_09_26_000106_create_promos_table',1),(19,'2026_09_26_000107_create_reviews_table',1),(20,'2026_09_26_000108_add_extra_fields_to_tables',1),(21,'2026_09_26_000109_create_contact_messages_table',1),(22,'2026_09_27_000001_create_homepage_settings_table',2),(23,'2026_09_27_000002_create_activity_logs_table',2),(24,'2026_09_27_000003_create_user_notifications_table',2),(25,'2026_09_27_000004_create_carts_table',2),(26,'2026_09_27_000005_create_cart_items_table',2),(27,'2026_09_27_000006_add_fields_to_reviews_table',3),(28,'2026_09_27_000007_add_fields_to_contact_messages_table',3),(29,'2026_09_27_000008_create_otp_codes_table',3),(30,'2024_01_01_000100_create_bta_tables',4),(31,'2024_01_01_000200_create_notifications_table',4),(32,'2026_09_27_000000_collapse_admin_roles_to_admin',5),(33,'2026_09_28_000000_add_time_to_blocked_dates',6),(36,'2026_09_29_000100_create_service_categories_table',7),(37,'2026_09_29_000200_add_service_category_to_blocked_dates',8),(38,'2026_09_29_000300_add_is_active_to_service_categories',9),(39,'2026_09_29_000400_add_dates_to_inventory_items',10),(40,'2026_09_29_000500_create_technicians_table',11),(41,'2026_09_29_000600_add_technician_to_appointments',11),(42,'2026_09_29_000700_make_inventory_stock_columns_nullable',11),(43,'2026_09_30_000000_drop_reviews_table',12),(44,'2026_10_01_000100_add_archived_to_appointments',13),(45,'2026_10_01_000200_drop_blocked_dates_table',14),(46,'2026_10_01_000100_split_service_price_into_display_and_base',15),(47,'2026_10_02_000100_add_photo_to_service_categories',16),(48,'2026_10_02_000200_repair_legacy_down_payment_status',17),(49,'2026_10_02_000300_add_admin_seen_at_to_appointments_table',18),(50,'2026_10_02_000400_drop_terms_version_column',19),(51,'2026_10_04_000100_add_minimum_notice_hours_to_salon_settings_table',20),(52,'2026_10_04_000200_create_blocked_slots_table',20),(53,'2026_10_04_000300_add_idempotency_key_to_appointments_table',20),(54,'2026_10_04_000400_close_mondays_in_operating_hours',21);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) unsigned NOT NULL,
  `data` text NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES ('0ea9e20f-0f09-486e-86d2-0d6ce428b2c4','App\\Notifications\\AppointmentConfirmedNotification','App\\Models\\User',13,'{\"title\":\"Your appointment is confirmed\",\"message\":\"We look forward to welcoming you for Nail Extension.\",\"icon\":\"check\",\"tone\":\"confirmed\",\"url\":\"http:\\/\\/localhost:8000\\/appointments?view=25\",\"appointment_id\":25,\"reference_number\":\"BTA-20260930-3928\"}','2026-10-01 16:00:12','2026-10-01 04:54:59','2026-10-01 16:00:12'),('47522125-4608-4ab9-ad98-a85ad18d8a27','App\\Notifications\\AppointmentConfirmedNotification','App\\Models\\User',13,'{\"title\":\"Your appointment is confirmed\",\"message\":\"We look forward to welcoming you for Arm Paraffin, Inner Thighs, Underarm.\",\"icon\":\"check\",\"tone\":\"confirmed\",\"url\":\"http:\\/\\/localhost\\/BTA\\/public\\/appointments?view=35\",\"appointment_id\":35,\"reference_number\":\"BTA-20261005-2AF4\"}','2026-10-04 19:38:25','2026-10-04 19:37:55','2026-10-04 19:38:25'),('caf85421-189f-4c8a-8f35-f464df8518d0','App\\Notifications\\AppointmentCancelledByAdminNotification','App\\Models\\User',13,'{\"title\":\"Appointment cancelled by the salon\",\"message\":\"Unfortunately we had to cancel your appointment on Oct 6, 2026. Please rebook at your convenience.\",\"icon\":\"x\",\"tone\":\"cancelled\",\"url\":\"http:\\/\\/localhost\\/BTA\\/public\\/book\",\"appointment_id\":34,\"reference_number\":\"BTA-20261005-859F\"}','2026-10-04 19:38:25','2026-10-04 19:38:06','2026-10-04 19:38:25'),('d93df2a7-51fb-492a-8b71-004a03c87b6a','App\\Notifications\\AppointmentBookedNotification','App\\Models\\User',13,'{\"title\":\"Appointment request received\",\"message\":\"We have received your booking request and will confirm it shortly. Total: \\u20b1799.00.\",\"icon\":\"calendar\",\"tone\":\"pending\",\"url\":\"https:\\/\\/localhost\\/BTA\\/public\\/appointments?view=35\",\"appointment_id\":35,\"reference_number\":\"BTA-20261005-2AF4\"}','2026-10-04 19:37:41','2026-10-04 19:37:13','2026-10-04 19:37:41'),('f044eace-aa24-4710-b446-a57540b8a43b','App\\Notifications\\WelcomeNotification','App\\Models\\User',11,'{\"title\":\"Welcome to Balai ti Arjud\",\"message\":\"Your account is ready. You can now book appointments and track your treatments.\",\"icon\":\"sparkles\",\"tone\":\"gold\",\"url\":\"http:\\/\\/localhost\\/BTA\\/public\\/book\"}',NULL,'2026-09-27 03:45:44','2026-09-27 03:45:44'),('f12ce568-bb72-4ab8-b7b4-f1ba8b3dafd7','App\\Notifications\\AppointmentBookedNotification','App\\Models\\User',13,'{\"title\":\"Appointment request received\",\"message\":\"We have received your booking request and will confirm it shortly. Total: \\u20b1498.00.\",\"icon\":\"calendar\",\"tone\":\"pending\",\"url\":\"http:\\/\\/127.0.0.1:8399\\/appointments?view=27\",\"appointment_id\":27,\"reference_number\":\"BTA-20261001-4204\"}','2026-10-01 16:00:12','2026-10-01 05:42:23','2026-10-01 16:00:12');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `otp_codes`
--

DROP TABLE IF EXISTS `otp_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `otp_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `otp_codes_user_id_index` (`user_id`),
  CONSTRAINT `otp_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `otp_codes`
--

LOCK TABLES `otp_codes` WRITE;
/*!40000 ALTER TABLE `otp_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `otp_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `packages`
--

DROP TABLE IF EXISTS `packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `packages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `max_pax` int(10) unsigned DEFAULT NULL,
  `duration_hours` decimal(5,2) DEFAULT NULL,
  `complimentary_items` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `packages_name_index` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `packages`
--

LOCK TABLES `packages` WRITE;
/*!40000 ALTER TABLE `packages` DISABLE KEYS */;
INSERT INTO `packages` VALUES (1,'Glow Solo Package 1','The best-selling glow combination for a single guest.',239.00,1,2.00,NULL,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(2,'Glow Solo Package 2','Complete hand and foot glow-up for one.',949.00,1,3.00,NULL,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(3,'Glow Solo Package 3','Our premium solo pampering bundle.',1199.00,1,4.00,NULL,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(4,'Glow Solo Package 4','A quick weekday glow refresh for one.',669.00,1,2.00,NULL,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(5,'Arjud Glow Package','The signature Balai ti Arjud experience. Enjoy 15% off this bundle.',1800.00,2,4.00,'15% off · Free express massage and refreshments.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(6,'Co Spa Package 1','A relaxing spa day for small groups of up to three.',5000.00,3,3.00,'Includes light snacks and refreshments for the group.','2026-09-25 15:43:47','2026-09-25 15:43:47'),(7,'Co Spa Package 2','The ultimate group bonding spa day for up to five guests.',10000.00,5,3.00,'Includes a dedicated private room and refreshments for the group.','2026-09-25 15:43:47','2026-09-25 15:43:47');
/*!40000 ALTER TABLE `packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promos`
--

DROP TABLE IF EXISTS `promos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `image_path` varchar(255) DEFAULT 'NULL',
  `notified` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promos`
--

LOCK TABLES `promos` WRITE;
/*!40000 ALTER TABLE `promos` DISABLE KEYS */;
INSERT INTO `promos` VALUES (1,'Glow Package Discount','Book any two signature services this month and enjoy 15% off your total. Perfect for pre-wedding prep or a mid-year reset.',NULL,'2026-09-22 00:00:00','2026-10-22 00:00:00',1,'2026-09-27 06:45:54','2026-09-28 13:35:44','2026-09-28 13:35:44','NULL',0),(2,'Loyalty Week: Free Manicure Upgrade','Book a Gelish Manicure during Loyalty Week and upgrade to Spa Pedicure pricing at no extra cost.',NULL,'2026-10-07 00:00:00','2026-10-14 00:00:00',1,'2026-09-27 06:45:54','2026-09-28 13:35:44','2026-09-28 13:35:44','NULL',1),(3,'Mother’s Day Special','A relaxing 60-minute massage plus a hydrating facial at a special Mother’s Day rate.',NULL,'2026-07-29 00:00:00','2026-08-05 00:00:00',0,'2026-09-27 06:45:54','2026-09-28 13:35:44','2026-09-28 13:35:44','NULL',0),(4,'3RD ANNIV','✨ SATURDAY MASSAGE DAY ✨\r\nUnwind. Relax. Recharge. 💆‍♀️🌿\r\nGive yourself the rest you deserve!\r\n🗓️ Every Saturday\r\n⏰ 9:00 AM – 5:00 PM\r\n📩 Massage appointments are now open!\r\nBook your preferred time slot and enjoy a soothing, relaxing experience at Balai Ti Arjud Salon. 🤍\r\n✨ Your weekend relaxation starts here. ✨ See less',NULL,'2026-10-01 00:00:00','2026-10-30 00:00:00',1,'2026-09-28 19:49:33','2026-10-02 01:22:10',NULL,'promos/Vhc4NTeVixs5aquocSRHPQu9c0ilA0r9YO9OXcXu.jpg',0),(5,'Glow Weekend Package','Any two nail services and a facial for one price.\n\nThis weekend only, while slots last.',NULL,'2026-10-01 00:00:00','2026-10-08 00:00:00',1,'2026-10-01 21:42:10','2026-10-01 21:44:51','2026-10-01 21:44:51','promos/verify-offer.jpg',0);
/*!40000 ALTER TABLE `promos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salon_settings`
--

DROP TABLE IF EXISTS `salon_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `salon_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT 'Balai ti Arjud',
  `address` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `operating_hours` longtext NOT NULL,
  `slot_interval_minutes` smallint(5) unsigned NOT NULL DEFAULT 30,
  `booking_lead_days` smallint(5) unsigned NOT NULL DEFAULT 60,
  `minimum_notice_hours` smallint(5) unsigned NOT NULL DEFAULT 24,
  `down_payment_required` tinyint(1) NOT NULL DEFAULT 1,
  `down_payment_percentage` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salon_settings`
--

LOCK TABLES `salon_settings` WRITE;
/*!40000 ALTER TABLE `salon_settings` DISABLE KEYS */;
INSERT INTO `salon_settings` VALUES (1,'Balai ti Arjud — Glow & Co. Beauty Lounge','Purok 5, Abra, Philippines','+63 900 000 0000','hello@balaitiarjud.test','{\"tuesday\":[\"09:00\",\"17:00\"],\"wednesday\":[\"09:00\",\"17:00\"],\"thursday\":[\"09:00\",\"17:00\"],\"friday\":[\"09:00\",\"17:00\"],\"saturday\":[\"09:00\",\"17:00\"],\"sunday\":[\"09:00\",\"17:00\"]}',30,60,24,0,50,'2026-09-27 02:38:55','2026-10-03 18:14:56');
/*!40000 ALTER TABLE `salon_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_categories`
--

DROP TABLE IF EXISTS `service_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `photo` varchar(255) DEFAULT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#7A241B',
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_categories_name_unique` (`name`),
  KEY `service_categories_sort_order_index` (`sort_order`),
  KEY `service_categories_is_active_index` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_categories`
--

LOCK TABLES `service_categories` WRITE;
/*!40000 ALTER TABLE `service_categories` DISABLE KEYS */;
INSERT INTO `service_categories` VALUES (1,'Manicure & Pedicure',1,'categories/I303BvK4qRGzpHRAX5zslg25fmGWdnQols6a0tdO.jpg','#E11D48',0,'2026-09-28 15:53:09','2026-10-06 20:40:44'),(2,'Nail Art & Extension',1,'categories/YPeRcsZ7u1rEPbNbG9m7Pvd5aHrh32OoApePhLjr.jpg','#EC4899',1,'2026-09-28 15:53:09','2026-10-04 19:43:07'),(3,'Spa Services',1,'categories/nTXgcB2j4CNkH9P7g845laizPw3zG44X5Msm7Zd0.jpg','#8B5CF6',2,'2026-09-28 15:53:09','2026-10-04 19:43:14'),(4,'Brow & Lash Extension',1,'categories/FH6G81VdrSltSDVJj6FzMJ7DBZlsRfmn6it6WKo6.jpg','#4F46E5',3,'2026-09-28 15:53:09','2026-10-04 19:43:23'),(5,'Hair Waxing Removal',1,'categories/QiIZchylolOW2UJXPfHoK4IYIBZZ2ud11H6OYovV.jpg','#F59E0B',4,'2026-09-28 15:53:09','2026-10-04 19:43:31'),(6,'Bleaching',1,'categories/56mAa1fM9nDdWEGTFULoLD5bAIb0lQoiqIeaHYQD.jpg','#64748B',5,'2026-09-28 15:53:09','2026-10-04 19:43:38'),(8,'Relaxing Massage',1,'categories/5fIU79QLiy0zMDXPOsWGeOFWpnpMG9BaaqIOPY4g.png','#16A34A',7,'2026-09-28 15:53:09','2026-10-04 19:44:17'),(9,'Hair Care',1,'categories/PBreMiWygsyR2KTHohaVqEAVI2vk46wxrKGmg5IT.jpg','#EA580C',8,'2026-09-28 15:53:09','2026-10-04 19:44:24'),(10,'Threading',1,'categories/KgdL3fWe7JcM1TMTEBmtnpVLjF2WLkI600Bgq1oz.jpg','#65A30D',9,'2026-09-28 15:53:09','2026-10-04 19:44:37'),(11,'Kiddie Services',1,'categories/jRGcTQB328dFhgtEdk6Q4FZzsWmB4vMktHBsFPgp.jpg','#D946EF',10,'2026-09-28 15:53:09','2026-10-04 19:44:45'),(13,'Nail Care Pacakges',1,'categories/gC2ipoFaQQImJ0DIFSiapjxLEqvFamwbkyLaaR1m.jpg','#0284C7',12,'2026-10-02 00:07:22','2026-10-02 00:07:22'),(14,'Therapeutic Massage',1,'categories/hnJrzcrTCUn6Ry1wq8i9y0tY3wuGaK52zpfhVQhR.png','#B45309',13,'2026-10-02 00:57:16','2026-10-02 00:57:16'),(15,'Spot Massage',1,'categories/hySB4tuXoZmJRvKIJmDjIGcFpUd4DrZ86AUl5r4H.png','#E11D48',14,'2026-10-02 00:57:28','2026-10-02 01:19:06'),(16,'Hair Glowout',1,'categories/kPvTpUZlRDllcuk1JtixGCTVtrYGmFmbLN87w49q.jpg','#E11D48',15,'2026-10-02 01:20:51','2026-10-04 19:44:56'),(17,'Glutathione Push OR Drip',1,'categories/vQAAN5RJJ9lasGzo60DMqRrk2akjy53AdUBS2WSx.jpg','#0D9488',16,'2026-10-04 19:34:33','2026-10-04 19:45:09');
/*!40000 ALTER TABLE `service_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_inventory`
--

DROP TABLE IF EXISTS `service_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_inventory` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `inventory_item_id` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `quantity_per_service` decimal(12,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_inventory_service_id_inventory_item_id_unique` (`service_id`,`inventory_item_id`),
  KEY `service_inventory_inventory_item_id_foreign` (`inventory_item_id`),
  CONSTRAINT `service_inventory_inventory_item_id_foreign` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `service_inventory_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_inventory`
--

LOCK TABLES `service_inventory` WRITE;
/*!40000 ALTER TABLE `service_inventory` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `service_variants`
--

DROP TABLE IF EXISTS `service_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `service_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(255) DEFAULT NULL,
  `price` varchar(50) DEFAULT NULL,
  `duration_minutes` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `base_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_variants_service_id_foreign` (`service_id`),
  CONSTRAINT `service_variants_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_variants`
--

LOCK TABLES `service_variants` WRITE;
/*!40000 ALTER TABLE `service_variants` DISABLE KEYS */;
/*!40000 ALTER TABLE `service_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price_type` varchar(255) NOT NULL DEFAULT 'fixed',
  `price` varchar(50) DEFAULT NULL,
  `max_price` decimal(10,2) DEFAULT NULL,
  `duration_minutes` int(10) unsigned DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `service_category_id` bigint(20) unsigned DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT 'NULL',
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `base_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `services_slug_unique` (`slug`),
  KEY `services_category_id_name_index` (`category_id`,`name`),
  KEY `services_service_category_id_foreign` (`service_category_id`),
  CONSTRAINT `services_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `services_service_category_id_foreign` FOREIGN KEY (`service_category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=173 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
INSERT INTO `services` VALUES (1,1,'Glow Manicure',NULL,'fixed','119.00',NULL,45,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:43','2026-09-28 13:35:43','glow-manicure','Manicure & Pedicure',1,NULL,0,119.00),(2,1,'Glow Pedicure',NULL,'fixed','159.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:43','2026-09-28 13:35:43','glow-pedicure','Manicure & Pedicure',1,NULL,0,159.00),(3,1,'Glow Gel Manicure','With gel polish application.','fixed','349.00',NULL,75,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:43','2026-09-28 13:35:43','glow-gel-manicure','Manicure & Pedicure',1,NULL,0,349.00),(4,1,'Glow Gel Pedicure','With gel polish application.','fixed','379.00',NULL,90,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:43','2026-09-28 13:35:43','glow-gel-pedicure','Manicure & Pedicure',1,NULL,0,379.00),(5,1,'Gel Removal Off-House','Removal of gel polish applied by another salon.','fixed','100.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','gel-removal-off-house','Manicure & Pedicure',1,NULL,0,100.00),(6,1,'Gel Matte Top','Add-on matte top coat finish.','fixed','100.00',NULL,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','gel-matte-top','Manicure & Pedicure',1,NULL,0,100.00),(7,1,'Hand Paraffin Treatment','Deeply moisturizing warm wax dip for hands.','fixed','249.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hand-paraffin-treatment','Manicure & Pedicure',1,NULL,0,249.00),(8,1,'Arm Paraffin Treatment','Warm wax treatment for the arms.','fixed','299.00',NULL,35,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','arm-paraffin-treatment','Manicure & Pedicure',1,NULL,0,299.00),(9,1,'Foot Paraffin Treatment','Warm wax treatment for the feet.','fixed','299.00',NULL,35,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','foot-paraffin-treatment','Manicure & Pedicure',1,NULL,0,299.00),(10,1,'Leg Paraffin Treatment','Warm wax treatment for the legs.','fixed','399.00',NULL,45,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','leg-paraffin-treatment','Manicure & Pedicure',1,NULL,0,399.00),(11,2,'Nail Extension w/ Gel Polish','Full set nail extension with gel polish.','tiered','799.00',999.00,120,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-extension-w-gel-polish','Nail Art & Extension',2,NULL,0,799.00),(12,2,'Nail Extension Removal','Removal of a full set of extensions.','fixed','200.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-extension-removal','Nail Art & Extension',2,NULL,0,200.00),(13,2,'Nail Repair (Per Nail)','Repair of a damaged nail.','starting_at','100.00',NULL,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-repair-per-nail','Nail Art & Extension',2,NULL,0,100.00),(14,2,'Nail Art - Chrome','Mirror chrome accent on nails.','fixed','100.00',NULL,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-art-chrome','Nail Art & Extension',2,NULL,0,100.00),(15,2,'Nail Art - Ombre','Fading gradient nail art.','fixed','100.00',NULL,20,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-art-ombre','Nail Art & Extension',2,NULL,0,100.00),(16,2,'Nail Art - Gems / Rhinestones','Crystals and rhinestone accents.','starting_at','50.00',NULL,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-art-gems-rhinestones','Nail Art & Extension',2,NULL,0,50.00),(17,2,'Nail Art - Stickers / Flakes','Charms, stickers, and foil flakes.','starting_at','50.00',NULL,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-art-stickers-flakes','Nail Art & Extension',2,NULL,0,50.00),(18,2,'Nail Art - Nail Stone','Decorative nail stone accents.','starting_at','50.00',NULL,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nail-art-nail-stone','Nail Art & Extension',2,NULL,0,50.00),(19,3,'Glow Hand Spa','Complete hand care ritual.','fixed','299.00',NULL,45,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','glow-hand-spa','Spa Services',3,NULL,0,299.00),(20,3,'Glow Foot Spa','Relaxing soak, scrub, and massage for the feet.','fixed','449.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','glow-foot-spa','Spa Services',3,NULL,0,449.00),(21,3,'Glow Signature Hand Spa','Extended hand spa with paraffin.','fixed','499.00',NULL,75,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','glow-signature-hand-spa','Spa Services',3,NULL,0,499.00),(22,3,'Glow Signature Foot Spa','Extended foot spa with warm towel treatment.','fixed','599.00',NULL,90,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','glow-signature-foot-spa','Spa Services',3,NULL,0,599.00),(23,3,'Hair Spa Aromatic Scalp Treat','Soothing aromatic scalp massage and treatment.','fixed','200.00',NULL,45,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hair-spa-aromatic-scalp-treat','Spa Services',3,NULL,0,200.00),(24,5,'Fancy Classic Lashes','Classic 1:1 lash extension set.','fixed','749.00',NULL,120,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','fancy-classic-lashes','Brow & Lash Extension',4,NULL,0,749.00),(25,5,'Glamour Open Eye / Cat Eye Hybrid','Hybrid volume lash set.','fixed','999.00',NULL,150,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','glamour-open-eye-cat-eye-hybrid','Brow & Lash Extension',4,NULL,0,999.00),(26,5,'Diva Lashes','Full volume lash set.','fixed','999.00',NULL,150,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','diva-lashes','Brow & Lash Extension',4,NULL,0,999.00),(27,5,'Eye Lash Perming','Lift and curl of natural lashes.','fixed','250.00',NULL,45,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','eye-lash-perming','Brow & Lash Extension',4,NULL,0,250.00),(28,5,'Lash Lift','Lash curl that lasts several weeks.','fixed','450.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','lash-lift','Brow & Lash Extension',4,NULL,0,450.00),(29,5,'Lash Tint','Eyelash tinting.','fixed','250.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','lash-tint','Brow & Lash Extension',4,NULL,0,250.00),(30,5,'Brow Lamination','Tamed, lifted brows that stay brushed up.','fixed','450.00',NULL,45,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','brow-lamination','Brow & Lash Extension',4,NULL,0,450.00),(31,5,'Brow Tint','Brow color tint.','fixed','150.00',NULL,20,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','brow-tint','Brow & Lash Extension',4,NULL,0,150.00),(32,5,'Synthetic Hair Extension','Full set synthetic lashes.','fixed','450.00',NULL,90,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','synthetic-hair-extension','Brow & Lash Extension',4,NULL,0,450.00),(33,5,'Human Hair Extension','Full set human hair lashes.','fixed','600.00',NULL,120,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','human-hair-extension','Brow & Lash Extension',4,NULL,0,600.00),(34,5,'Ear Candling','Relaxing ear candling treatment.','fixed','150.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','ear-candling','Brow & Lash Extension',4,NULL,0,150.00),(35,6,'Upper / Lower Lip Waxing',NULL,'fixed','149.00',NULL,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','upper-lower-lip-waxing','Hair Waxing Removal',5,NULL,0,149.00),(36,6,'Eyebrow Waxing',NULL,'fixed','499.00',NULL,20,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','eyebrow-waxing','Hair Waxing Removal',5,NULL,0,499.00),(37,6,'Underarm Waxing',NULL,'fixed','199.00',NULL,20,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','underarm-waxing','Hair Waxing Removal',5,NULL,0,199.00),(38,6,'Half Arm Waxing',NULL,'fixed','249.00',NULL,25,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','half-arm-waxing','Hair Waxing Removal',5,NULL,0,249.00),(39,6,'Full Arms Waxing',NULL,'fixed','499.00',NULL,40,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','full-arms-waxing','Hair Waxing Removal',5,NULL,0,499.00),(40,6,'Half Legs Waxing',NULL,'fixed','349.00',NULL,40,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','half-legs-waxing','Hair Waxing Removal',5,NULL,0,349.00),(41,6,'Full Legs Waxing',NULL,'fixed','699.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','full-legs-waxing','Hair Waxing Removal',5,NULL,0,699.00),(42,6,'Bikini Waxing',NULL,'fixed','299.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','bikini-waxing','Hair Waxing Removal',5,NULL,0,299.00),(43,6,'Brazilian Waxing',NULL,'fixed','399.00',NULL,45,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','brazilian-waxing','Hair Waxing Removal',5,NULL,0,399.00),(44,7,'Nape Bleaching',NULL,'fixed','200.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','nape-bleaching','Bleaching',6,NULL,0,200.00),(45,7,'Underarm Bleaching',NULL,'fixed','250.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','underarm-bleaching','Bleaching',6,NULL,0,250.00),(46,7,'Elbow / Knees Bleaching',NULL,'fixed','250.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','elbow-knees-bleaching','Bleaching',6,NULL,0,250.00),(47,7,'Inner Thighs Bleaching',NULL,'fixed','250.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','inner-thighs-bleaching','Bleaching',6,NULL,0,250.00),(48,7,'Whole Body Bleaching',NULL,'fixed','1300.00',NULL,120,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','whole-body-bleaching','Bleaching',6,NULL,0,1300.00),(49,8,'Bella Gluta Drip','Intravenous skin brightening drip.','fixed','1399.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','bella-gluta-drip','Glutathione Push OR Drip',NULL,NULL,0,1399.00),(50,8,'IV Push','Quick intravenous skin brightening push.','fixed','699.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','iv-push','Glutathione Push OR Drip',NULL,NULL,0,699.00),(51,9,'Swedish Massage (60 min)','Relaxing full body massage.','fixed','400.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','swedish-massage-60-min','Massage',8,NULL,0,400.00),(52,9,'Shiatsu Massage (60 min)','Pressure point massage therapy.','fixed','400.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','shiatsu-massage-60-min','Massage',8,NULL,0,400.00),(53,9,'Combination Massage (60 min)','Mix of Swedish and Shiatsu techniques.','fixed','500.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','combination-massage-60-min','Massage',8,NULL,0,500.00),(54,9,'Moving Ventosa (75 min)','Cupping therapy with moving cups.','fixed','799.00',NULL,75,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','moving-ventosa-75-min','Massage',8,NULL,0,799.00),(55,9,'Stationary Ventosa (75 min)','Cupping therapy with stationary cups.','tiered','799.00',899.00,75,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','stationary-ventosa-75-min','Massage',8,NULL,0,799.00),(56,9,'Aromatherapy Massage (90 min)','Massage with essential oil blends.','fixed','899.00',NULL,90,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','aromatherapy-massage-90-min','Massage',8,NULL,0,899.00),(57,9,'Hotstone Massage (90 min)','Warm basalt stones on key pressure points.','fixed','799.00',NULL,90,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hotstone-massage-90-min','Massage',8,NULL,0,799.00),(58,9,'Spot Massage - Head & Shoulder (30 min)',NULL,'fixed','200.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','spot-massage-head-shoulder-30-min','Massage',8,NULL,0,200.00),(59,9,'Spot Massage - Back (30 min)',NULL,'fixed','200.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','spot-massage-back-30-min','Massage',8,NULL,0,200.00),(60,9,'Spot Massage - Hands & Arms (30 min)',NULL,'fixed','200.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','spot-massage-hands-arms-30-min','Massage',8,NULL,0,200.00),(61,9,'Spot Massage - Feet & Legs (30 min)',NULL,'fixed','200.00',NULL,30,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','spot-massage-feet-legs-30-min','Massage',8,NULL,0,200.00),(62,11,'Glow Haircut','Cut and finish tailored to your hair length. Comes with a complimentary shampoo, blow-dry and express massage.','starting_at','starts @ 120',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'glow-haircut','Hair Care',9,NULL,0,120.00),(63,11,'Hot Oil Treatment','Restorative hot oil treatment.','starting_at','350.00',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hot-oil-treatment','Hair Care',9,NULL,0,350.00),(64,11,'Hair Color','Full colour service. Price varies with length and coverage. Comes with a complimentary shampoo, blow-dry and express massage.','starting_at','starts @ 500',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'hair-color','Hair Care',9,NULL,0,500.00),(65,11,'Hair Glowout','Glossy deep-conditioning treatment.','starting_at','850.00',NULL,90,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hair-glowout','Hair Care',9,NULL,0,850.00),(66,11,'Hair Spa','Deep-conditioning treatment for softness and shine. Comes with a complimentary shampoo, blow-dry and express massage.','starting_at','starts @ 450',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'hair-spa','Hair Care',9,NULL,0,450.00),(67,11,'Keratin Treatment','Smoothing keratin treatment.','starting_at','800.00',NULL,150,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','keratin-treatment','Hair Care',9,NULL,0,800.00),(68,11,'Hair Rebond','Straightening rebond treatment.','starting_at','1500.00',NULL,240,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hair-rebond','Hair Care',9,NULL,0,1500.00),(69,11,'Bleach','Lightening or pre-lightening treatment. Comes with a complimentary shampoo, blow-dry and express massage.','starting_at','starts @ 500',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'bleach','Hair Care',9,NULL,0,500.00),(70,11,'Kerabond Treatment','Premium smoothing treatment.','starting_at','2000.00',NULL,180,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','kerabond-treatment','Hair Care',9,NULL,0,2000.00),(71,11,'Hair Color + Rebond','Combo color and rebond service.','starting_at','1800.00',NULL,300,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hair-color-rebond','Hair Care',9,NULL,0,1800.00),(72,11,'Hair Color + Kerabond','Combo color and kerabond service.','starting_at','2300.00',NULL,300,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','hair-color-kerabond','Hair Care',9,NULL,0,2300.00),(73,12,'Upper Lip Threading',NULL,'tiered','70.00',100.00,10,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','upper-lip-threading','Threading',10,NULL,0,70.00),(74,12,'Chin Threading',NULL,'tiered','90.00',150.00,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','chin-threading','Threading',10,NULL,0,90.00),(75,12,'Eyebrow Threading',NULL,'tiered','100.00',150.00,15,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','eyebrow-threading','Threading',10,NULL,0,100.00),(76,12,'Underarms Threading',NULL,'fixed','150.00',NULL,20,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','underarms-threading','Threading',10,NULL,0,150.00),(77,12,'Full Face Threading',NULL,'fixed','300.00',NULL,40,NULL,1,'2026-09-25 15:43:47','2026-09-28 13:35:44','2026-09-28 13:35:44','full-face-threading','Threading',10,NULL,0,300.00),(78,13,'Kiddie Mani','A gentle manicure sized and shaped for young hands.','fixed','69',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'kiddie-mani','Kiddie Services',11,NULL,0,69.00),(79,13,'Kiddie Pedi','A gentle pedicure for young feet.','fixed','89',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'kiddie-pedi','Kiddie Services',11,NULL,0,89.00),(80,13,'Kiddie Hand Spa','A soak, scrub and massage for young hands.','fixed','149',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'kiddie-hand-spa','Kiddie Services',11,NULL,0,149.00),(81,13,'Kiddie Foot Spa','A soak, scrub and massage for young feet.','fixed','199',NULL,60,NULL,1,'2026-09-25 15:43:47','2026-10-02 01:20:51',NULL,'kiddie-foot-spa','Kiddie Services',11,NULL,0,199.00),(82,NULL,'Glow Manicure',NULL,'fixed','119.00',NULL,NULL,NULL,1,'2026-09-28 18:56:25','2026-10-01 04:50:40','2026-10-01 04:50:40','glow-manicure-2','Manicure & Pedicure',1,'NULL',0,119.00),(83,NULL,'Nail Extension',NULL,'fixed','799.00',NULL,NULL,NULL,1,'2026-09-28 18:58:06','2026-10-01 04:50:44','2026-10-01 04:50:44','nail-extension','Nail Art & Extension',2,'NULL',0,799.00),(84,NULL,'Brow Threading',NULL,'fixed','150.00',NULL,30,NULL,1,'2026-09-30 18:03:54','2026-09-30 18:13:02','2026-09-30 18:13:02','brow-threading-6abd4f0a584d4','Threading',10,'NULL',0,150.00),(85,NULL,'Brow Wax',NULL,'fixed','200.00',NULL,30,NULL,1,'2026-09-30 18:03:54','2026-09-30 18:13:02','2026-09-30 18:13:02','brow-wax-6abd4f0a641f1','Threading',10,'NULL',0,200.00),(86,NULL,'Upper Lip Threading',NULL,'fixed','100.00',NULL,20,NULL,1,'2026-09-30 18:03:54','2026-09-30 18:13:02','2026-09-30 18:13:02','upper-lip-6abd4f0a6510e','Threading',10,'NULL',0,100.00),(87,NULL,'Full Face Threading',NULL,'fixed','350.00',NULL,60,NULL,1,'2026-09-30 18:03:54','2026-09-30 18:13:02','2026-09-30 18:13:02','full-face-6abd4f0a65ba6','Threading',10,'NULL',0,350.00),(88,NULL,'Brow Tint',NULL,'fixed','250.00',NULL,40,NULL,1,'2026-09-30 18:03:54','2026-09-30 18:13:02','2026-09-30 18:13:02','brow-tint-6abd4f0a6681d','Threading',10,'NULL',0,250.00),(89,NULL,'Glow Manicure',NULL,'fixed','119.00',NULL,NULL,NULL,1,'2026-10-01 05:02:40','2026-10-01 05:02:40',NULL,'glow-manicure-3','Manicure & Pedicure',1,'NULL',0,119.00),(90,NULL,'Glow Pedicure',NULL,'fixed','159.00',NULL,NULL,NULL,1,'2026-10-01 05:03:03','2026-10-01 05:03:03',NULL,'glow-pedicure-2','Manicure & Pedicure',1,'NULL',0,159.00),(91,NULL,'Glow Gel Maninure',NULL,'fixed','349.00',NULL,NULL,NULL,1,'2026-10-01 05:03:31','2026-10-01 05:03:31',NULL,'glow-gel-maninure','Manicure & Pedicure',1,'NULL',0,349.00),(92,NULL,'Glow Gel Pedicure',NULL,'fixed','379.00',NULL,NULL,NULL,1,'2026-10-01 05:04:06','2026-10-01 05:04:06',NULL,'glow-gel-pedicure-2','Manicure & Pedicure',1,'NULL',0,379.00),(93,NULL,'Gel Removal (Off- House)',NULL,'fixed','100.00',NULL,NULL,NULL,1,'2026-10-01 05:04:41','2026-10-01 05:04:41',NULL,'gel-removal-off-house-2','Manicure & Pedicure',1,'NULL',0,100.00),(94,NULL,'Gel Mate Top',NULL,'fixed','100.00',NULL,NULL,NULL,1,'2026-10-01 05:05:04','2026-10-01 05:05:04',NULL,'gel-mate-top','Manicure & Pedicure',1,'NULL',0,100.00),(95,NULL,'Hand Paraffin',NULL,'fixed','249.00',NULL,NULL,NULL,1,'2026-10-01 05:05:50','2026-10-01 05:05:50',NULL,'hand-paraffin','Manicure & Pedicure',1,'NULL',0,249.00),(96,NULL,'Arm Paraffin',NULL,'fixed','299.00',NULL,NULL,NULL,1,'2026-10-01 05:06:09','2026-10-04 21:32:19','2026-10-04 21:32:19','arm-paraffin','Manicure & Pedicure',1,'NULL',0,299.00),(97,NULL,'Foot Paraffin',NULL,'fixed','299.00',NULL,NULL,NULL,1,'2026-10-01 05:06:27','2026-10-01 05:06:27',NULL,'foot-paraffin','Manicure & Pedicure',1,'NULL',0,299.00),(98,NULL,'Leg Paraffin',NULL,'fixed','399.00',NULL,NULL,NULL,1,'2026-10-01 05:06:42','2026-10-01 05:06:42',NULL,'leg-paraffin','Manicure & Pedicure',1,'NULL',0,399.00),(99,NULL,'Nail Extension',NULL,'fixed','799.00',NULL,NULL,NULL,1,'2026-10-01 05:08:23','2026-10-01 05:08:23',NULL,'nail-extension-2','Nail Art & Extension',2,'NULL',0,799.00),(100,NULL,'Nail Extension w/ Gel Polish',NULL,'fixed','999.00',NULL,NULL,NULL,1,'2026-10-01 05:08:47','2026-10-01 05:08:47',NULL,'nail-extension-w-gel-polish-2','Nail Art & Extension',2,'NULL',0,999.00),(101,NULL,'Removal',NULL,'fixed','200.00',NULL,NULL,NULL,1,'2026-10-01 05:09:05','2026-10-01 05:09:05',NULL,'removal','Nail Art & Extension',2,'NULL',0,200.00),(110,NULL,'Repair (Per Nail)',NULL,'fixed','100+',NULL,NULL,NULL,1,'2026-10-01 23:30:08','2026-10-01 23:30:30',NULL,'repair-per-nail','Nail Art & Extension',2,'NULL',0,100.00),(111,NULL,'Nail Art',NULL,'fixed','100+',NULL,NULL,NULL,1,'2026-10-01 23:31:07','2026-10-01 23:31:07',NULL,'nail-art','Nail Art & Extension',2,'NULL',0,100.00),(112,NULL,'Nail Art Chrome',NULL,'fixed','100',NULL,NULL,NULL,1,'2026-10-01 23:31:39','2026-10-01 23:31:39',NULL,'nail-art-chrome-2','Nail Art & Extension',2,'NULL',0,100.00),(113,NULL,'Nail Art Ombre',NULL,'fixed','100',NULL,NULL,NULL,1,'2026-10-01 23:33:22','2026-10-01 23:33:22',NULL,'nail-art-ombre-2','Nail Art & Extension',2,'NULL',0,100.00),(114,NULL,'Nail Art Gems & Rhinestones',NULL,'fixed','50+',NULL,NULL,NULL,1,'2026-10-01 23:33:57','2026-10-01 23:34:57',NULL,'nail-art-gems-rhinestones-2','Nail Art & Extension',2,'NULL',0,50.00),(115,NULL,'Nail Art Sticker/ Flakes',NULL,'fixed','50+',NULL,NULL,NULL,1,'2026-10-01 23:34:48','2026-10-01 23:34:48',NULL,'nail-art-sticker-flakes','Nail Art & Extension',2,'NULL',0,50.00),(116,NULL,'Nail Stone',NULL,'fixed','50+',NULL,NULL,NULL,1,'2026-10-01 23:35:11','2026-10-01 23:35:11',NULL,'nail-stone','Nail Art & Extension',2,'NULL',0,50.00),(117,NULL,'Glow Hand Spa',NULL,'fixed','299',NULL,NULL,NULL,1,'2026-10-01 23:57:12','2026-10-01 23:57:12',NULL,'glow-hand-spa-2','Spa Services',3,'NULL',0,299.00),(118,NULL,'Glow Foot Spa',NULL,'fixed','449',NULL,NULL,NULL,1,'2026-10-01 23:57:36','2026-10-01 23:57:36',NULL,'glow-foot-spa-2','Spa Services',3,'NULL',0,449.00),(119,NULL,'Glow Signature Hand Spa',NULL,'fixed','499',NULL,NULL,NULL,1,'2026-10-01 23:58:30','2026-10-01 23:58:30',NULL,'glow-signature-hand-spa-2','Spa Services',3,'NULL',0,499.00),(120,NULL,'Glow Signature Foot Spa',NULL,'fixed','599',NULL,NULL,NULL,1,'2026-10-01 23:59:35','2026-10-01 23:59:35',NULL,'glow-signature-foot-spa-2','Spa Services',3,'NULL',0,599.00),(121,NULL,'Hair Spa (Aromatic Scalp Treat)',NULL,'fixed','200',NULL,NULL,NULL,1,'2026-10-02 00:02:04','2026-10-02 00:02:04',NULL,'hair-spa-aromatic-scalp-treat-2','Spa Services',3,'NULL',0,200.00),(122,NULL,'Glow Mani - Pedi',NULL,'fixed','239',NULL,NULL,NULL,1,'2026-10-02 00:08:00','2026-10-02 00:08:00',NULL,'glow-mani-pedi','Nail Care Pacakges',13,'NULL',0,239.00),(123,NULL,'Glow Mani - Pedi & Glow Hand & Foot Spa',NULL,'fixed','949',NULL,NULL,NULL,1,'2026-10-02 00:12:23','2026-10-02 00:12:23',NULL,'glow-mani-pedi-glow-hand-foot-spa','Nail Care Pacakges',13,'NULL',0,949.00),(124,NULL,'Eye Brow Threading/ Waxing, Eye Lash Extension, & upper Lip Waxing',NULL,'fixed','669',NULL,NULL,NULL,1,'2026-10-02 00:13:26','2026-10-02 00:13:26',NULL,'eye-brow-threading-waxing-eye-lash-extension-upper-lip-waxing','Nail Care Pacakges',13,'NULL',0,669.00),(125,NULL,'Hand & Foot Paraffin & Glow Mani - Pedi Gel',NULL,'fixed','1199',NULL,NULL,NULL,1,'2026-10-02 00:15:01','2026-10-02 00:15:01',NULL,'hand-foot-paraffin-glow-mani-pedi-gel','Nail Care Pacakges',13,'NULL',0,1199.00),(126,NULL,'Fancy Clssic',NULL,'fixed','749',NULL,NULL,NULL,1,'2026-10-02 00:21:01','2026-10-02 00:21:01',NULL,'fancy-clssic','Brow & Lash Extension',4,'NULL',0,749.00),(127,NULL,'Simple & Natural Glamour',NULL,'fixed','999',NULL,NULL,NULL,1,'2026-10-02 00:21:42','2026-10-02 00:21:42',NULL,'simple-natural-glamour','Brow & Lash Extension',4,'NULL',0,999.00),(128,NULL,'Open Eye or Cat Eye Hybrid Style Lashes Diva Lashes',NULL,'fixed','999',NULL,NULL,NULL,1,'2026-10-02 00:23:12','2026-10-02 00:23:12',NULL,'open-eye-or-cat-eye-hybrid-style-lashes-diva-lashes','Brow & Lash Extension',4,'NULL',0,999.00),(129,NULL,'Eye Lash Perming',NULL,'fixed','150',NULL,NULL,NULL,1,'2026-10-02 00:26:24','2026-10-02 00:26:24',NULL,'eye-lash-perming-2','Brow & Lash Extension',4,'NULL',0,150.00),(130,NULL,'Synthetic Hair Extension',NULL,'fixed','450',NULL,NULL,NULL,1,'2026-10-02 00:26:52','2026-10-02 00:26:52',NULL,'synthetic-hair-extension-2','Brow & Lash Extension',4,'NULL',0,450.00),(131,NULL,'Removal',NULL,'fixed','200',NULL,NULL,NULL,1,'2026-10-02 00:27:38','2026-10-02 00:27:38',NULL,'removal-2','Brow & Lash Extension',4,'NULL',0,200.00),(132,NULL,'Lash List',NULL,'fixed','450',NULL,NULL,NULL,1,'2026-10-02 00:27:52','2026-10-02 00:27:52',NULL,'lash-list','Brow & Lash Extension',4,'NULL',0,450.00),(133,NULL,'Lash Tint',NULL,'fixed','250',NULL,NULL,NULL,1,'2026-10-02 00:28:13','2026-10-02 00:28:13',NULL,'lash-tint-2','Brow & Lash Extension',4,'NULL',0,250.00),(134,NULL,'Brow Lamination',NULL,'fixed','450',NULL,NULL,NULL,1,'2026-10-02 00:29:14','2026-10-02 00:29:14',NULL,'brow-lamination-2','Brow & Lash Extension',4,'NULL',0,450.00),(135,NULL,'Brow Tint',NULL,'fixed','150',NULL,NULL,NULL,1,'2026-10-02 00:29:34','2026-10-02 00:29:34',NULL,'brow-tint-2','Brow & Lash Extension',4,'NULL',0,150.00),(136,NULL,'Ear Cadling',NULL,'fixed','150',NULL,NULL,NULL,1,'2026-10-02 00:30:30','2026-10-02 00:30:30',NULL,'ear-cadling','Brow & Lash Extension',4,'NULL',0,150.00),(137,NULL,'Upper Lip /Lower Lip',NULL,'fixed','149',NULL,NULL,NULL,1,'2026-10-02 00:32:39','2026-10-02 00:32:39',NULL,'upper-lip-lower-lip','Hair Waxing Removal',5,'NULL',0,149.00),(138,NULL,'Eyebrow',NULL,'fixed','499',NULL,NULL,NULL,1,'2026-10-02 00:32:51','2026-10-02 00:32:51',NULL,'eyebrow','Hair Waxing Removal',5,'NULL',0,499.00),(139,NULL,'Underarm',NULL,'fixed','199',NULL,NULL,NULL,1,'2026-10-02 00:33:07','2026-10-02 00:33:07',NULL,'underarm','Hair Waxing Removal',5,'NULL',0,199.00),(140,NULL,'Half Arm',NULL,'fixed','249',NULL,NULL,NULL,1,'2026-10-02 00:33:37','2026-10-02 00:33:37',NULL,'half-arm','Hair Waxing Removal',5,'NULL',0,249.00),(141,NULL,'Full Arms',NULL,'fixed','499',NULL,NULL,NULL,1,'2026-10-02 00:33:49','2026-10-02 00:33:49',NULL,'full-arms','Hair Waxing Removal',5,'NULL',0,499.00),(142,NULL,'Half Legs',NULL,'fixed','349',NULL,NULL,NULL,1,'2026-10-02 00:34:06','2026-10-02 00:34:06',NULL,'half-legs','Hair Waxing Removal',5,'NULL',0,349.00),(143,NULL,'Full Legs',NULL,'fixed','699',NULL,NULL,NULL,1,'2026-10-02 00:34:40','2026-10-02 00:34:40',NULL,'full-legs','Hair Waxing Removal',5,'NULL',0,699.00),(144,NULL,'Bikini',NULL,'fixed','299',NULL,NULL,NULL,1,'2026-10-02 00:43:33','2026-10-02 00:43:33',NULL,'bikini','Hair Waxing Removal',5,'NULL',0,299.00),(145,NULL,'Brazillian',NULL,'fixed','399',NULL,NULL,NULL,1,'2026-10-02 00:43:55','2026-10-02 00:43:55',NULL,'brazillian','Hair Waxing Removal',5,'NULL',0,399.00),(146,NULL,'Nape',NULL,'fixed','200',NULL,NULL,NULL,1,'2026-10-02 00:48:00','2026-10-02 00:48:00',NULL,'nape','Bleaching',6,'NULL',0,200.00),(147,NULL,'Underarm',NULL,'fixed','250',NULL,NULL,NULL,1,'2026-10-02 00:48:12','2026-10-02 00:48:12',NULL,'underarm-2','Bleaching',6,'NULL',0,250.00),(148,NULL,'Elbow/ Knees',NULL,'fixed','250',NULL,NULL,NULL,1,'2026-10-02 00:50:07','2026-10-02 00:50:07',NULL,'elbow-knees','Bleaching',6,'NULL',0,250.00),(149,NULL,'Inner Thighs',NULL,'fixed','250',NULL,NULL,NULL,1,'2026-10-02 00:52:36','2026-10-02 00:52:36',NULL,'inner-thighs','Bleaching',6,'NULL',0,250.00),(150,NULL,'Whole Body',NULL,'fixed','1300',NULL,NULL,NULL,1,'2026-10-02 00:52:48','2026-10-02 00:52:48',NULL,'whole-body','Bleaching',6,'NULL',0,1300.00),(151,NULL,'Bella Glut Drip',NULL,'fixed','1399',NULL,NULL,NULL,1,'2026-10-02 00:53:13','2026-10-02 00:53:13',NULL,'bella-glut-drip','Glutathione Push OR Drip',NULL,'NULL',0,1399.00),(152,NULL,'IV Push',NULL,'fixed','699',NULL,NULL,NULL,1,'2026-10-02 00:53:26','2026-10-02 00:53:26',NULL,'iv-push-2','Glutathione Push OR Drip',NULL,'NULL',0,699.00),(153,NULL,'Relaxing Massage',NULL,'fixed','400',NULL,NULL,NULL,1,'2026-10-02 00:53:55','2026-10-02 01:02:45','2026-10-02 01:02:45','relaxing-massage','Massage',8,'NULL',0,400.00),(154,NULL,'Swedish Massage (60 mins)',NULL,'fixed','400',NULL,NULL,NULL,1,'2026-10-02 00:54:20','2026-10-02 01:03:02',NULL,'swedish-massage-60-mins','Relaxing Massage',8,'NULL',0,400.00),(155,NULL,'Shiatsu Massage (60 mins)',NULL,'fixed','400',NULL,NULL,NULL,1,'2026-10-02 00:55:52','2026-10-02 01:02:02',NULL,'shiatsu-massage-60-mins','Relaxing Massage',8,'NULL',0,400.00),(156,NULL,'Combination (60 mins)',NULL,'fixed','500',NULL,NULL,NULL,1,'2026-10-02 00:56:26','2026-10-02 01:01:06',NULL,'combination-60-mins','Relaxing Massage',8,'NULL',0,500.00),(157,NULL,'Moving Ventosa Massage','Ventosa therapy with cupping moved across the back for circulation and muscle release.','fixed','799',NULL,75,NULL,1,'2026-10-02 00:57:55','2026-10-02 01:19:06',NULL,'moving-ventosa-massage-75mins','Therapeutic Massage',14,NULL,0,799.00),(158,NULL,'Stationary Ventosa Massage','Ventosa therapy with the cups held in place on the back for a deeper, targeted treatment.','fixed','799',NULL,75,NULL,1,'2026-10-02 01:19:06','2026-10-02 01:19:06',NULL,'stationary-ventosa-massage','Therapeutic Massage',14,NULL,0,799.00),(159,NULL,'Aromatherapy Massage','A full-length massage using essential oils chosen for relaxation.','fixed','799',NULL,90,NULL,1,'2026-10-02 01:19:06','2026-10-02 01:19:06',NULL,'aromatherapy-massage','Therapeutic Massage',14,NULL,0,799.00),(160,NULL,'Hotstone Massage','Warm basalt stones worked into the muscles to ease stiffness and tension.','fixed','799',NULL,90,NULL,1,'2026-10-02 01:19:06','2026-10-02 01:19:06',NULL,'hotstone-massage','Therapeutic Massage',14,NULL,0,799.00),(161,NULL,'Head + Shoulder','Focused relief for the head, neck and shoulders.','fixed','200',NULL,30,NULL,1,'2026-10-02 01:19:06','2026-10-02 01:19:06',NULL,'head-shoulder','Spot Massage',15,NULL,0,200.00),(162,NULL,'Back','Focused relief for the back.','fixed','200',NULL,30,NULL,1,'2026-10-02 01:19:06','2026-10-02 01:19:06',NULL,'back','Spot Massage',15,NULL,0,200.00),(163,NULL,'Hands + Arms','Focused relief for the hands and arms.','fixed','200',NULL,30,NULL,1,'2026-10-02 01:19:06','2026-10-02 01:19:06',NULL,'hands-arms','Spot Massage',15,NULL,0,200.00),(164,NULL,'Feet + Legs','Focused relief for the feet and lower legs.','fixed','200',NULL,30,NULL,1,'2026-10-02 01:19:06','2026-10-02 01:19:06',NULL,'feet-legs','Spot Massage',15,NULL,0,200.00),(166,NULL,'Hot Oil','Hot-oil treatment to nourish and add shine. Comes with a complimentary shampoo, blow-dry and express massage.','fixed','starts @ 350',NULL,60,NULL,1,'2026-10-02 01:20:51','2026-10-02 01:20:51',NULL,'hot-oil','Hair Care',9,NULL,0,350.00),(167,NULL,'Keratin','Keratin smoothing to reduce frizz and shorten styling time. Comes with a complimentary shampoo, blow-dry and express massage.','fixed','starts @ 800',NULL,60,NULL,1,'2026-10-02 01:20:51','2026-10-02 01:20:51',NULL,'keratin','Hair Care',9,NULL,0,800.00),(168,NULL,'Rebond','Chemical rebonding for a permanent straightened finish. Comes with a complimentary shampoo, blow-dry and express massage.','fixed','starts @ 1,500',NULL,60,NULL,1,'2026-10-02 01:20:51','2026-10-02 01:20:51',NULL,'rebond','Hair Care',9,NULL,0,1500.00),(169,NULL,'Kerabond','Keratin straightening bonded with keratin treatment for a smooth, glossy finish.','fixed','starts @ 2,000',NULL,60,NULL,1,'2026-10-02 01:20:51','2026-10-02 01:20:51',NULL,'kerabond','Hair Glowout',16,NULL,0,2000.00),(170,NULL,'Hair Color + Rebond','Colour and rebonding in one appointment for a single transformation.','fixed','starts @ 1,800',NULL,60,NULL,1,'2026-10-02 01:20:51','2026-10-02 01:20:51',NULL,'hair-glowout-hair-color-rebond','Hair Glowout',16,NULL,0,1800.00),(171,NULL,'Hair Color + Kerabond','Colour combined with keratin rebonding for the smoothest, glossiest result.','fixed','starts @ 2,300',NULL,60,NULL,1,'2026-10-02 01:20:51','2026-10-02 01:20:51',NULL,'hair-glowout-hair-color-kerabond','Hair Glowout',16,NULL,0,2300.00),(172,NULL,'Hair Color','Colour as part of the Hair Glowout treatment range.','fixed','starts @ 850',NULL,60,NULL,1,'2026-10-02 01:20:51','2026-10-02 01:20:51',NULL,'hair-glowout-hair-color','Hair Glowout',16,NULL,0,850.00);
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `staff_members`
--

DROP TABLE IF EXISTS `staff_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `staff_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `specialization` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff_members`
--

LOCK TABLES `staff_members` WRITE;
/*!40000 ALTER TABLE `staff_members` DISABLE KEYS */;
INSERT INTO `staff_members` VALUES (1,'Maria Santos','Nail Technician',1,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(2,'Jessa Reyes','Massage Therapist',1,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(3,'Dianne Cruz','Brow & Lash Artist',1,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(4,'Karen Lopez','Hair Stylist',1,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(5,'Aira Mendoza','Spa Therapist',1,'2026-09-25 15:43:47','2026-09-25 15:43:47'),(6,'Bea Fernandez','Glutathione Nurse',1,'2026-09-25 15:43:47','2026-09-25 15:43:47');
/*!40000 ALTER TABLE `staff_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `technicians`
--

DROP TABLE IF EXISTS `technicians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `technicians` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `technicians_is_active_index` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technicians`
--

LOCK TABLES `technicians` WRITE;
/*!40000 ALTER TABLE `technicians` DISABLE KEYS */;
INSERT INTO `technicians` VALUES (2,'Rosila Coloma','technician-photos/gkO9RJ5Zce9s4gqePnrLE5aao3QRbxMG9O69xqdP.jpg',1,'2026-09-30 06:00:53','2026-09-30 06:00:53',NULL),(3,'Rhoda Tagura','technician-photos/ZL2LbwicVPgaQn7TK9dObLWg4eczOjSVpcjUQV8l.jpg',1,'2026-09-30 06:01:05','2026-09-30 06:01:32',NULL),(4,'Noemi Padagas',NULL,1,'2026-09-30 06:01:18','2026-10-04 19:35:08','2026-10-04 19:35:08'),(5,'Noemi Padagas',NULL,1,'2026-10-04 19:35:14','2026-10-04 19:35:14',NULL);
/*!40000 ALTER TABLE `technicians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `terms_and_conditions`
--

DROP TABLE IF EXISTS `terms_and_conditions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terms_and_conditions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` varchar(255) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `terms_and_conditions_category_unique` (`category`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `terms_and_conditions`
--

LOCK TABLES `terms_and_conditions` WRITE;
/*!40000 ALTER TABLE `terms_and_conditions` DISABLE KEYS */;
INSERT INTO `terms_and_conditions` VALUES (10,'booking',NULL,'<h2>Booking Terms &amp; Conditions</h2>\r\n<p>These terms govern every appointment made through the Balai ti Arjud online booking system.</p>\r\n<h3>1. Confirmation</h3>\r\n<p>An appointment is <strong>not</strong> confirmed until you receive a confirmation notification from us. A request that is still marked <em>Pending</em> has not yet secured your slot.</p>\r\n<h3>2. Down Payment</h3>\r\n<p>A down payment is required to reserve your slot. Submit the GCash reference number on the booking form. Our team verifies the reference manually &mdash; the down payment shows as <em>Awaiting Verification</em> until then. Unverified down payments may be cancelled after 24 hours.</p>\r\n<h3>3. Arrival</h3>\r\n<p>Please arrive 10 minutes early. Arriving more than <strong>20 minutes late</strong> may shorten or forfeit your treatment.</p>\r\n<h3>4. Allergies &amp; Health</h3>\r\n<p>You are required to disclose allergies, sensitivities and current medications on the booking form. We cannot guarantee suitability of any treatment until we have this information.</p>\r\n<h3>5. Pricing</h3>\r\n<p>Prices shown are per service and may vary by hair length, thickness and variant selected. The final amount is confirmed on your booking summary before treatment begins.</p>','2026-10-01 20:13:53','2026-10-02 04:55:02',1,'2026-10-01 20:13:53',1),(12,'cancellation',NULL,'<h2>Cancellation Policy</h2>\r\n<p>We understand that plans change. Please read this policy before cancelling.</p>\r\n<h3>1. Free Cancellation</h3>\r\n<p>You may cancel or reschedule free of charge up to <strong>24 hours</strong> before your appointment time.</p>\r\n<h3>2. Late Cancellation</h3>\r\n<p>Cancellations made within 24 hours of the appointment time may forfeit the down payment, because the slot and the products used are reserved specifically for you.</p>\r\n<h3>3. No-Show</h3>\r\n<p>Failure to arrive without notice is treated as a late cancellation and the down payment is forfeited.</p>\r\n<h3>4. Cancellations by the Salon</h3>\r\n<p>If we need to cancel &mdash; for example due to an emergency closure or stock unavailability &mdash; you will be notified immediately and your down payment is returned in full.</p>\r\n<h3>5. How to Cancel</h3>\r\n<p>Use the <em>Cancel Appointment</em> action on your appointment, provide a reason, and agree to this policy.</p>','2026-10-02 04:30:13','2026-10-02 04:55:02',1,'2026-10-02 04:30:13',1),(13,'rescheduling',NULL,'<h2>Rescheduling Policy</h2>\n<p>Need a different time? Rescheduling is simple, subject to availability.</p>\n<h3>1. Free Reschedule</h3>\n<p>Reschedule free of charge up to <strong>24 hours</strong> before your appointment.</p>\n<h3>2. Availability</h3>\n<p>The new date and time are validated against our operating hours and any dates we have blocked. Only slots shown as available can be selected.</p>\n<h3>3. Late Reschedule</h3>\n<p>Rescheduling within 24 hours depends entirely on slot availability and may forfeit the down payment.</p>\n<h3>4. Repeated Changes</h3>\n<p>We may ask you to pay a new down payment if an appointment has been rescheduled more than twice.</p>','2026-10-02 04:30:25','2026-10-02 04:55:02',1,'2026-10-02 04:30:25',1);
/*!40000 ALTER TABLE `terms_and_conditions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_notifications`
--

DROP TABLE IF EXISTS `user_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `data` longtext DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_notifications_user_id_read_at_index` (`user_id`,`read_at`),
  CONSTRAINT `user_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_notifications`
--

LOCK TABLES `user_notifications` WRITE;
/*!40000 ALTER TABLE `user_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'customer',
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `username` varchar(64) DEFAULT NULL,
  `contact_number` varchar(32) DEFAULT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (13,NULL,'rucielmaeobias277@gmail.com',NULL,'customer',NULL,'$2y$12$4rgX2v6QJK8DQQaRtTgov.dAr.P.DAUUf.jkEQxJa4rlH4h22IRwK','WixoDMhIokAKu9lphTVsj4I32N0ARwNUF1UaCxkJF7oDni4CMDgOL5vFXWHw','2026-09-28 19:05:27','2026-10-06 20:44:32',NULL,'Ruciel Mae','Obias','rucielmaeobias277','09318467600',NULL,1,'2026-10-06 20:44:32'),(14,NULL,'navbar-probe@example.test',NULL,'customer',NULL,'$2y$12$N/TlUf999fukn8pb2QFEJ.nK98J3Xs7u1n7np13ZFCzlXswO74zFa','Jgh4gMBPDrkccTpMh4SeZR8nDypmXxeeE7JqihIw8OEtGI3oCsSo0a26YBxg','2026-09-29 18:22:13','2026-09-29 18:23:13','2026-09-29 18:23:13','Nav','Probe',NULL,NULL,NULL,1,'2026-09-29 18:22:20'),(15,NULL,'jtest@example.test',NULL,'customer',NULL,'$2y$12$1tSxR1DoWtdYebbhtLywo.OCT/J7MKgu8YfweqpGxv7bjHltJg0PG',NULL,'2026-10-01 15:34:34','2026-10-01 15:37:30','2026-10-01 15:37:30','J','Test','jtest','09171234567',NULL,1,'2026-10-01 15:35:17'),(16,NULL,'smoke.customer@example.test',NULL,'customer',NULL,'$2y$12$uBXtHBPwFS0XaPzrjDUqTe4Tv3vpnJPmSh4Q2pHoy7T3pK8aJztd2',NULL,'2026-10-03 18:09:41','2026-10-03 18:14:57','2026-10-03 18:14:57','Smoke','Customer','smokecust','09171234567',NULL,1,'2026-10-03 18:10:23'),(17,NULL,'strike@example.test',NULL,'customer',NULL,'$2y$12$2yyJbxBAqLos1WsrNgYJYuaZYkEBQB4SyxRIcvSAriO28N.vz0ImW',NULL,'2026-10-03 19:21:32','2026-10-03 19:23:09','2026-10-03 19:23:09','S','T','SjKe5b','09171234567',NULL,1,'2026-10-03 19:21:55');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-07  5:06:38
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `code_hash` varchar(255) NOT NULL DEFAULT '0',
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT 'NULL',
  PRIMARY KEY (`id`),
  KEY `password_reset_codes_email_index` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
