-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: dmu_complaints
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
-- Table structure for table `abusive_words`
--

DROP TABLE IF EXISTS `abusive_words`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `abusive_words` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `word` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `word` (`word`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `abusive_words`
--

LOCK TABLES `abusive_words` WRITE;
/*!40000 ALTER TABLE `abusive_words` DISABLE KEYS */;
INSERT INTO `abusive_words` VALUES (1,'hate','2025-04-26 15:29:20'),(2,'stupid','2025-04-26 15:29:20'),(3,'idiot','2025-04-26 15:29:20');
/*!40000 ALTER TABLE `abusive_words` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `committee_members`
--

DROP TABLE IF EXISTS `committee_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `committee_members` (
  `committee_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `is_handler` tinyint(1) DEFAULT 0,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`committee_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `committee_members_ibfk_1` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`id`) ON DELETE CASCADE,
  CONSTRAINT `committee_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `committee_members`
--

LOCK TABLES `committee_members` WRITE;
/*!40000 ALTER TABLE `committee_members` DISABLE KEYS */;
INSERT INTO `committee_members` VALUES (1,3,1,'2025-04-12 19:40:00'),(1,12,0,'2025-04-12 19:40:00'),(1,13,0,'2025-04-12 19:40:00'),(1,14,0,'2025-04-12 19:40:00');
/*!40000 ALTER TABLE `committee_members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `committees`
--

DROP TABLE IF EXISTS `committees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `committees` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `handler_id` int(10) unsigned NOT NULL,
  `complaint_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `handler_id` (`handler_id`),
  KEY `complaint_id` (`complaint_id`),
  CONSTRAINT `committees_ibfk_1` FOREIGN KEY (`handler_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `committees_ibfk_2` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `committees`
--

LOCK TABLES `committees` WRITE;
/*!40000 ALTER TABLE `committees` DISABLE KEYS */;
INSERT INTO `committees` VALUES (1,3,3,'2025-04-12 19:40:00');
/*!40000 ALTER TABLE `committees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `complaint_logs`
--

DROP TABLE IF EXISTS `complaint_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaint_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `complaint_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaint_logs`
--

LOCK TABLES `complaint_logs` WRITE;
/*!40000 ALTER TABLE `complaint_logs` DISABLE KEYS */;
INSERT INTO `complaint_logs` VALUES (1,1,'Abusive Complaint Attempt','User attempted to submit a complaint with abusive words: stupid','2025-04-01 12:30:00'),(2,1,'User Suspended','User suspended for 2 hours due to abusive content: stupid','2025-04-01 12:30:00'),(3,1,'Submitted','Complaint #1: Incorrect Course Registration','2025-04-02 09:00:00'),(4,2,'Submitted','Complaint #2: Payment Issue','2025-04-02 09:15:00'),(5,1,'Submitted','Complaint #3: Grading Issue','2025-04-12 22:30:00'),(6,2,'Submitted','Complaint #4: Transcript Error','2025-04-13 08:00:00'),(7,1,'Submitted','Complaint #5: Lab Access Issue','2025-04-15 10:00:00'),(8,2,'Submitted','Complaint #6: Fee Refund Delay','2025-04-16 11:00:00'),(9,1,'Submitted','Complaint #7: Administrative Delay in Fee Processing','2025-04-17 12:00:00'),(10,2,'Submitted','Complaint #8: Incorrect Billing Statement','2025-04-18 09:00:00');
/*!40000 ALTER TABLE `complaint_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `complaint_stereotypes`
--

DROP TABLE IF EXISTS `complaint_stereotypes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaint_stereotypes` (
  `complaint_id` int(10) unsigned NOT NULL,
  `stereotype_id` int(10) unsigned NOT NULL,
  `tagged_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`complaint_id`,`stereotype_id`),
  KEY `stereotype_id` (`stereotype_id`),
  KEY `tagged_by` (`tagged_by`),
  CONSTRAINT `complaint_stereotypes_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `complaint_stereotypes_ibfk_2` FOREIGN KEY (`stereotype_id`) REFERENCES `stereotypes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `complaint_stereotypes_ibfk_3` FOREIGN KEY (`tagged_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaint_stereotypes`
--

LOCK TABLES `complaint_stereotypes` WRITE;
/*!40000 ALTER TABLE `complaint_stereotypes` DISABLE KEYS */;
INSERT INTO `complaint_stereotypes` VALUES (3,3,3,'2025-04-12 19:35:00'),(5,4,3,'2025-04-15 07:05:00');
/*!40000 ALTER TABLE `complaint_stereotypes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `complaints`
--

DROP TABLE IF EXISTS `complaints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaints` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `handler_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `category` enum('academic','administrative') NOT NULL,
  `status` enum('pending','validated','in_progress','resolved','rejected','pending_more_info') DEFAULT 'pending',
  `visibility` enum('standard','anonymous') DEFAULT 'standard',
  `needs_video_chat` tinyint(1) DEFAULT 0,
  `needs_committee` tinyint(1) DEFAULT 0,
  `committee_id` int(10) unsigned DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `resolution_date` timestamp NULL DEFAULT NULL,
  `resolution_details` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `evidence_file` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `handler_id` (`handler_id`),
  KEY `committee_id` (`committee_id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `complaints_ibfk_2` FOREIGN KEY (`handler_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `complaints_ibfk_3` FOREIGN KEY (`committee_id`) REFERENCES `committees` (`id`) ON DELETE SET NULL,
  CONSTRAINT `complaints_ibfk_4` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaints`
--

LOCK TABLES `complaints` WRITE;
/*!40000 ALTER TABLE `complaints` DISABLE KEYS */;
INSERT INTO `complaints` VALUES (1,1,3,'Incorrect Course Registration','I was enrolled in the wrong course for Fall 2025.','academic','in_progress','standard',0,0,NULL,NULL,'2025-04-02 06:00:00','2025-04-02 07:00:00',NULL,NULL,NULL,'schedule_screenshot.png'),(2,2,3,'Payment Issue','Overcharged for tuition fees.','administrative','resolved','anonymous',0,0,NULL,NULL,'2025-04-02 06:15:00','2025-04-03 11:00:00','2025-04-03 11:00:00','Refund processed.',NULL,NULL),(3,1,3,'Grading Issue','Issue with grading process in CS101.','academic','in_progress','standard',0,1,1,1,'2025-04-12 19:30:00','2025-04-12 19:40:00',NULL,NULL,NULL,NULL),(4,2,3,'Transcript Error','My transcript shows an incorrect grade.','academic','','standard',0,0,NULL,NULL,'2025-04-13 05:00:00','2025-04-14 06:00:00',NULL,NULL,NULL,'transcript.pdf'),(5,1,3,'Lab Access Issue','Unable to access the computer lab due to scheduling conflict.','academic','pending','standard',0,1,NULL,1,'2025-04-15 07:00:00',NULL,NULL,NULL,NULL,NULL),(6,2,3,'Fee Refund Delay','Requested a refund for a dropped course, but it has been delayed.','administrative','pending','anonymous',0,0,NULL,NULL,'2025-04-16 08:00:00',NULL,NULL,NULL,NULL,NULL),(7,1,3,'Administrative Delay in Fee Processing','Fee payment processed late, causing registration issues.','administrative','in_progress','standard',0,0,NULL,NULL,'2025-04-17 09:00:00','2025-04-17 10:00:00',NULL,NULL,NULL,NULL),(8,2,3,'Incorrect Billing Statement','Received an incorrect billing statement for the semester.','administrative','pending','standard',0,0,NULL,NULL,'2025-04-18 06:00:00',NULL,NULL,NULL,NULL,'billing_statement.pdf');
/*!40000 ALTER TABLE `complaints` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `decisions`
--

DROP TABLE IF EXISTS `decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `decisions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `escalation_id` int(10) unsigned DEFAULT NULL,
  `complaint_id` int(10) unsigned NOT NULL,
  `sender_id` int(10) unsigned NOT NULL,
  `receiver_id` int(10) unsigned DEFAULT NULL,
  `decision_text` text NOT NULL,
  `status` enum('pending','final') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `escalation_id` (`escalation_id`),
  KEY `complaint_id` (`complaint_id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `decisions_ibfk_1` FOREIGN KEY (`escalation_id`) REFERENCES `escalations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `decisions_ibfk_2` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `decisions_ibfk_3` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `decisions_ibfk_4` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `decisions`
--

LOCK TABLES `decisions` WRITE;
/*!40000 ALTER TABLE `decisions` DISABLE KEYS */;
INSERT INTO `decisions` VALUES (1,2,2,6,3,'Refund processed after verifying overcharge.','final','2025-04-03 11:05:00'),(2,5,4,5,7,'Escalated to Campus Registrar for further review.','pending','2025-04-13 06:05:00'),(3,9,7,15,10,'Escalated to President for final approval on fee processing delay.','pending','2025-04-17 11:05:00');
/*!40000 ALTER TABLE `decisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `head_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `head_id` (`head_id`),
  CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`head_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'Computer Science',12,'2025-04-01 05:55:00'),(2,'Physics',NULL,'2025-04-01 06:00:00');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `escalations`
--

DROP TABLE IF EXISTS `escalations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `escalations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int(10) unsigned NOT NULL,
  `escalated_to` enum('sims','cost_sharing','campus_registrar','university_registrar','academic_vp','president','academic','department_head','college_dean','administrative_vp') NOT NULL,
  `escalated_to_id` int(10) unsigned DEFAULT NULL,
  `escalated_by_id` int(10) unsigned NOT NULL,
  `college` varchar(100) DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','resolved','escalated') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_details` text DEFAULT NULL,
  `original_handler_id` int(10) unsigned NOT NULL,
  `action_type` enum('assignment','escalation') DEFAULT 'assignment',
  PRIMARY KEY (`id`),
  KEY `complaint_id` (`complaint_id`),
  KEY `escalated_to_id` (`escalated_to_id`),
  KEY `escalated_by_id` (`escalated_by_id`),
  KEY `department_id` (`department_id`),
  KEY `original_handler_id` (`original_handler_id`),
  CONSTRAINT `escalations_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escalations_ibfk_2` FOREIGN KEY (`escalated_to_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escalations_ibfk_3` FOREIGN KEY (`escalated_by_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `escalations_ibfk_4` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `escalations_ibfk_5` FOREIGN KEY (`original_handler_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `escalations`
--

LOCK TABLES `escalations` WRITE;
/*!40000 ALTER TABLE `escalations` DISABLE KEYS */;
INSERT INTO `escalations` VALUES (1,1,'sims',5,3,NULL,NULL,'pending','2025-04-02 07:00:00',NULL,NULL,NULL,3,'assignment'),(2,2,'cost_sharing',6,3,NULL,NULL,'resolved','2025-04-02 07:15:00','2025-04-03 11:00:00','2025-04-03 11:00:00','Refund processed.',3,'assignment'),(3,3,'department_head',14,3,'College of Technology',1,'pending','2025-04-12 19:40:00',NULL,NULL,NULL,3,'assignment'),(4,4,'sims',5,3,NULL,NULL,'escalated','2025-04-13 05:30:00','2025-04-13 06:00:00',NULL,NULL,3,'assignment'),(5,4,'campus_registrar',7,5,NULL,NULL,'pending','2025-04-13 06:00:00',NULL,NULL,NULL,3,'escalation'),(6,5,'sims',5,3,'College of Technology',1,'pending','2025-04-15 07:10:00',NULL,NULL,NULL,3,'assignment'),(7,6,'cost_sharing',6,3,NULL,NULL,'pending','2025-04-16 08:10:00',NULL,NULL,NULL,3,'assignment'),(8,7,'cost_sharing',6,3,NULL,NULL,'','2025-04-17 10:00:00',NULL,NULL,NULL,3,'assignment'),(9,7,'administrative_vp',15,6,NULL,NULL,'pending','2025-04-17 11:00:00',NULL,NULL,NULL,3,'escalation'),(10,8,'cost_sharing',6,3,NULL,NULL,'pending','2025-04-18 06:30:00',NULL,NULL,NULL,3,'assignment');
/*!40000 ALTER TABLE `escalations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feedback`
--

DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback`
--

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
INSERT INTO `feedback` VALUES (1,1,'The registrar complaint system is helpful, but response times could be improved.','2025-04-14 07:00:00'),(2,2,'It would be useful to track complaint status in real-time.','2025-04-14 08:00:00');
/*!40000 ALTER TABLE `feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notices`
--

DROP TABLE IF EXISTS `notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `handler_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `handler_id` (`handler_id`),
  CONSTRAINT `notices_ibfk_1` FOREIGN KEY (`handler_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notices`
--

LOCK TABLES `notices` WRITE;
/*!40000 ALTER TABLE `notices` DISABLE KEYS */;
INSERT INTO `notices` VALUES (1,3,'Registrar System Maintenance','The Complaint Management System will be unavailable on April 20th from 2 AM to 4 AM.','2025-04-15 11:00:00');
/*!40000 ALTER TABLE `notices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `complaint_id` int(10) unsigned DEFAULT NULL,
  `description` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `complaint_id` (`complaint_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,1,1,'Your complaint \"Incorrect Course Registration\" has been assigned to SIMS.',0,'2025-04-02 07:01:00'),(2,5,1,'New complaint assigned: \"Incorrect Course Registration\".',0,'2025-04-02 07:01:00'),(3,2,2,'Your complaint \"Payment Issue\" has been resolved: Refund processed.',0,'2025-04-03 11:01:00'),(4,6,2,'New complaint assigned: \"Payment Issue\".',0,'2025-04-02 07:16:00'),(5,1,3,'Your complaint \"Grading Issue\" has been assigned to a committee.',0,'2025-04-12 19:41:00'),(6,12,3,'You have been assigned to the committee for complaint #3.',0,'2025-04-12 19:41:00'),(7,13,3,'You have been assigned to the committee for complaint #3.',0,'2025-04-12 19:41:00'),(8,14,3,'You have been assigned to the committee for complaint #3.',0,'2025-04-12 19:41:00'),(9,2,4,'Your complaint \"Transcript Error\" has been assigned to SIMS.',0,'2025-04-13 05:31:00'),(10,5,4,'New complaint assigned: \"Transcript Error\".',0,'2025-04-13 05:31:00'),(11,2,4,'Your complaint \"Transcript Error\" has been escalated to Campus Registrar.',0,'2025-04-13 06:01:00'),(12,7,4,'New complaint escalated: \"Transcript Error\".',0,'2025-04-13 06:01:00'),(13,1,NULL,'Your account has been suspended for 2 hours due to the use of inappropriate language: stupid.',0,'2025-04-01 09:30:00'),(14,1,5,'Your complaint \"Lab Access Issue\" needs a committee.',0,'2025-04-15 07:11:00'),(15,5,5,'New complaint assigned: \"Lab Access Issue\".',0,'2025-04-15 07:11:00'),(16,2,6,'Your complaint \"Fee Refund Delay\" has been assigned to Cost Sharing.',0,'2025-04-16 08:11:00'),(17,6,6,'New complaint assigned: \"Fee Refund Delay\".',0,'2025-04-16 08:11:00'),(18,1,7,'Your complaint \"Administrative Delay in Fee Processing\" has been assigned to Cost Sharing.',0,'2025-04-17 10:01:00'),(19,6,7,'New complaint assigned: \"Administrative Delay in Fee Processing\".',0,'2025-04-17 10:01:00'),(20,1,7,'Your complaint \"Administrative Delay in Fee Processing\" has been escalated to Administrative Vice President.',0,'2025-04-17 11:01:00'),(21,15,7,'New complaint escalated: \"Administrative Delay in Fee Processing\".',0,'2025-04-17 11:01:00'),(22,2,8,'Your complaint \"Incorrect Billing Statement\" has been assigned to Cost Sharing.',0,'2025-04-18 06:31:00'),(23,6,8,'New complaint assigned: \"Incorrect Billing Statement\".',0,'2025-04-18 06:31:00');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_password_resets_user_id` (`user_id`),
  KEY `idx_password_resets_expires_at` (`expires_at`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reports`
--

DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `report_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `report_type` enum('weekly','monthly') NOT NULL,
  `academic_complaints` int(11) NOT NULL,
  `administrative_complaints` int(11) NOT NULL,
  `total_pending` int(11) NOT NULL,
  `total_in_progress` int(11) NOT NULL,
  `total_resolved` int(11) NOT NULL,
  `total_rejected` int(11) NOT NULL,
  `sent_to` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sent_to` (`sent_to`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`sent_to`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reports`
--

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
INSERT INTO `reports` VALUES (1,'2025-04-15 06:00:00','weekly',3,1,2,2,1,0,10),(2,'2025-04-18 06:00:00','weekly',4,4,3,3,1,1,10);
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stereotyped_reports`
--

DROP TABLE IF EXISTS `stereotyped_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stereotyped_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `complaint_id` int(10) unsigned NOT NULL,
  `handler_id` int(10) unsigned NOT NULL,
  `recipient_id` int(10) unsigned NOT NULL,
  `report_type` enum('assigned','resolved','escalated','decision_received') NOT NULL,
  `report_content` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `complaint_id` (`complaint_id`),
  KEY `handler_id` (`handler_id`),
  KEY `recipient_id` (`recipient_id`),
  CONSTRAINT `stereotyped_reports_ibfk_1` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stereotyped_reports_ibfk_2` FOREIGN KEY (`handler_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stereotyped_reports_ibfk_3` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stereotyped_reports`
--

LOCK TABLES `stereotyped_reports` WRITE;
/*!40000 ALTER TABLE `stereotyped_reports` DISABLE KEYS */;
INSERT INTO `stereotyped_reports` VALUES (1,2,3,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 2\nTitle: Payment Issue\nDescription: Overcharged for tuition fees.\nCategory: Administrative\nStatus: Resolved\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 2, 2025 09:15\nAdditional Info: Resolved by Cost Sharing','2025-04-03 11:10:00'),(2,4,3,10,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 4\nTitle: Transcript Error\nDescription: My transcript shows an incorrect grade.\nCategory: Academic\nStatus: Escalated\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 13, 2025 08:00\nAdditional Info: Escalated from SIMS to Campus Registrar','2025-04-13 06:10:00'),(3,7,6,15,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 7\nTitle: Administrative Delay in Fee Processing\nDescription: Fee payment processed late, causing registration issues.\nCategory: Administrative\nStatus: In Progress\nSubmitted By: John Doe\nHandler: Tom Brown\nCreated At: Apr 17, 2025 12:00\nAdditional Info: Escalated to Administrative VP','2025-04-17 11:10:00');
/*!40000 ALTER TABLE `stereotyped_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stereotypes`
--

DROP TABLE IF EXISTS `stereotypes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stereotypes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `label` (`label`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stereotypes`
--

LOCK TABLES `stereotypes` WRITE;
/*!40000 ALTER TABLE `stereotypes` DISABLE KEYS */;
INSERT INTO `stereotypes` VALUES (1,'discrimination','Complaints involving discriminatory behavior or practices.','2025-04-01 06:00:00'),(2,'harassment','Complaints involving harassment, bullying, or inappropriate behavior.','2025-04-01 06:05:00'),(3,'bias','Complaints involving unfair bias or favoritism.','2025-04-01 06:10:00'),(4,'accessibility','Complaints related to accessibility issues for students with disabilities.','2025-04-01 06:15:00');
/*!40000 ALTER TABLE `stereotypes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','handler','admin','sims','cost_sharing','campus_registrar','university_registrar','academic_vp','president','academic','department_head','college_dean','administrative_vp') NOT NULL,
  `fname` varchar(50) NOT NULL,
  `lname` varchar(50) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `sex` enum('male','female','other') NOT NULL,
  `email` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `college` varchar(100) DEFAULT NULL,
  `status` enum('active','blocked','suspended') DEFAULT 'active',
  `suspended_until` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'user1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','user','John','Doe','1234567890','male','john.doe@example.com',NULL,NULL,'suspended','2025-05-01 14:30:00','2025-04-01 05:00:00'),(2,'user2','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','user','Jane','Smith','9876543210','female','jane.smith@example.com','Physics','College of Science','active',NULL,'2025-04-01 05:05:00'),(3,'handler1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','handler','Michael','Green','0987654321','male','michael.green@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:10:00'),(4,'admin1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','admin','Admin','User','5555555555','other','admin@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:15:00'),(5,'sims1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','sims','Carol','Jones','7778889999','female','carol.jones@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:20:00'),(6,'costsharing1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','cost_sharing','Tom','Brown','2223334444','male','tom.brown@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:25:00'),(7,'campusreg1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','campus_registrar','Sarah','Johnson','1112223333','female','sarah.johnson@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:30:00'),(8,'univreg1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','university_registrar','David','Lee','4445556666','male','david.lee@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:35:00'),(9,'academicvp1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','academic_vp','Emily','Davis','9990001111','female','emily.davis@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:40:00'),(10,'president1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','president','Robert','Smith','1231231234','male','president@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:45:00'),(11,'academic1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','academic','Eve','Wilson','6667778888','female','eve.wilson@example.com','Physics','College of Science','active',NULL,'2025-04-01 05:50:00'),(12,'depthead1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','department_head','Henry','Clark','3334445555','male','henry.clark@example.com','Computer Science','College of Technology','active',NULL,'2025-04-01 05:55:00'),(13,'collegedean1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','college_dean','Grace','Taylor','8889990000','female','grace.taylor@example.com',NULL,'College of Technology','active',NULL,'2025-04-01 06:00:00'),(14,'depthead2','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','department_head','Samuel','Aschalew','5556667777','male','samuel.aschalew@example.com','Computer Science','College of Technology','active',NULL,'2025-04-01 06:05:00'),(15,'adminvp1','$2y$10$vy2z4VbQMTo9Wde93xGplOFqjU7AXaCj06/Ba3TFBouimnIrnhGV6','administrative_vp','Laura','Adams','7776665555','female','laura.adams@example.com',NULL,NULL,'active',NULL,'2025-04-01 06:10:00');
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

-- Dump completed on 2025-04-26 15:36:31
