-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: 127.0.0.1    Database: bta
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admins`
--

LOCK TABLES `admins` WRITE;
/*!40000 ALTER TABLE `admins` DISABLE KEYS */;
INSERT INTO `admins` VALUES (1,'Maia','Arjud','admin','admin@balaitiarjud.test','$2y$12$mzLWa14yCW6uQ8QytI/uXuPnMY0kk52V3C9zks85BaSkKfzNC9cfK','admin',NULL,1,'2026-11-06 14:28:06','Hao5xIqGE6bKPLSuYHPJOPQGmILSqmGSnyffa9gN0vuanTzPwrm2ZN9AeJnR','2026-11-04 13:05:09','2026-11-06 14:28:06',NULL);
/*!40000 ALTER TABLE `admins` ENABLE KEYS */;
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
  CONSTRAINT `appointment_service_appointment_id_foreign` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `appointment_service_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointment_service_service_variant_id_foreign` FOREIGN KEY (`service_variant_id`) REFERENCES `service_variants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_service`
--

LOCK TABLES `appointment_service` WRITE;
/*!40000 ALTER TABLE `appointment_service` DISABLE KEYS */;
INSERT INTO `appointment_service` VALUES (1,1,34,NULL,'Bleach',NULL,500.00,'starts @ 500',60,1,'2026-11-06 14:54:22','2026-11-06 14:54:22'),(2,1,3,6,'Color & Highlights','Global Color',2200.00,'2200',150,1,'2026-11-06 14:54:22','2026-11-06 14:54:22');
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
  `from_status` enum('pending','confirmed','in_progress','completed','cancelled') DEFAULT NULL,
  `to_status` enum('pending','confirmed','in_progress','completed','cancelled') NOT NULL,
  `changed_by` enum('admin','customer','system') NOT NULL DEFAULT 'system',
  `changed_by_id` bigint(20) unsigned DEFAULT NULL,
  `changed_by_name` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `appointment_status_history_appointment_id_created_at_index` (`appointment_id`,`created_at`),
  KEY `appointment_status_history_archived_at_index` (`archived_at`),
  CONSTRAINT `appointment_status_history_appointment_id_foreign` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointment_status_history`
--

LOCK TABLES `appointment_status_history` WRITE;
/*!40000 ALTER TABLE `appointment_status_history` DISABLE KEYS */;
INSERT INTO `appointment_status_history` VALUES (1,1,NULL,'pending','customer',1,'Ruciel Mae Obias','Appointment submitted by customer.',NULL,'2026-11-06 14:54:22','2026-11-06 14:54:22'),(2,1,'pending','confirmed','admin',1,'Maia Arjud',NULL,NULL,'2026-11-06 14:55:43','2026-11-06 14:55:43'),(3,1,'confirmed','completed','admin',1,'Maia Arjud',NULL,NULL,'2026-11-06 14:55:50','2026-11-06 14:55:50');
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
  `reference_number` varchar(32) NOT NULL,
  `idempotency_key` char(36) DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_phone` varchar(32) NOT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` time NOT NULL,
  `allergies` text DEFAULT NULL,
  `last_services_availed` text DEFAULT NULL,
  `preferred_stylist_id` bigint(20) unsigned DEFAULT NULL,
  `technician_id` bigint(20) unsigned DEFAULT NULL,
  `special_request` text DEFAULT NULL,
  `down_payment_reference` varchar(64) DEFAULT NULL,
  `down_payment_amount` decimal(10,2) DEFAULT NULL,
  `down_payment_status` enum('unverified','verified','rejected','not_required') NOT NULL DEFAULT 'unverified',
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','confirmed','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `admin_seen_at` timestamp NULL DEFAULT NULL,
  `source` varchar(24) NOT NULL DEFAULT 'web',
  `admin_notes` text DEFAULT NULL,
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `reschedule_reason` varchar(255) DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `archived_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `archived_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `appointments_reference_number_unique` (`reference_number`),
  UNIQUE KEY `appointments_user_idempotency_unique` (`user_id`,`idempotency_key`),
  KEY `appointments_preferred_stylist_id_foreign` (`preferred_stylist_id`),
  KEY `appointments_preferred_date_preferred_time_index` (`preferred_date`,`preferred_time`),
  KEY `appointments_user_id_status_index` (`user_id`,`status`),
  KEY `appointments_preferred_date_index` (`preferred_date`),
  KEY `appointments_down_payment_status_index` (`down_payment_status`),
  KEY `appointments_status_index` (`status`),
  KEY `appointments_technician_id_foreign` (`technician_id`),
  KEY `appointments_archived_by_foreign` (`archived_by`),
  KEY `appointments_admin_seen_at_index` (`admin_seen_at`),
  CONSTRAINT `appointments_archived_by_foreign` FOREIGN KEY (`archived_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_preferred_stylist_id_foreign` FOREIGN KEY (`preferred_stylist_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_technician_id_foreign` FOREIGN KEY (`technician_id`) REFERENCES `technicians` (`id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (1,'BTA-20261106-BF13','090b43af-0be9-4aa0-999e-631f45183aee',1,'Ruciel Mae Obias','09318467600','rucielmaeobias277@gmail.com','2026-11-07','11:00:00',NULL,'none',NULL,NULL,NULL,NULL,NULL,'not_required',2700.00,'completed','2026-11-06 14:54:42','web',NULL,NULL,NULL,NULL,'2026-11-06 14:55:43','2026-11-06 14:55:50',NULL,NULL,'2026-11-06 14:54:22','2026-11-06 14:55:50',NULL,NULL);
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blocked_slots`
--

LOCK TABLES `blocked_slots` WRITE;
/*!40000 ALTER TABLE `blocked_slots` DISABLE KEYS */;
/*!40000 ALTER TABLE `blocked_slots` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('356a192b7913b04c54574d18c28d46e6395428ab','i:2;',1793978968),('356a192b7913b04c54574d18c28d46e6395428ab:timer','i:1793978968;',1793978968),('5c785c036466adea360111aa28563bfd556b5fba','i:4;',1793978952),('5c785c036466adea360111aa28563bfd556b5fba:timer','i:1793978952;',1793978952),('b49a159b1f2333ee367f3ff3d591944d','i:1;',1793976922),('b49a159b1f2333ee367f3ff3d591944d:timer','i:1793976922;',1793976922);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
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
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
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
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `topic` enum('general_inquiry','booking_question','service_question','pricing_question','product_inquiry','feedback_complaint','others') NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `admin_reply` text DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_messages_topic_index` (`topic`),
  KEY `contact_messages_is_read_index` (`is_read`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_messages`
--

LOCK TABLES `contact_messages` WRITE;
/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
INSERT INTO `contact_messages` VALUES (1,'Liza Mercado','liza@example.test','pricing_question','Hi! Do you have a package for hair colour plus a blowout? Also, how long does rebonding usually take?',0,NULL,NULL,'2026-11-04 13:05:11','2026-11-04 13:05:11'),(2,'Toni Alvarez','toni@example.test','booking_question','Can I reschedule my appointment to Saturday morning instead? Thank you!',0,NULL,NULL,'2026-11-04 13:05:11','2026-11-04 13:05:11'),(3,'Grace Lim','grace@example.test','service_question','I have a gel allergy. Which of your nail services would you recommend instead?',1,'Hi Grace! We suggest the Glow Manicure or Classic Pedicure with regular polish — both avoid gel products entirely. Reply here or call us to book.','2026-11-02 13:05:11','2026-11-04 13:05:11','2026-11-04 13:05:11');
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
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
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
-- Table structure for table `inventory_items`
--

DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `sku` varchar(64) NOT NULL,
  `category` varchar(255) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(24) NOT NULL DEFAULT 'pcs',
  `date_in` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `reorder_threshold` decimal(12,2) DEFAULT NULL,
  `supplier` varchar(255) DEFAULT NULL,
  `status_tag` enum('available','low_stock','best_seller','sold_out') NOT NULL DEFAULT 'available',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_items_sku_unique` (`sku`),
  KEY `inventory_items_category_index` (`category`),
  KEY `inventory_items_status_tag_index` (`status_tag`),
  KEY `inventory_items_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
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
  `attempts` tinyint(3) unsigned NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2024_01_01_000100_create_bta_tables',1),(5,'2024_01_01_000200_create_notifications_table',1),(6,'2026_09_27_000000_collapse_admin_roles_to_admin',1),(7,'2026_09_28_000000_add_time_to_blocked_dates',1),(8,'2026_09_29_000100_create_service_categories_table',1),(9,'2026_09_29_000200_add_service_category_to_blocked_dates',1),(10,'2026_09_29_000300_add_is_active_to_service_categories',1),(11,'2026_09_29_000400_add_dates_to_inventory_items',1),(12,'2026_09_29_000500_create_technicians_table',1),(13,'2026_09_29_000600_add_technician_to_appointments',1),(14,'2026_09_29_000700_make_inventory_stock_columns_nullable',1),(15,'2026_09_30_000000_drop_reviews_table',1),(16,'2026_10_01_000100_add_archived_to_appointments',1),(17,'2026_10_01_000100_split_service_price_into_display_and_base',1),(18,'2026_10_01_000200_drop_blocked_dates_table',1),(19,'2026_10_02_000100_add_photo_to_service_categories',1),(20,'2026_10_02_000200_repair_legacy_down_payment_status',1),(21,'2026_10_02_000300_add_admin_seen_at_to_appointments_table',1),(22,'2026_10_02_000400_drop_terms_version_column',1),(23,'2026_10_04_000100_add_minimum_notice_hours_to_salon_settings_table',1),(24,'2026_10_04_000200_create_blocked_slots_table',1),(25,'2026_10_04_000300_add_idempotency_key_to_appointments_table',1),(26,'2026_10_04_000400_close_mondays_in_operating_hours',1),(27,'2026_11_06_000100_add_archived_at_to_appointment_status_history_table',2);
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
INSERT INTO `notifications` VALUES ('10d0098d-0c0a-4c5b-8c29-17b1536839be','App\\Notifications\\AppointmentConfirmedNotification','App\\Models\\User',1,'{\"title\":\"Your appointment is confirmed\",\"message\":\"We look forward to welcoming you for Bleach, Color & Highlights (Global Color).\",\"icon\":\"check\",\"tone\":\"confirmed\",\"url\":\"http:\\/\\/localhost:8000\\/appointments?view=1\",\"appointment_id\":1,\"reference_number\":\"BTA-20261106-BF13\"}','2026-11-06 15:05:33','2026-11-06 14:55:43','2026-11-06 15:05:33'),('5d0f7ba6-4ac8-4746-a27c-f59f6899118b','App\\Notifications\\AppointmentCompletedNotification','App\\Models\\User',1,'{\"title\":\"Thank you for visiting\",\"message\":\"We hope you enjoyed your Bleach, Color & Highlights (Global Color). We would love to see you again.\",\"icon\":\"sparkles\",\"tone\":\"completed\",\"url\":\"http:\\/\\/localhost:8000\\/appointments?view=1\",\"appointment_id\":1,\"reference_number\":\"BTA-20261106-BF13\"}','2026-11-06 15:05:33','2026-11-06 14:55:50','2026-11-06 15:05:33'),('d92a9af9-d8fc-4116-9ff2-51db09d22bb5','App\\Notifications\\AppointmentBookedNotification','App\\Models\\User',1,'{\"title\":\"Appointment request received\",\"message\":\"We have received your booking request and will confirm it shortly. Total: \\u20b12,700.00.\",\"icon\":\"calendar\",\"tone\":\"pending\",\"url\":\"http:\\/\\/localhost:8000\\/appointments?view=1\",\"appointment_id\":1,\"reference_number\":\"BTA-20261106-BF13\"}','2026-11-06 14:54:34','2026-11-06 14:54:22','2026-11-06 14:54:34');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_codes`
--

DROP TABLE IF EXISTS `password_reset_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `password_reset_codes_email_index` (`email`),
  KEY `password_reset_codes_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_codes`
--

LOCK TABLES `password_reset_codes` WRITE;
/*!40000 ALTER TABLE `password_reset_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_codes` ENABLE KEYS */;
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
  `description` text NOT NULL,
  `starts_at` date NOT NULL,
  `ends_at` date NOT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notified` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `promos_is_active_starts_at_ends_at_index` (`is_active`,`starts_at`,`ends_at`),
  KEY `promos_starts_at_index` (`starts_at`),
  KEY `promos_ends_at_index` (`ends_at`),
  KEY `promos_is_active_index` (`is_active`),
  KEY `promos_notified_index` (`notified`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promos`
--

LOCK TABLES `promos` WRITE;
/*!40000 ALTER TABLE `promos` DISABLE KEYS */;
INSERT INTO `promos` VALUES (1,'Glow Package Discount','Book any two signature services this month and enjoy 15% off your total. Perfect for pre-wedding prep or a mid-year reset.','2026-10-30','2026-11-29',NULL,0,0,'2026-11-04 13:05:11','2026-11-06 15:07:39',NULL),(2,'Loyalty Week: Free Manicure Upgrade','Book a Gelish Manicure during Loyalty Week and upgrade to Spa Pedicure pricing at no extra cost.','2026-11-06','2026-11-30',NULL,1,0,'2026-11-04 13:05:11','2026-11-06 15:07:09',NULL),(3,'Mother’s Day Special','A relaxing 60-minute massage plus a hydrating facial at a special Mother’s Day rate.','2026-09-05','2026-09-12',NULL,0,0,'2026-11-04 13:05:11','2026-11-04 13:05:11',NULL);
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
  `operating_hours` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`operating_hours`)),
  `slot_interval_minutes` smallint(5) unsigned NOT NULL DEFAULT 30,
  `booking_lead_days` smallint(5) unsigned NOT NULL DEFAULT 60,
  `minimum_notice_hours` smallint(5) unsigned NOT NULL DEFAULT 24,
  `down_payment_required` tinyint(1) NOT NULL DEFAULT 1,
  `down_payment_percentage` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salon_settings`
--

LOCK TABLES `salon_settings` WRITE;
/*!40000 ALTER TABLE `salon_settings` DISABLE KEYS */;
INSERT INTO `salon_settings` VALUES (1,'Balai ti Arjud — Glow & Co. Beauty Lounge','Purok 5, Abra, Philippines','+63 900 000 0000','hello@balaitiarjud.test','{\"tuesday\":[\"09:00\",\"17:00\"],\"wednesday\":[\"09:00\",\"17:00\"],\"thursday\":[\"09:00\",\"17:00\"],\"friday\":[\"09:00\",\"17:00\"],\"saturday\":[\"09:00\",\"17:00\"],\"sunday\":[\"09:00\",\"17:00\"]}',30,60,24,0,50,'2026-11-04 13:05:09','2026-11-04 13:05:09');
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
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_categories`
--

LOCK TABLES `service_categories` WRITE;
/*!40000 ALTER TABLE `service_categories` DISABLE KEYS */;
INSERT INTO `service_categories` VALUES (2,'Hair Care',1,'categories/JkBdr5KJ4dPiHyl2cCI6YyDTReVyXIeD7hHYIJTf.jpg','#EC4899',2,'2026-11-04 13:05:09','2026-11-06 15:09:11'),(4,'Lash & Brow',1,'categories/7zMFqYDaFmNFOOBNP1Y6j8YrmT6ag2zn2vquOUDI.jpg','#B45309',4,'2026-11-04 13:05:10','2026-11-06 15:09:33'),(5,'Facial',1,'categories/JRfCfgW0AjPwd0F80bQNx1XADungq297S0L7Bfcb.jpg','#4F46E5',5,'2026-11-04 13:05:10','2026-11-06 15:09:54'),(6,'Massage & Spa',1,'categories/acxD1kanqcU0BOCVfYPlCzJmohGyu4gGDcJiYND7.jpg','#F59E0B',6,'2026-11-04 13:05:10','2026-11-06 15:12:01'),(7,'Waxing & Threading',1,'categories/dtNRgMdyZBRXWtLxibEUfxxRIVBSgRuVVJaaid3I.jpg','#64748B',7,'2026-11-04 13:05:10','2026-11-06 15:10:24'),(10,'Therapeutic Massage',1,'categories/j7s56Q9DRtvVbC3pjnXPsXn8JSHeBvUBDUSDqf40.png','#0284C7',10,'2026-11-04 13:05:10','2026-11-06 15:24:52'),(11,'Spot Massage',1,'categories/mHGlXtKVZwuacCylxaD06Rq5LdEptVfDlfm07n2Q.png','#EA580C',11,'2026-11-04 13:05:10','2026-11-06 15:25:02'),(12,'Hair Glowout',1,'categories/jkiCOLKKVLXKXN23RwzINHcl6rpjKVGmSCqhblkN.jpg','#65A30D',12,'2026-11-04 13:05:10','2026-11-06 15:11:15'),(13,'Kiddie Services',1,'categories/Byc6ouLTarmplMrmlT1D4gHrq2Rcsp1NfM0Ss5SF.jpg','#D946EF',13,'2026-11-04 13:05:10','2026-11-06 15:11:05'),(14,'Manicure & Pedicure',1,'categories/mQtV8PPX0fGq5OyxfdEoR7Cl6ftmO2dWUPT7tAwQ.jpg','#0D9488',14,'2026-11-06 15:17:28','2026-11-06 15:21:56'),(15,'Nail Art & Extension',1,'categories/6g5kpPtWLHRif3NdFPoVbV09Jo5P5kOXKhlbQiGa.jpg','#16A34A',15,'2026-11-06 15:17:28','2026-11-06 15:22:22'),(16,'Spa Services',1,'categories/lacmoHM1lQupcYLYfwM2xQGrGRTEolhhZtLdbz0q.jpg','#E11D48',16,'2026-11-06 15:17:29','2026-11-06 15:22:37'),(17,'Brow & Lash Extension',1,'categories/o8XS3szbt5wnDxqxlYJ5SFwPEagydRntkfJvPOXU.jpg','#E11D48',17,'2026-11-06 15:17:29','2026-11-06 15:23:25'),(18,'Hair Waxing Removal',1,'categories/ezDv5chhW6JrZPSXblXM5f3wPzG6dE9iKH8YmUJz.jpg','#E11D48',18,'2026-11-06 15:17:29','2026-11-06 15:23:39'),(19,'Bleaching',1,'categories/HFNjOt8sMWNmYI863EN4HaR9MQ5Zcuo0O3lSQFGK.jpg','#E11D48',19,'2026-11-06 15:17:29','2026-11-06 15:23:52'),(20,'Relaxing Massage',1,'categories/D6BjQcYsEiR0BNo9SFEyWikodo7j5f2B7eM2tjwc.png','#E11D48',20,'2026-11-06 15:17:29','2026-11-06 15:24:07'),(21,'Nail Care Pacakges',1,'categories/PPn4pmEdeYLktwhqdJeR4Mg1HmnUObXWSjk0U45W.jpg','#E11D48',21,'2026-11-06 15:17:29','2026-11-06 15:24:38'),(22,'Glutathione Push OR Drip',1,'categories/x21qUdJUkMFNfkoU0eTpWL2FFOdurughAoHB3ZEo.jpg','#E11D48',22,'2026-11-06 15:17:29','2026-11-06 15:25:44');
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
  `quantity_per_service` decimal(12,2) NOT NULL DEFAULT 1.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
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
  `price` varchar(50) DEFAULT NULL,
  `duration_minutes` smallint(5) unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `base_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `service_variants_service_id_name_unique` (`service_id`,`name`),
  CONSTRAINT `service_variants_service_id_foreign` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `service_variants`
--

LOCK TABLES `service_variants` WRITE;
/*!40000 ALTER TABLE `service_variants` DISABLE KEYS */;
INSERT INTO `service_variants` VALUES (1,1,'Short Hair','400',NULL,1,'2026-11-04 13:05:09','2026-11-04 13:05:09',400.00),(2,1,'Medium Hair','450',NULL,0,'2026-11-04 13:05:09','2026-11-04 13:05:09',450.00),(3,1,'Long Hair','550',90,0,'2026-11-04 13:05:09','2026-11-04 13:05:09',550.00),(4,2,'Short Hair','2800',NULL,1,'2026-11-04 13:05:09','2026-11-04 13:05:09',2800.00),(5,2,'Long Hair','3800',240,0,'2026-11-04 13:05:09','2026-11-04 13:05:09',3800.00),(6,3,'Global Color','2200',NULL,1,'2026-11-04 13:05:09','2026-11-04 13:05:09',2200.00),(7,3,'Highlights','3200',210,0,'2026-11-04 13:05:09','2026-11-04 13:05:09',3200.00),(8,3,'Balayage','4200',240,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',4200.00),(9,4,'Classic','350',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',350.00),(10,4,'With Nail Art','450',60,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',450.00),(11,5,'Gelish Manicure','600',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',600.00),(12,5,'Gelish with Nail Art','750',90,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',750.00),(13,7,'Spa Pedicure','650',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',650.00),(14,7,'Spa Pedicure + Paraffin','850',105,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',850.00),(15,8,'Classic Set','1800',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',1800.00),(16,8,'Refill (2-3 weeks)','1200',90,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',1200.00),(17,9,'Volume Set','2200',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',2200.00),(18,9,'Volume Refill','1500',105,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',1500.00),(19,12,'Brightening Facial','1500',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',1500.00),(20,12,'Brightening + Peel','2000',105,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',2000.00),(21,13,'60 minutes','900',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',900.00),(22,13,'90 minutes','1250',90,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',1250.00),(23,19,'Bridal Glow','12500',NULL,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',12500.00),(24,19,'Bridal Glow + Extensions','16800',420,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',16800.00);
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
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `category` varchar(255) NOT NULL,
  `service_category_id` bigint(20) unsigned DEFAULT NULL,
  `price` varchar(50) DEFAULT NULL,
  `duration_minutes` smallint(5) unsigned NOT NULL DEFAULT 60,
  `description` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `base_price` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `services_slug_unique` (`slug`),
  KEY `services_category_is_active_index` (`category`,`is_active`),
  KEY `services_category_index` (`category`),
  KEY `services_is_active_index` (`is_active`),
  KEY `services_is_featured_index` (`is_featured`),
  KEY `services_service_category_id_foreign` (`service_category_id`),
  CONSTRAINT `services_service_category_id_foreign` FOREIGN KEY (`service_category_id`) REFERENCES `service_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
INSERT INTO `services` VALUES (1,'Signature Blowout & Styling','signature-blowout-styling','Hair Styling',NULL,'450',60,'A wash, blow-dry and finish tailored to your hair type and event.','photos/service-blowout.jpg',1,1,'2026-11-04 13:05:09','2026-11-04 13:05:09',NULL,450.00),(2,'Rebonding / Straightening','rebonding-straightening','Hair Care',2,'2800',180,'Chemical straightening for a smooth, manageable finish. Includes post-treatment care.',NULL,1,0,'2026-11-04 13:05:09','2026-11-04 13:05:09',NULL,2800.00),(3,'Color & Highlights','color-highlights','Hair Care',2,'2200',150,'Full colour change or highlight placement with gloss and blow-dry.',NULL,1,0,'2026-11-04 13:05:09','2026-11-04 13:05:09',NULL,2200.00),(4,'Glow Manicure','glow-manicure','Nail Care',NULL,'350',45,'Cleanse, shape, cuticle care and a high-shine finish.',NULL,1,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,350.00),(5,'Gelish Manicure','gelish-manicure','Nail Care',NULL,'600',75,'Long-wear gel colour with a strengthening base and top coat.','photos/service-manicure.jpg',1,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,600.00),(6,'Classic Pedicure','classic-pedicure','Nail Care',NULL,'400',60,'Foot soak, exfoliation, nail shaping and polish.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,400.00),(7,'Spa Pedicure','spa-pedicure','Nail Care',NULL,'650',90,'Aromatic soak, sugar scrub, mask, massage and polish.',NULL,1,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,650.00),(8,'Classic Eyelash Extensions','classic-eyelash-extensions','Lash & Brow',4,'1800',120,'Hand-applied individual lashes for a natural, defined look.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1800.00),(9,'Volume Lash Extensions','volume-lash-extensions','Lash & Brow',4,'2200',150,'Denser, fuller lashes mapped to your eye shape.',NULL,1,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,2200.00),(10,'Brow Lamination & Tint','brow-lamination-tint','Lash & Brow',4,'950',75,'Lifted, brushed-up brows with a tint to suit your hair colour.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,950.00),(11,'Hydrating Facial','hydrating-facial','Facial',5,'1100',75,'Gentle cleanse, exfoliation, hydrating mask and massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1100.00),(12,'Brightening Facial','brightening-facial','Facial',5,'1500',90,'Targeted treatment for dull or uneven skin tone.',NULL,1,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1500.00),(13,'Relaxing Massage (60 min)','relaxing-massage-60-min','Massage & Spa',6,'900',60,'A soothing full-body massage to unwind tension.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,900.00),(14,'Aromatherapy Massage (90 min)','aromatherapy-massage-90-min','Massage & Spa',6,'1500',90,'Essential-oil massage tailored for deep relaxation.','photos/service-massage.jpg',1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1500.00),(15,'Herbal Steam & Body Scrub','herbal-steam-body-scrub','Massage & Spa',6,'1200',90,'Warm herbal steam followed by a full-body exfoliation.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1200.00),(16,'Brazilian Waxing','brazilian-waxing','Waxing & Threading',7,'650',45,'Professional waxing with sensitive-skin formula.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,650.00),(17,'Threading','threading','Waxing & Threading',7,'200',30,'Precise eyebrow and facial threading.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,200.00),(18,'Occasion Makeup','occasion-makeup','Makeup',NULL,'1800',90,'Full-face makeup for events, photos and celebrations.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1800.00),(19,'Bridal Glow Package','bridal-glow-package','Packages',NULL,'12500',300,'A full pre-wedding journey: facial, manicure, lashes, hair and makeup trial.',NULL,1,1,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,12500.00),(20,'Moving Ventosa Massage','moving-ventosa-massage-75mins','Therapeutic Massage',10,'799',75,'Ventosa therapy with cupping moved across the back for circulation and muscle release.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,799.00),(21,'Stationary Ventosa Massage','stationary-ventosa-massage','Therapeutic Massage',10,'799',75,'Ventosa therapy with the cups held in place on the back for a deeper, targeted treatment.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,799.00),(22,'Aromatherapy Massage','aromatherapy-massage','Therapeutic Massage',10,'799',90,'A full-length massage using essential oils chosen for relaxation.',NULL,1,0,'2026-11-04 13:05:10','2026-11-06 15:05:24',NULL,799.00),(23,'Hotstone Massage','hotstone-massage','Therapeutic Massage',10,'799',90,'Warm basalt stones worked into the muscles to ease stiffness and tension.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,799.00),(24,'Head + Shoulder','head-shoulder','Spot Massage',11,'200',30,'Focused relief for the head, neck and shoulders.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,200.00),(25,'Back','back','Spot Massage',11,'200',30,'Focused relief for the back.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,200.00),(26,'Hands + Arms','hands-arms','Spot Massage',11,'200',30,'Focused relief for the hands and arms.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,200.00),(27,'Feet + Legs','feet-legs','Spot Massage',11,'200',30,'Focused relief for the feet and lower legs.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,200.00),(28,'Glow Haircut','glow-haircut','Hair Care',2,'starts @ 120',60,'Cut and finish tailored to your hair length. Comes with a complimentary shampoo, blow-dry and express massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,120.00),(29,'Hot Oil','hot-oil','Hair Care',2,'starts @ 350',60,'Hot-oil treatment to nourish and add shine. Comes with a complimentary shampoo, blow-dry and express massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,350.00),(30,'Hair Color','hair-color','Hair Care',2,'starts @ 500',60,'Full colour service. Price varies with length and coverage. Comes with a complimentary shampoo, blow-dry and express massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,500.00),(31,'Hair Spa','hair-spa','Hair Care',2,'starts @ 450',60,'Deep-conditioning treatment for softness and shine. Comes with a complimentary shampoo, blow-dry and express massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,450.00),(32,'Keratin','keratin','Hair Care',2,'starts @ 800',60,'Keratin smoothing to reduce frizz and shorten styling time. Comes with a complimentary shampoo, blow-dry and express massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,800.00),(33,'Rebond','rebond','Hair Care',2,'starts @ 1,500',60,'Chemical rebonding for a permanent straightened finish. Comes with a complimentary shampoo, blow-dry and express massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1500.00),(34,'Bleach','bleach','Hair Care',2,'starts @ 500',60,'Lightening or pre-lightening treatment. Comes with a complimentary shampoo, blow-dry and express massage.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,500.00),(35,'Kerabond','kerabond','Hair Glowout',12,'starts @ 2,000',60,'Keratin straightening bonded with keratin treatment for a smooth, glossy finish.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,2000.00),(36,'Hair Color + Rebond','hair-color-rebond','Hair Glowout',12,'starts @ 1,800',60,'Colour and rebonding in one appointment for a single transformation.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,1800.00),(37,'Hair Color + Kerabond','hair-color-kerabond','Hair Glowout',12,'starts @ 2,300',60,'Colour combined with keratin rebonding for the smoothest, glossiest result.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,2300.00),(38,'Hair Color','hair-glowout-hair-color','Hair Glowout',12,'starts @ 850',60,'Colour as part of the Hair Glowout treatment range.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,850.00),(39,'Kiddie Mani','kiddie-mani','Kiddie Services',13,'69',60,'A gentle manicure sized and shaped for young hands.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,69.00),(40,'Kiddie Pedi','kiddie-pedi','Kiddie Services',13,'89',60,'A gentle pedicure for young feet.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,89.00),(41,'Kiddie Hand Spa','kiddie-hand-spa','Kiddie Services',13,'149',60,'A soak, scrub and massage for young hands.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,149.00),(42,'Kiddie Foot Spa','kiddie-foot-spa','Kiddie Services',13,'199',60,'A soak, scrub and massage for young feet.',NULL,1,0,'2026-11-04 13:05:10','2026-11-04 13:05:10',NULL,199.00),(43,'Foot Paraffin','foot-paraffin','Manicure & Pedicure',14,'299.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,299.00),(44,'Gel Mate Top','gel-mate-top','Manicure & Pedicure',14,'100.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,100.00),(45,'Gel Removal (Off- House)','gel-removal-off-house-2','Manicure & Pedicure',14,'100.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,100.00),(46,'Glow Gel Maninure','glow-gel-maninure','Manicure & Pedicure',14,'349.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,349.00),(47,'Glow Gel Pedicure','glow-gel-pedicure-2','Manicure & Pedicure',14,'379.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,379.00),(48,'Glow Manicure','glow-manicure-3','Manicure & Pedicure',14,'119.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,119.00),(49,'Glow Pedicure','glow-pedicure-2','Manicure & Pedicure',14,'159.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,159.00),(50,'Hand Paraffin','hand-paraffin','Manicure & Pedicure',14,'249.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,249.00),(51,'Leg Paraffin','leg-paraffin','Manicure & Pedicure',14,'399.00',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,399.00),(52,'Nail Art','nail-art','Nail Art & Extension',15,'100+',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,100.00),(53,'Nail Art Chrome','nail-art-chrome-2','Nail Art & Extension',15,'100',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,100.00),(54,'Nail Art Gems & Rhinestones','nail-art-gems-rhinestones-2','Nail Art & Extension',15,'50+',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,50.00),(55,'Nail Art Ombre','nail-art-ombre-2','Nail Art & Extension',15,'100',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,100.00),(56,'Nail Art Sticker/ Flakes','nail-art-sticker-flakes','Nail Art & Extension',15,'50+',60,NULL,NULL,1,0,'2026-11-06 15:17:28','2026-11-06 15:17:28',NULL,50.00),(57,'Nail Extension','nail-extension-2','Nail Art & Extension',15,'799.00',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,799.00),(58,'Nail Extension w/ Gel Polish','nail-extension-w-gel-polish-2','Nail Art & Extension',15,'999.00',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,999.00),(59,'Nail Stone','nail-stone','Nail Art & Extension',15,'50+',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,50.00),(60,'Removal','removal','Nail Art & Extension',15,'200.00',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,200.00),(61,'Repair (Per Nail)','repair-per-nail','Nail Art & Extension',15,'100+',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,100.00),(62,'Glow Foot Spa','glow-foot-spa-2','Spa Services',16,'449',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,449.00),(63,'Glow Hand Spa','glow-hand-spa-2','Spa Services',16,'299',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,299.00),(64,'Glow Signature Foot Spa','glow-signature-foot-spa-2','Spa Services',16,'599',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,599.00),(65,'Glow Signature Hand Spa','glow-signature-hand-spa-2','Spa Services',16,'499',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,499.00),(66,'Hair Spa (Aromatic Scalp Treat)','hair-spa-aromatic-scalp-treat-2','Spa Services',16,'200',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,200.00),(67,'Brow Lamination','brow-lamination-2','Brow & Lash Extension',17,'450',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,450.00),(68,'Brow Tint','brow-tint-2','Brow & Lash Extension',17,'150',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,150.00),(69,'Ear Cadling','ear-cadling','Brow & Lash Extension',17,'150',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,150.00),(70,'Eye Lash Perming','eye-lash-perming-2','Brow & Lash Extension',17,'150',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,150.00),(71,'Fancy Clssic','fancy-clssic','Brow & Lash Extension',17,'749',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,749.00),(72,'Lash List','lash-list','Brow & Lash Extension',17,'450',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,450.00),(73,'Lash Tint','lash-tint-2','Brow & Lash Extension',17,'250',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,250.00),(74,'Open Eye or Cat Eye Hybrid Style Lashes Diva Lashes','open-eye-or-cat-eye-hybrid-style-lashes-diva-lashes','Brow & Lash Extension',17,'999',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,999.00),(75,'Removal','removal-2','Brow & Lash Extension',17,'200',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,200.00),(76,'Simple & Natural Glamour','simple-natural-glamour','Brow & Lash Extension',17,'999',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,999.00),(77,'Synthetic Hair Extension','synthetic-hair-extension-2','Brow & Lash Extension',17,'450',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,450.00),(78,'Bikini','bikini','Hair Waxing Removal',18,'299',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,299.00),(79,'Brazillian','brazillian','Hair Waxing Removal',18,'399',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,399.00),(80,'Eyebrow','eyebrow','Hair Waxing Removal',18,'499',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,499.00),(81,'Full Arms','full-arms','Hair Waxing Removal',18,'499',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,499.00),(82,'Full Legs','full-legs','Hair Waxing Removal',18,'699',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,699.00),(83,'Half Arm','half-arm','Hair Waxing Removal',18,'249',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,249.00),(84,'Half Legs','half-legs','Hair Waxing Removal',18,'349',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,349.00),(85,'Underarm','underarm','Hair Waxing Removal',18,'199',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,199.00),(86,'Upper Lip /Lower Lip','upper-lip-lower-lip','Hair Waxing Removal',18,'149',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,149.00),(87,'Elbow/ Knees','elbow-knees','Bleaching',19,'250',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,250.00),(88,'Inner Thighs','inner-thighs','Bleaching',19,'250',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,250.00),(89,'Nape','nape','Bleaching',19,'200',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,200.00),(90,'Underarm','underarm-2','Bleaching',19,'250',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,250.00),(91,'Whole Body','whole-body','Bleaching',19,'1300',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,1300.00),(92,'Combination (60 mins)','combination-60-mins','Relaxing Massage',20,'500',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,500.00),(93,'Shiatsu Massage (60 mins)','shiatsu-massage-60-mins','Relaxing Massage',20,'400',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,400.00),(94,'Swedish Massage (60 mins)','swedish-massage-60-mins','Relaxing Massage',20,'400',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,400.00),(95,'Eye Brow Threading/ Waxing, Eye Lash Extension, & upper Lip Waxing','eye-brow-threading-waxing-eye-lash-extension-upper-lip-waxing','Nail Care Pacakges',21,'669',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,669.00),(96,'Glow Mani - Pedi','glow-mani-pedi','Nail Care Pacakges',21,'239',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,239.00),(97,'Glow Mani - Pedi & Glow Hand & Foot Spa','glow-mani-pedi-glow-hand-foot-spa','Nail Care Pacakges',21,'949',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,949.00),(98,'Hand & Foot Paraffin & Glow Mani - Pedi Gel','hand-foot-paraffin-glow-mani-pedi-gel','Nail Care Pacakges',21,'1199',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,1199.00),(99,'Hair Color + Kerabond','hair-glowout-hair-color-kerabond','Hair Glowout',12,'starts @ 2,300',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,2300.00),(100,'Hair Color + Rebond','hair-glowout-hair-color-rebond','Hair Glowout',12,'starts @ 1,800',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,1800.00),(101,'Bella Glut Drip','bella-glut-drip','Glutathione Push OR Drip',22,'1399',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,1399.00),(102,'IV Push','iv-push-2','Glutathione Push OR Drip',22,'699',60,NULL,NULL,1,0,'2026-11-06 15:17:29','2026-11-06 15:17:29',NULL,699.00);
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
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

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('4tztLyvZk0YpPznFB5jOWKqntgCcaJXLwl5y6Fua',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJa3B1YVVKR1kyUTJRVk13U2xKa1RqUjJlV0pKT0ZFOVBTSXNJblpoYkhWbElqb2ljVFpUTjFwQlJYVkNZVFZ5YzB0VGVrcHRSRkZTU1ZwV1l6VXpTVVJUZHpKcGNEWnhTbkJyVTNSSFdVNVdkWEZLVTBkWWFubHFNVlpMTmtsRmFVcFdZM1ZGVVM5S2IzY3lSREpUY214alluWTBZVU4wWkVkdFoydHZiRlZDYTJFMllYcHVRMXAxYVZOWWNqTm1NRFYzT1VKd2RFYzRaREpRUVhGUU1qZDFWbXhvZWpKSldFcHJiVmwxTkRVM1VDODVSVVprZGpCc2JqWjBkRk5HU0RFNVJWUlBTVWQwVWsxTU1raEJWRGs1YlhoclozbzBkR0Z2WldSaGQydDRUSHBTTUZvNGJtWnRWbWxGVEZSRWJsZHRhalpSUVhOSmJURjNibEF2VlZKRlkyVlJaV2MyYjJKRGFVWTBRbmQyZGtWNVQyZFlaR2h3YUZsTlNqaExWa2hKUmxWUk1FdEpRa3RNWjBOVWFIZHpaR1o1WmpCTVJuYzlQU0lzSW0xaFl5STZJakpsWTJJeU5UYzBOV0pqTVRNMU1tRTBPREkxTW1KbVlqTTJZekJsTVRnNVpHUTBNemRtTm1JM09UQXlOelprWlRsaE9USTNZVEJtWkRObU1UQmtObU1pTENKMFlXY2lPaUlpZlE9PQ==',1793976357),('7SylAI0H3Xy6HDlJQvHkFruyL0gUTUhGlgrxTRMD',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJbUZIVEV0QlNUaE1PRWhzWWpCeFN5dElhbVI1TUdjOVBTSXNJblpoYkhWbElqb2lRbXRuV2xWcFdHNTRjbFZJTm14cWFtVmhNR3RPZUhCd1ZUQmFXbkUyTW5Jd0sxcHVlamxvYldoNVRTOWxWWHBYUkZWUlEweEZOMHhDTXpWS1NYcE5TVnBaZEZWaWVUaFJWak14YjBnd1ZUbHJRalJ6VDJOMk56WkJTWGQ1YmxsdFZYSm1Rbk5KVUhoTk4ySTRhMnBuVDJGT01rZDRka3RwYjNWQ1JsUkNTR3g1VDFWck1VaE5Na1J0VmpKM2RFMWxPRTFKVTI4eFZETlNVa1JLYVV0d0t6aEhTMkpuV2xObVJUSmxZemsxWlc4eWRuSmliR0l4YUN0MGVHOTVXVzVaWTBaUWNHZzBUbE5OWjJkdFR6ZE9aVlpPV2xNNVMwSnRORlZsVWxwVmVERllWWEl5ZG14amRXdDJaazl5UjNRNWRDdGplRUZGUW5WSFVWcGpkRFZPU2poNmRUSlJhRlZRWkZJeFJYVmhhME0xVVZCSWNGRTlQU0lzSW0xaFl5STZJalk0TXpVNE5EYzRZelUxTVdGbE1EaGpNakl4WkRNME5ESm1ZVE13WXpoall6SXhNbVEwWldJek9ESTFObUUwWXpVMVpXSm1NakEwWWpkaFkyUXlORGdpTENKMFlXY2lPaUlpZlE9PQ==',1793976363),('Ay2JeBN9oQVVfZCwHSIom4TJYNmvCim85NLyORLr',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJbU5CZFZSUUwzTktWa3BDYmpkUE0wazBSMjVxZEZFOVBTSXNJblpoYkhWbElqb2lLMGhCWlRaT01tSlpkV3hFVXpjMWIwd3dkbTQxYVdwRmJFMTNkbUp6U0VKM1FXOVdjRTVUUmpKQllUSXJlbk5vU21SalRFdExOREV5WVd4S1JpOW5iMEZtTVhBelMxQmtUbXBqVERORldYSktWM1ZLVFc1dlMxSjVNVXR6VW5OV05UbHJZVkpXT1dGd1IwbEZORmswWmlzMWRtdGxSbmhIYldWa2RtSjRTMk55ZFZOUk9VeElTVlJtYW04eE5VZG5WRkJVV0VoaFJtSm1OakYyUkZGR2NsVlFMMlJGVXpsWWFqSm9RbWh2YldsWU5TczVXVGx6ZFhSNmIydHBSWFYyZFZKb1VrNUhZazVSVUROamIxTTNVMU53WlhwVFVUSkRUbFpOZFU1Mk56Um1ibEprYjNWbFZXb3JSblZqVW5NMmNVUklMM2w2YkRKUmMzRmtlVVJCWVVFMVpWRllaVkpCUkRCSWMzcDVhMjFJZFU1NlEzYzlQU0lzSW0xaFl5STZJakkxTWpWaE9EUmpPREEzTWpBeE4yRTBOamRtWkRZME5HRTVNbUl4WmpjNU9XRTJZamhoWXpaalpqVXpaalUwT1dRd1kyUXlaV016WVdWbE1qUXpNR0VpTENKMFlXY2lPaUlpZlE9PQ==',1793976382),('bTtMWzbGAFY78GVihSXDk1jqx1mBoeBUeTcBBimE',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJa3hFUXpoRFRtWmtZMkZMTVdJdlEwdERVVVJrVUZFOVBTSXNJblpoYkhWbElqb2lOWFZDUVc5TVNsQkhiMVI2VVd0T1JYSjZNamRKV1VObVEyNVFORzB4VTBaMVFUUnViVnBHTkVJNGFuaGxkRXBFTUVsR056ZFJXbE5TVWxWWU1YaHlVekJqYVVjek5EbDNWWG9yUmtGR2EzaEtPRWRFWnpGNFNrMXBZVGMzVDBsTE5rbFNha3AyWkhOa00yOVBOVGQzYmtwV1psSTViQ3M1YlRWWVkwYzFUSGR4UjFnMlpGcFVSbU0yUnk5bldYaEpaVXhEU0haelprSmhhVEEzTml0Q0wwOXRjREpWU0dkc2JtSldVVlJLVFhwR1RGSlVVMnhETjFZeU1HTXplRUYwVjNaNVNtc3pLMnd5VTA1cVEzbHpXR05qTVV4eVpESlZLMUZCTkZkSU5UY3JPVzR2TVhoWVJuUkJObWRVVkhVd0sxY3pSVk4yUW1wVFIzVmhTVFozWlNJc0ltMWhZeUk2SWpObU9HUmxPRFF3T0dVeE5qSmhPVFExT1dFNE1tUmxPRFV4TldVMll6UTFZVFF5WXpCaE9HTXdNamN3WW1RMlptWTFZMkprTVRWbE56ZzBNMlpqTTJNaUxDSjBZV2NpT2lJaWZRPT0=',1793976382),('DRBj2u4pmMtkTLJL1QEcESnHRihAKCZtqvRFMTts',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJamxYU2pKcFJtOTZRM2d2V1RWWFExUjJNbTV3ZDFFOVBTSXNJblpoYkhWbElqb2llSEZ4Yld0b01uZE1Zall2VDJwR2FIUmlkRVV2Vm5CWWFVTlJjMDV3ZFRGblNteFdWMHN4TlVkUmRpODRkV2hWU25Gc1lqTk1jbFE1YVRoUlVtZE5NWGh1T1hZNFNrbFJhSEpNVVZObWVFRlliV0Z6ZUc0MldHcHJialpEVlM5TldVbHpRVTFhTUdwNWFFNHdaM001VkU5SVptZEZSRWhuYzFvNFRHaEhZMVprWkdobmJERnVNV0U0ZUVoNGFtTmtjSG81Y1hkeE1rUm9TMDVDWjB4R1ZDOTJjSHB6TlRKVE0xZENXRE5PUlVGQk4za3hRVGRzVEZSdllYUllXUzkwWW0xTmQxUjVXUzlMY2xwc1dtNWFSME5WWXpGcFlURkNVRmhwUjNVck5EY3JPV2RYZWl0M1NrWTNXbXBKY25SVVJGTk5VeXR6ZFRGdlRVVk9lbFpuSzNJeVZsVlFNblUwUVVWVE9FYzVVSE5EWm5odlVFRTlQU0lzSW0xaFl5STZJbVl5T0dSbU5qaGlaV1F6WlRneU5qWmhPV0V6TkdFMVptUXhNRFZsWkdSaU5tUTBOemhpWTJKak1tRmpOMlk1T0dGbE5qTm1NelptTURZeU1qWmlObUlpTENKMFlXY2lPaUlpZlE9PQ==',1793976369),('HnwjumsFP5R69dpyicwjKfamPhkU20CvD3R69D8A',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJalppU25Gc1NsRmlkM000YTNFMFltNVVRUzlSUjNjOVBTSXNJblpoYkhWbElqb2lVVXhQY1hWaGVYQnpUakY0UlRGNEszSmpOSGMyVlRVeGEyUXdLMnRLUVV0dlZGUlVPR2xrU2psNVNWRnZialJoU1U1cVdWbGpZelpaVTBwRlRYcE9hMWxtWm1GRGVXWTNhbUk1UlVoMFZXMXNiR0YxZW5CRVRFbzRNbnBRVEU1bGMwazBaMjQwYTFCTk1FSlRWbVpoWkM4MmFYRkRZWGhsUmtOT2JWY3pMMFphV1ZWYVVFNUxTa2hITW1KMVZsb3JjREJvTTBweWRGQm5SM0ZuSzB3dlFUZHVaRmMwTWtKeFpYZ3hVMmxVUjFWM1pEUnpOamQxVlZsQ2JESllSMU40ZVZWMVlVSXZieTg1UzFRNGRsWjFWV3R0WjNOREswVnJVMjlYVDFsRk1qUnFWMDFtUlRaRWNXeHBjRmN6VDJ4U01FOTFPWG8yWm1SRWFGTTRWMHR6TWlJc0ltMWhZeUk2SWpVMll6QTBZMkZsTkRNMlpEYzJaamRpWkdSbFlqazRaamN3T0RNME5qY3dZamN6Wm1aaU1EbG1Nak15TkRjNFpqTTVNVE5qWmpaaU1tVTNORFJsWVRNaUxDSjBZV2NpT2lJaWZRPT0=',1793974018),('lJDY7CcB1biZzRDlxYqBBQScx8pxoiCSdwKijMGQ',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:159.0) Gecko/20100101 Firefox/159.0','ZXlKcGRpSTZJbVphU1VGSWVFTlZkVEp1ZFZSRFIzRnJla1pOYUhjOVBTSXNJblpoYkhWbElqb2liSE5MT0hVM05GRk5abGxPTVVGbmEyTTNaRFEzVlc1SmVsWktUa3RFWWsxUE4wMHZkVU00Y0ROc2ExZFRTMHg2WmxJdlR6bE9iRWxJTkhsSlQySkZRbTV3ZFRWRGJVTklSVFZ4VmpJMldESXJka1JMVGtoQlZXUnpSaXR1TkRodVJGRXhlR05RU0drd1ltVk1kVWxJYkdoeWExZ3lTV05UTXpGVU9Xa3ZaVWhPTkZGdFREQXZPWFpEVTBKMlRUZEZjWEU0VEROUk5rNXlXalZqYVVwallqZGxZM0k0TWsxQ2FUTmxSMWhCWTNWM1VrbFlXVm8wTTAwM1NtaFlabEJ3UXpaNFNuaEZiVGs1UkhJdldYRkdUakpZWkM5Q2JtaFNXR1V6Tm14d2JGbDJTMmRTVDNkcE9XZDBSM1F4YUdscWNtdGhWVVpuUmt0eGJGUnRWbWxTWjNGUWRuaFNTV1ozYnpBMU1VZGhTR0Z3Vm1wMlJUSlVTSFpUVW1kQlRFRllkblZaYkZKWFRYWlplWHBGVlZVdmVuSlFXVmMwVTBsUllsZHlMMVJZTTFJMlFUSlNiV3hxUjJZMldDdHJUMEp5Y2xsWE5ESllLMFJTVVRkNGRYRmlVMVUxWms5cE9WRjVabEJaUFNJc0ltMWhZeUk2SWpFMU1UUTJOMkpqTkdaaE9EVTJZemc1TURWbVl6VTNPVGMxWWpnek5tVTBOVFJrWXpoaU4yWTJaRFV6WWpBNU1qZGhaV1JpWW1NNFptTXdObUkxTVdJaUxDSjBZV2NpT2lJaWZRPT0=',1793978922),('NgaRM8aO60kF6HWjzWOwi4QwCxuQxx0eyo9U1nuB',NULL,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Code/1.145.1 Chrome/150.0.7871.250 Electron/43.8.3 Safari/537.36','ZXlKcGRpSTZJa1pCY1VNd1JGTjRURko2YWpOaE56WklVM1kxZVdjOVBTSXNJblpoYkhWbElqb2llbEoxY1ZVcmEzUk5XVTE2Tm1oNEwwdHpjR2RtWTNReU1IZHFTRU5xY1RsTlluSjViMVJ6ZDNZdlNGUkdSRk5aYmk5WFNHSnlUbk01YkZKS00xUTBaRU5JWVZneVowZDVWVXRZZG5KM2VtVmFaMEpZY0dsVlVWWXhhRk14WmxKVVJXZDFRMVZaVGxobmIyVllZVnBRVkVWUWJIRlphM3A2U0VvMFozUTVWM05SUm5Zd1lWcFVOVkU0YVhKb1ZEaFVjVWw0T0hOdU1XMDNVazFOYVRBMmJIQkdkbmx5U1ROblFYVm1hamh4U0dWT1VqUTVkVU12T1hSV2FuVnROR0Y2VEZwaVZWcDNSWGhUTkdjMVUzcDVRVzByVWt4MmNGQnNOa2N5YlhRMlpscElVVkpSSzJ4UmRuZHlZWGxET1N0Mk5UZ3hjRGh1YVZSc1pHVm5UWGxHTXlJc0ltMWhZeUk2SW1RMk16azBaVFJrTWpCbE9HWTRZemhtTXpRek5tSmtOMkkxWXpoallqSTRPV1JqTjJWa1pqVXhNakl4TURjd01HWTBOREEzTmpjek5qSTJNREExWW1VaUxDSjBZV2NpT2lJaWZRPT0=',1793974057),('O0XvJuofdD7C4qWqsdThz3LM3iqBKZlJC82SY6A8',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJaXRSY0dKeVEyOVJjVGhDYzFKMmQwSkRjMFoyYzNjOVBTSXNJblpoYkhWbElqb2lNRU5TUVVNNGFIbGlkbGQ1VlVaeWFUWnpabGxSUlRBNE9GQlJOVUo2VFZaVVdqSnBVMGwxZWpKc1RXUk5ZamRTWVRKTlNIRm9WelIyVTBkSU1GUnZhekZaYmxBME5uRm9iU3RMU0dVelZreGxZalJZWkdWeE9WVjRVRnBaZFcxcFNuRnBTRlpzV1hsSU0yUldhbFF2TVhSS1VFTm5ablZSYVhkSWJHRnNMMnA2TlVrMkszSnlaVEkyZUdvclNsTTVlVE5UTUhGcFpXWjBTVGR2Y0M5b2JFeHNaMnh4VnpkNE1tUkhXRFF3UlVjNVRWZHRVblEwTkROeGJtWmxSMVJqU1daWFVXTm9TM2swVjI1cGVHVjBOWEY1Umt0bWVWSTVSa2RtWTIxak4wWTJlakZzVTNwR2FtcGhjemgxWlV0blIwWjRhM0ZqVG1kS2VVMDFjRk0yUWsxVVpXTXlaemxJVmxJdllreHdOVzVGV1VaV01rRTlQU0lzSW0xaFl5STZJamhpWkdReE1qTmlPRGswT1dKaU1tVTNZelUwWVRkbFl6RmtNalZpWWpka016Y3hZelppWkRZeVlUTm1ZbU16WXpGbE9XUXhOR0l6TUdWa09EaGlOV0VpTENKMFlXY2lPaUlpZlE9PQ==',1793976376),('OCFUKZMhtrECs8JtcMvXE9xFQd2S5hwLe8Is70f9',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJakZMYVRST1JHTnRhR1ZCVTBoT00wdG5TbWMxV0VFOVBTSXNJblpoYkhWbElqb2lMMWtyWmt4T1VXWk1VbkZLVFRWSGRYTm9WMnRvTm5aR1ZIVlJLM0JRVEhkRkt5c3plSFpIYW1SNEswbFlRV3B0ZVhCVUswMU9hVVJUTWtKWVkwbDJURTVvYmtod1lrUlJRMHBxVXl0MFNTOHlabGxzWkZKQ05tWlpPVkU1UTNKcU1XcFZZbk5hU25rclJsbzFUbVZ4UkU4clRETjBPVU0yUWl0alozRmlZa3RFZUdWR1NUVXZibU40V2xRMlRYaFRSVzFvUXpoWFRGWnlabFV2Tm1WTlZFODNjbGRSTjFseU9XTXhjRVJCYjBSQlZVMXNhbmxoUlhoa1lVa3JOMFpaTWxGYVZGZHpVVWxZUjJaa2NpdHFZVVJ1V0hRM1ptUTNiRmRyU2xac2RtcDZNRFl6TWpjMFVpOXBWamhyTkhFNFUzbHhjRVlyYVZSeU9HSmFMMVJuY0NJc0ltMWhZeUk2SW1ReVpUQmxNekl3T0dSbFl6QXlNalV6Wm1ObE1UWXpPRFF5TjJGbE1tWTFOVE5oWVdVMk16VTBaVE0yT0dRMFpHRm1OVGxoWkRCaE16VXdPR0poT0RnaUxDSjBZV2NpT2lJaWZRPT0=',1793974045),('paHut9AaQMMPZZHKhzVre4oADoxKcKc6viuZTwOE',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJamMxU2xJd09HcENhbWxJTW5NcmFVVXlTVmhuV1hjOVBTSXNJblpoYkhWbElqb2lhVXRHV25sVk5GQjVka05QSzFkeVpuQkVaMDlKU0hKdGRsUjZjVUZhT0RSYWFYZEJiRWhaUWpoVU1sSnZjMVY0U2paRVQzZFNZVFV6TW14YWMxcDFSR0kwVTBaalF6TjZSSEZFUlU1blMzY3pkRlZJZGt4TWRVOHlWbFZhWlhKQldUWlVWMGR0VDBwSk0xZHNOWFJIVmxsU1JFWlJRV0l4ZW04d01qbHhNMlE1UVdSc1YyRXhkVmhuTVRWalJsZGxNM1pWVW5ONFIxTmtkU3NyWVhOdU0wNHhXRFpqVVVkVlFYazJhR2xWWjBWTVIxY3daR3BRTTJ4b1NYVk1ZbUZOVDNrMFoyWlFLMHh1YVRZMU5uRXdSREY1WjFseVkyczJTbEJUWVVWRFZTdHFja2hwUjFKSWIyMU1OWE5ZVTNnNVl6Tm9ZMGQ1VWxkck1uZHpZMWx4WlVoMGFGWmFOMFJ1YlRGclYwbEJPV0ZSVGtoTU1FRTlQU0lzSW0xaFl5STZJbU5pT1RCak1qTTFNV016WmpnNE1qY3lZV1UyWlRneU1HVXhNVFl5TXpBd01XWXdPV0V5TURZek9EZG1OVE0wTWpZME9EbGlaRE5oTWpBeFlqaGhaRFlpTENKMFlXY2lPaUlpZlE9PQ==',1793978271),('RfF4AIYnuANcLPMSyHCaDtrrAO0XMN3pvuMMnCFm',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJbGxXUW5Kbk5YZFBWM05aWjFFeVJsUnBUMmd2VW1jOVBTSXNJblpoYkhWbElqb2lTbVpYYTNnemN5OTJUR0pXUWpoTFZXdHdUMXBwSzFrM01UQTFjVGszVkZoSGNuQjJWMHh0TXpkMlN6TlRSbE5JT0hnM1VuVk1XSG94ZVZOVFluZFZaVUozU25oMWVtRmpNWFUxUnpaTFVVWXZkRUYzZDJ3M00xUnRTM1ZSWjB0VE55OU5WWEJCZW5GR05IWXZRekpVUmtnMlJXcDVWRnBKZWxGVFNIRm9kRnB4U1dka2VGRkJWVkpxVVdneVQxWXJVV2REVkUxSFRHZG1aSFVyVjNwemVsWllUMkZwZW00MmFsUTJRalpvYTJOdWMwUnhZa1Z4VldSUFQxSm1URmRLYzNKeFNpOWFkRVpOVUZKbE1XZDBTSE5vTVdaaWIwOUNRbGxHVm1kYVEybDZNVkpxTUZKS1lYVkVXVXhhUmtKT1RtRnJiamhrZUVkaFNqZFZSamgwTWs5dFREUjVZa2MzUjFKNVQyVkdXWEZoY2t4WGNWRTlQU0lzSW0xaFl5STZJbVk0TnpZeE9EQXhNamRpWkRsaVpHWmpPV0ZpT1RVMk56VmhNRGN4WTJZM056Tm1PRE5rTkdFME5UVmtabVJpWkdabE5XRTBaamszT1dNMk5UQTFOVFFpTENKMFlXY2lPaUlpZlE9PQ==',1793976376),('SqzknFppMm5AdxWMe1OSV0jugRGcmI67gGjpr8m6',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:159.0) Gecko/20100101 Firefox/159.0','ZXlKcGRpSTZJa3RFVkhkT09HVnVSRVo1WXpjdk9FRjNhMXBDZUVFOVBTSXNJblpoYkhWbElqb2lUbnBqYlVObVkzRk5jV1UyTTFWTmNHMDJTVEJXWVV0M2RUVjRhRVpKWnpaVllrNHlPRmRNZDFkamExazFSVXAxU2xneFRFUnlLM2hpWVVwc1dFWnZhbmxSWm01TWIycHNlWGRGUjIxcVZIaHhUemx4TlhoRU5FUlJkRmRNWkc1eFpXMDVWSFZGZVdoYU5FMXpWMWRyVG05UlRWWlFSVEp6TUhaek5sQkZkR0p0Y2s1dVFtRlJTbWxtWTBwS1VHNHhkMGxJWkVaNE5USkVSbk5VYVU0eFNXRnFNWGx5Y1hsWmVsSXdXWE01Wm5nNFNURkJhRFY0Y205NU5WSnRUamRoTkVselIxbEZiMWQ1WkZjNU1YSTRTMGhsWVhCM04wTkNhVVJaYkZGbFZqVm5ZV293ZUVoclp6RTVaV0lyVFZOSVNHSm5jbXBoTlcxQ0swcHpPV2d6VW1sb1FsVm1hU3RpVW5wcFRWTkphVVpXWld0VVpsUmxaak14UWtaek1ITnBMMGhZVTNkdmJuTjNaV3Q1WjBaNlp6VjBNM1UyV1RKUWFGbHFaM0JMVDJOSWFuaEtUUzl4V0M5Q2JtZHhiVkZ5TkVwQ1MyZHBTVlJQZW5oSVkzQkVUbHB5TldFNE9VNVNUekIwV0ZOclVYbExja0pyV0dsT1VEUnZTMjFZVUdadVFWQXlkR05xWkVnelNXZGlXakZKT1ZaNkx5ODJURmR4VG01T1pYSTNRVWRZT1dWbFlqYzBOMGQwZVZSeFJFTTRaall5VFROWFZERlJkRGR1YW5FeFpubG5Ua1ppWlZodWVVTm5UMDVoWVhoRlZucG9jUzgxYlROVlltRmpVbFZqTkRGYU1XUk1NR0V6VFVKSU4zTjFXSEZ1TWxWWFMxTmtPSEJIY0VKb1pYTnNhMjkzVmpoUk1XSmlNM1o0Y0RJd1YzQkpTMUZCUFQwaUxDSnRZV01pT2lJNVlqTXdZbUkxT1ROak4yVmxaR0ZtTXpaaE1EWmpNVEZoTVdZMk9HWmhPREExWm1FNU5qZGhaRFpsTkRRMll6QTJZV05rWmpKa016STBabVkwWVRKbUlpd2lkR0ZuSWpvaUluMD0=',1793978923),('sUyN4bG9MeXMmGtbsv1FdBg62WErlayZrhDeSnZd',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJbEF2TVZWNllXZEtRMDh2TkdGbWJWTmpRMmRPTVhjOVBTSXNJblpoYkhWbElqb2lUV3hKVWxJek1VNUlhSEJsWjNWRlJrcDZTMmRNYVVoSldVMVRlbXhhVW5CNmVtYzRTa1ZWTlVNMlRrUlRjamRyU3k5TFJqVTRXRUpXTXpSVlZ6RlBUbEZGTkdzNWFVdzVVMFIwWjFFMksyd3JVeXRhWTB0UFpETlpUWE54ZDBwb01qZzBVa2h0UWtWYVZVaE9ZWEZLVWk4cmJYaEhVRzVYTjNoVVJWQjJka2RPTDFwQlVra3pSbFJEVDBGbWVrOUNjMnMxUzFwc09ERTNjMDF5WVZwRlEzTkhlVk5SVmxCT2JFZEhiRUpUTnpObk9IUXdZVWxLYXpCeWJrZDBkMEZ4VFdNNFRqaHhOVk5RYjNOM1dHVXJNV0ZHV1hwWVlTdEhka3hOWm5sQ1JGQTJibEZYVFZNclowNHJOamRTT0ZVcldsTkpaSGxvY1RsYVpFOVNSMDUzUjBoaE16aDBSMnMwYzFvM0sySlhjamxqVVZjeFRVRTlQU0lzSW0xaFl5STZJall4WkdNM1ptSTNZekZrTW1ObVlqYzFaVFk1T1RFd1lUSTRZVEZoT0RReE1EZ3dNV00wTkdWbFpUWm1aRFl6TTJSa09HWmxNalJpTUdRMVl6UmlaR1FpTENKMFlXY2lPaUlpZlE9PQ==',1793976364),('TschWVZYQtVb50OVnYQtOxn1TVPwBE7eGFH3i6FF',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJbk0yYVhCTWJ6QXpja0ZJWm1veE1UaFhOSEZXYTBFOVBTSXNJblpoYkhWbElqb2lXbU00Ym1kUmREVkRVbXB0VWtwa2IxaFZPSGxYYUc5V1MxRjZURE5tTVhGelF6QmxhVGwzVGtORlJtOVlXRlpzZDBnNFpITTNha0V5VEZCUmFHbFFSMWd4UVZoWkx6TnJiMlpKV0Vwd1FrZHFRMWhZYW5kSVJVSXhWMEpTTWxRMWVrVlNTamRQVXpob2NrTjNURlJWVVZoRlpFbFFUa2RXWVRNMlFqbGtXWGRaVWtKaVVEUjBXaXRTYjB0VE1sRlNkRVZ4U1dkSlpuZEdlbUUwT1doRFVEaHhXRkJOTmxCQlZUUk1jMHhVVm5aNGQyYzROMGxHZDI1Uk5XTlljbHByWkZwRmNTdDRjMUpQWkVZdlUzVlhVRnBRWldGbFUxYzVORlEyTDNjMVNUUjJObkpoYmt4Q2IxbHNPVEU1V0ZOUlR6Rk1hVmhVYVZKb01teFBkQzk0TkZCclVrSnJUSEkyT0dwNk4zVTRZMFozZDFsWFMyYzlQU0lzSW0xaFl5STZJbVl5WkRSak1HRXpZelJrTm1WaFpXWTNNVGRoWmpneFpHWmtNV1kzWXpOa09UaGhORFJsT1Raa1lUUmtZMkZqTUdGaFptWmlZamd6T0RjeFpUUXhaalFpTENKMFlXY2lPaUlpZlE9PQ==',1793976376),('vcokzDKPwL1FaiksvBmDReagJAPs1AVkWd6C5Stc',NULL,'127.0.0.1','curl/8.21.0','ZXlKcGRpSTZJalkxVG1wWmVXNXNRV2R5Y2pNdk5tSkNNM1V2YzNjOVBTSXNJblpoYkhWbElqb2lOMlJRTUhnMWQzWkRabkEyWXpGelZXOVpUSE4yWkUxMmFVWktabWNyZFdNMVZGSXJVelUyYkRaMU5VNU5TVTVrY1ZwRUwwcEpaVzl1U1dJeWVVdEdaM1o0Y0dGNGRVWnhaelJ5ZDJKVlVrd3hjMVpRV2pCM1VUaFVSbE5IY0VoVWJHNU9PV2s1THpoS09UQXhabVJHYkRkSVV5dDNaell2Tm0xblVsbEpZM0Z4VUZSTGFsSmxRbWg0Wm1WWWNEbHNaRGxvWlhFM1NUUTBXVlUzYWxKNU9FRktkUzkzWTJ4YVdYbGtTVWcyYVVsM1JreElTM1IwUW5CT1NFOUJNMHhUU1hoTlIxbDJjRll4T1ZSdWRFeE5iemhDVldwWlpGRTBWa1JEY0dKRWNrSTNTbTlEUVVaWGEybG1iRm8wWVd3M1UxSlFaa2RZTld4RFZIVlpkWGhzUXlJc0ltMWhZeUk2SW1Ga1pHSXdPV1F6TldSaU9HSXdaVEF4TXpJellqZ3lOVFZsWVdFM05USmpNRE00TWpCaU16WXhNMlU1Tm1ObU1UQXpZakEwTldZM056UmxOVFZtTVdFaUxDSjBZV2NpT2lJaWZRPT0=',1793976355);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technicians`
--

LOCK TABLES `technicians` WRITE;
/*!40000 ALTER TABLE `technicians` DISABLE KEYS */;
INSERT INTO `technicians` VALUES (1,'Rosila Coloma',NULL,1,'2026-11-06 15:26:40','2026-11-06 15:26:40',NULL),(2,'Rhoda Tagura',NULL,1,'2026-11-06 15:26:53','2026-11-06 15:26:53',NULL),(3,'Noemi Padagas',NULL,1,'2026-11-06 15:27:12','2026-11-06 15:27:12',NULL);
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
  `category` enum('booking','cancellation','rescheduling') NOT NULL,
  `content` longtext NOT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `terms_and_conditions_category_unique` (`category`),
  KEY `terms_and_conditions_created_by_foreign` (`created_by`),
  KEY `terms_and_conditions_category_is_published_index` (`category`,`is_published`),
  KEY `terms_and_conditions_is_published_index` (`is_published`),
  CONSTRAINT `terms_and_conditions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `terms_and_conditions`
--

LOCK TABLES `terms_and_conditions` WRITE;
/*!40000 ALTER TABLE `terms_and_conditions` DISABLE KEYS */;
INSERT INTO `terms_and_conditions` VALUES (1,'booking','<h2>Booking Terms &amp; Conditions</h2>\n<p>These terms govern every appointment made through the Balai ti Arjud online booking system.</p>\n<h3>1. Confirmation</h3>\n<p>An appointment is <strong>not</strong> confirmed until you receive a confirmation notification from us. A request that is still marked <em>Pending</em> has not yet secured your slot.</p>\n<h3>2. Down Payment</h3>\n<p>A down payment is required to reserve your slot. Submit the GCash reference number on the booking form. Our team verifies the reference manually &mdash; the down payment shows as <em>Awaiting Verification</em> until then. Unverified down payments may be cancelled after 24 hours.</p>\n<h3>3. Arrival</h3>\n<p>Please arrive 10 minutes early. Arriving more than <strong>20 minutes late</strong> may shorten or forfeit your treatment.</p>\n<h3>4. Allergies &amp; Health</h3>\n<p>You are required to disclose allergies, sensitivities and current medications on the booking form. We cannot guarantee suitability of any treatment until we have this information.</p>\n<h3>5. Pricing</h3>\n<p>Prices shown are per service and may vary by hair length, thickness and variant selected. The final amount is confirmed on your booking summary before treatment begins.</p>',1,'2026-10-21 13:05:11',1,'2026-11-04 13:05:11','2026-11-04 13:05:11'),(2,'cancellation','<h2>Cancellation Policy</h2>\n<p>We understand that plans change. Please read this policy before cancelling.</p>\n<h3>1. Free Cancellation</h3>\n<p>You may cancel or reschedule free of charge up to <strong>24 hours</strong> before your appointment time.</p>\n<h3>2. Late Cancellation</h3>\n<p>Cancellations made within 24 hours of the appointment time may forfeit the down payment, because the slot and the products used are reserved specifically for you.</p>\n<h3>3. No-Show</h3>\n<p>Failure to arrive without notice is treated as a late cancellation and the down payment is forfeited.</p>\n<h3>4. Cancellations by the Salon</h3>\n<p>If we need to cancel &mdash; for example due to an emergency closure or stock unavailability &mdash; you will be notified immediately and your down payment is returned in full.</p>\n<h3>5. How to Cancel</h3>\n<p>Use the <em>Cancel Appointment</em> action on your appointment, provide a reason, and agree to this policy.</p>',1,'2026-10-21 13:05:11',1,'2026-11-04 13:05:11','2026-11-04 13:05:11'),(3,'rescheduling','<h2>Rescheduling Policy</h2>\n<p>Need a different time? Rescheduling is simple, subject to availability.</p>\n<h3>1. Free Reschedule</h3>\n<p>Reschedule free of charge up to <strong>24 hours</strong> before your appointment.</p>\n<h3>2. Availability</h3>\n<p>The new date and time are validated against our operating hours and any dates we have blocked. Only slots shown as available can be selected.</p>\n<h3>3. Late Reschedule</h3>\n<p>Rescheduling within 24 hours depends entirely on slot availability and may forfeit the down payment.</p>\n<h3>4. Repeated Changes</h3>\n<p>We may ask you to pay a new down payment if an appointment has been rescheduled more than twice.</p>',1,'2026-10-21 13:05:11',1,'2026-11-04 13:05:11','2026-11-04 13:05:11');
/*!40000 ALTER TABLE `terms_and_conditions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(64) NOT NULL,
  `contact_number` varchar(32) NOT NULL,
  `password` varchar(255) NOT NULL,
  `profile_photo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_username_unique` (`username`),
  KEY `users_last_name_first_name_index` (`last_name`,`first_name`),
  KEY `users_is_active_index` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Ruciel Mae','Obias','rucielmaeobias277@gmail.com','rucielmaeobias277','09318467600','$2y$12$IlYqc.pHIjuF0A5slN1EN.y08zu34gNMw0TcbCknJFL.j4t8GUT2S',NULL,1,'2026-11-06 14:09:28','hpCIf6Li6dfFAxfYlMEi9vgWZOzjRmAeMrt1E7Vg3mJ6JDl10BXiLjMX1zR8','2026-11-04 13:05:10','2026-11-06 14:09:28',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'bta'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-06 23:28:47
