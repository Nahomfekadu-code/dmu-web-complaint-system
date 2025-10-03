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
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `word` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `word` (`word`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `abusive_words`
--

LOCK TABLES `abusive_words` WRITE;
/*!40000 ALTER TABLE `abusive_words` DISABLE KEYS */;
INSERT INTO `abusive_words` VALUES (1,'hate','2025-04-14 13:43:02'),(2,'stupid','2025-04-14 13:43:02'),(3,'idiot','2025-04-14 13:43:02'),(4,'bastard','2025-04-16 14:01:53');
/*!40000 ALTER TABLE `abusive_words` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `committees`
--

DROP TABLE IF EXISTS `committees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `committees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member1` int(11) DEFAULT NULL,
  `member2` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member1` (`member1`),
  KEY `member2` (`member2`),
  CONSTRAINT `committees_ibfk_1` FOREIGN KEY (`member1`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `committees_ibfk_2` FOREIGN KEY (`member2`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `committees`
--

LOCK TABLES `committees` WRITE;
/*!40000 ALTER TABLE `committees` DISABLE KEYS */;
INSERT INTO `committees` VALUES (1,5,6,'2024-01-11 07:00:00');
/*!40000 ALTER TABLE `committees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `complaint_logs`
--

DROP TABLE IF EXISTS `complaint_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaint_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `complaint_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaint_logs`
--

LOCK TABLES `complaint_logs` WRITE;
/*!40000 ALTER TABLE `complaint_logs` DISABLE KEYS */;
INSERT INTO `complaint_logs` VALUES (1,1,'Abusive Complaint Attempt','User attempted to submit a complaint with abusive words: stupid','2024-01-10 12:30:00'),(2,1,'User Suspended','User suspended for 2 hours due to abusive content: stupid','2024-01-10 12:30:00'),(3,1,'Complaint Filed','User submitted complaint #7: nahom...','2025-04-14 14:06:21'),(4,1,'Complaint Filed','User submitted complaint #8: someme...','2025-04-14 17:09:31'),(5,1,'Complaint Filed','User submitted complaint #9: foiiogoifg...','2025-04-14 18:08:57'),(6,1,'Complaint Filed','User submitted complaint #10: gjitggigu...','2025-04-14 21:31:30'),(7,1,'Complaint Filed','User submitted complaint #11: jfjfffiewpof...','2025-04-14 22:17:09'),(8,1,'Complaint Filed','User submitted complaint #12: abebe...','2025-04-14 22:27:52'),(9,1,'Complaint Filed','User submitted complaint #13: dddd...','2025-04-14 22:52:15'),(10,1,'Complaint Filed','User submitted complaint #14: exit exit exit...','2025-04-15 09:53:38'),(11,1,'Complaint Filed','User submitted complaint #15: something...','2025-04-15 11:32:13'),(12,17,'Complaint Filed','User submitted complaint #16: wifi problem...','2025-04-15 17:03:37'),(13,18,'Complaint Filed','User submitted complaint #17: some...','2025-04-15 17:09:11'),(14,17,'Complaint Filed','User submitted complaint #18: grading...','2025-04-15 17:44:50'),(15,1,'Complaint Filed','User submitted complaint #19: some issue on the grade...','2025-04-16 01:08:07'),(16,1,'Complaint Filed','User submitted complaint #20: abebe...','2025-04-16 01:34:49'),(17,1,'Complaint Filed','User submitted complaint #21: nahom...','2025-04-16 07:15:39'),(18,1,'Complaint Filed','User submitted complaint #22: dormitory Equipment faliure...','2025-04-16 07:23:15');
/*!40000 ALTER TABLE `complaint_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `complaint_stereotypes`
--

DROP TABLE IF EXISTS `complaint_stereotypes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaint_stereotypes` (
  `complaint_id` int(11) NOT NULL,
  `stereotype_id` int(11) NOT NULL,
  `tagged_by` int(11) NOT NULL,
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
INSERT INTO `complaint_stereotypes` VALUES (2,3,3,'2024-01-12 06:20:00'),(3,4,3,'2024-01-12 06:35:00');
/*!40000 ALTER TABLE `complaint_stereotypes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `complaints`
--

DROP TABLE IF EXISTS `complaints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaints` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `handler_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `category` enum('academic','administrative') NOT NULL,
  `status` enum('pending','validated','in_progress','resolved','rejected','pending_more_info') DEFAULT 'pending',
  `visibility` enum('standard','anonymous') DEFAULT 'standard',
  `needs_video_chat` tinyint(1) DEFAULT 0,
  `committee_id` int(11) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaints`
--

LOCK TABLES `complaints` WRITE;
/*!40000 ALTER TABLE `complaints` DISABLE KEYS */;
INSERT INTO `complaints` VALUES (1,1,3,'WiFi Unreliable in Library Wing B','The WiFi connection in the second floor, Wing B of the main library frequently drops, making online research impossible.','administrative','resolved','standard',0,NULL,3,'2024-01-12 06:00:00','2024-01-12 12:00:00','2024-01-12 12:00:00','WiFi issue resolved by IT team.',NULL,'wifi_speedtest.png'),(2,2,3,'Incorrect Grade Calculation for Midterm','The calculation for my Physics 101 midterm grade seems incorrect. The points summation doesn\'t match the final percentage shown.','academic','resolved','standard',0,NULL,2,'2024-01-12 06:15:00','2025-04-15 23:06:46','2025-04-15 23:06:46','fjfjfjf',NULL,NULL),(3,1,3,'Request for Accessible Seating','Classroom 301 in the Tech Building lacks sufficient accessible seating options near the front.','administrative','resolved','anonymous',0,NULL,1,'2024-01-12 06:30:00','2025-04-14 19:44:06',NULL,NULL,NULL,NULL),(4,2,3,'Lab Equipment Calibration Issue','The spectrophotometer in Chem Lab 2 needs recalibration. Readings are inconsistent.','academic','resolved','standard',0,NULL,2,'2024-01-12 06:45:00','2025-04-14 11:05:11','2025-04-14 11:05:11','jjfjf',NULL,NULL),(5,1,3,'Textbook Availability Concern','The required textbook for History 205 is out of stock at the campus bookstore and online options are delayed.','academic','resolved','standard',0,NULL,3,'2024-01-10 08:00:00','2024-01-11 13:00:00','2024-01-11 12:30:00','Additional copies were ordered and expedited. Digital access code provided as interim solution.',NULL,'textbook_screenshot.jpg'),(6,1,3,'grading c','Issue with grading process','academic','resolved','standard',0,NULL,1,'2025-04-12 19:30:00','2025-04-12 19:45:00','2025-04-12 19:45:00','Issue resolved by Department Head.',NULL,NULL),(7,1,3,'nahom','nahon nahom nahom nahom nahom nahom nahom and nahom','academic','in_progress','standard',0,NULL,NULL,'2025-04-14 11:06:21','2025-04-14 11:15:52',NULL,NULL,NULL,NULL),(8,1,3,'someme','sjfjgjhrigi','academic','resolved','standard',0,NULL,NULL,'2025-04-14 14:09:31','2025-04-14 14:11:01','2025-04-14 14:11:01','ufrueru',NULL,NULL),(9,1,3,'foiiogoifg','kfkgifvnv','academic','resolved','standard',0,NULL,NULL,'2025-04-14 15:08:57','2025-04-14 19:27:06','2025-04-14 19:27:06','jjjdcfcfjne',NULL,NULL),(10,1,3,'gjitggigu','riririfngerjgiehehjigr','academic','in_progress','standard',0,NULL,NULL,'2025-04-14 18:31:30','2025-04-14 18:33:16',NULL,NULL,NULL,NULL),(11,1,3,'jfjfffiewpof','sepodoefeopf','academic','resolved','standard',0,NULL,NULL,'2025-04-14 19:17:09','2025-04-14 19:21:25','2025-04-14 19:21:25','kjojopkpp',NULL,NULL),(12,1,3,'abebe','abebebebe','academic','resolved','standard',0,NULL,NULL,'2025-04-14 19:27:52','2025-04-15 06:51:54','2025-04-15 06:51:54','hfhhghjdkghkf',NULL,NULL),(13,1,3,'dddd','ppppppppppppppppppppppppppp','academic','in_progress','standard',0,NULL,NULL,'2025-04-14 19:52:15','2025-04-14 19:53:29',NULL,NULL,NULL,NULL),(14,1,3,'exit exit exit','exit exit exit exit exit exit exit exit exit exit exit exit exit exit','academic','resolved','standard',0,NULL,NULL,'2025-04-15 06:53:38','2025-04-15 07:20:19','2025-04-15 07:20:19','hfkgkfkfjhgfkjg',NULL,NULL),(15,1,3,'something','something something something new','academic','resolved','standard',0,NULL,NULL,'2025-04-15 08:32:13','2025-04-15 14:40:24','2025-04-15 14:40:24','dsdwhsgwjsf',NULL,NULL),(16,17,3,'wifi problem','i have wifi issue','administrative','pending','anonymous',1,NULL,NULL,'2025-04-15 14:03:37',NULL,NULL,NULL,NULL,'evidence_67fe6739a7bdc1.51404946.jpg'),(17,18,3,'some','somecase somefkrj','academic','resolved','anonymous',0,NULL,NULL,'2025-04-15 14:09:11','2025-04-15 14:23:37','2025-04-15 14:23:37','jifjgeoieo[',NULL,'evidence_67fe68a91e6174.82633642.jpg'),(18,17,3,'grading','ihave f grade','academic','in_progress','anonymous',0,NULL,NULL,'2025-04-15 14:44:50','2025-04-15 14:52:44',NULL,NULL,NULL,'evidence_67fe70e278c668.83644416.jpg'),(19,1,3,'some issue on the grade','detail is teacher','academic','in_progress','standard',0,NULL,NULL,'2025-04-15 22:08:07','2025-04-15 22:09:14',NULL,NULL,NULL,NULL),(20,1,3,'abebe','abebebebebebebebe','academic','in_progress','standard',0,NULL,NULL,'2025-04-15 22:34:49','2025-04-15 22:35:48',NULL,NULL,NULL,NULL),(21,1,3,'nahom','Nahom is','academic','pending','anonymous',0,NULL,NULL,'2025-04-16 04:15:39',NULL,NULL,NULL,NULL,NULL),(22,1,3,'dormitory Equipment faliure','we are live on the block 101. our Wi-Fi router is fail','academic','pending','standard',0,NULL,NULL,'2025-04-16 04:23:15',NULL,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `complaints` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `decisions`
--

DROP TABLE IF EXISTS `decisions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `decisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `escalation_id` int(11) DEFAULT NULL,
  `complaint_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `decisions`
--

LOCK TABLES `decisions` WRITE;
/*!40000 ALTER TABLE `decisions` DISABLE KEYS */;
INSERT INTO `decisions` VALUES (1,1,1,9,3,'WiFi issue resolved by IT team after rebooting the router.','final','2024-01-12 12:05:00'),(2,7,5,9,3,'Contacted bookstore manager. More copies arriving tomorrow. Provided student with temporary digital access code.','final','2024-01-11 12:05:00'),(3,2,2,5,3,'Unable to resolve at department level due to complexity of grade calculation. Escalating to College Dean.','pending','2024-01-13 08:00:00'),(4,4,3,6,3,'Requires higher-level approval for budget allocation to add accessible seating. Escalating to Academic VP.','pending','2024-01-14 11:20:00'),(5,8,6,14,3,'Issue resolved by Department Head.','final','2025-04-12 19:45:00'),(6,6,4,5,3,'jjfjf','final','2025-04-14 11:05:11'),(7,NULL,7,5,6,'it above of my responsiblty ','pending','2025-04-14 11:17:17'),(8,10,8,5,3,'ufrueru','final','2025-04-14 14:11:01'),(9,13,11,5,3,'kjojopkpp','final','2025-04-14 19:21:25'),(10,11,9,6,3,'jjjdcfcfjne','final','2025-04-14 19:27:06'),(11,22,17,19,3,'jifjgeoieo[','final','2025-04-15 14:23:37'),(12,NULL,18,19,6,'this is out of my load','pending','2025-04-15 15:00:39'),(13,NULL,20,5,6,'kkfkfkf','pending','2025-04-15 22:36:29'),(14,26,2,13,2,'fjfjfjf','final','2025-04-15 23:06:46');
/*!40000 ALTER TABLE `decisions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `head_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `head_id` (`head_id`),
  CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`head_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (1,'Computer Science',5,'2024-01-10 05:25:00'),(2,'Physics',NULL,'2024-01-10 05:30:00'),(3,'Library Services',9,'2024-01-10 05:35:00');
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `escalations`
--

DROP TABLE IF EXISTS `escalations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `escalations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` int(11) NOT NULL,
  `escalated_to` enum('handler','department_head','college_dean','sims','cost_sharing_customer_service','libraries_service_directorate','academic_vp','president') NOT NULL,
  `escalated_to_id` int(11) NOT NULL,
  `escalated_by_id` int(11) NOT NULL,
  `college` varchar(100) DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `status` enum('pending','resolved','escalated') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolution_details` text DEFAULT NULL,
  `original_handler_id` int(11) NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `escalations`
--

LOCK TABLES `escalations` WRITE;
/*!40000 ALTER TABLE `escalations` DISABLE KEYS */;
INSERT INTO `escalations` VALUES (1,1,'libraries_service_directorate',9,3,NULL,3,'resolved','2024-01-12 06:10:00','2024-01-12 12:00:00','2024-01-12 12:00:00','WiFi issue resolved by IT team.',3,'assignment'),(2,2,'department_head',5,3,'College of Science',2,'escalated','2024-01-12 06:20:00','2024-01-12 06:20:00',NULL,NULL,3,'assignment'),(3,2,'college_dean',6,5,'College of Science',2,'resolved','2024-01-13 08:00:00','2025-04-15 15:08:58','2025-04-15 15:08:58','Escalated to Academic Vice President by Dean. Reason: fgfjg,gjh',3,'escalation'),(4,3,'college_dean',6,3,'College of Technology',1,'escalated','2024-01-12 06:40:00','2024-01-12 06:40:00',NULL,NULL,3,'assignment'),(5,3,'academic_vp',10,6,'College of Technology',1,'resolved','2024-01-14 11:20:00','2025-04-14 19:44:06',NULL,'jjfnjvnjnjvcnierf0-',3,'escalation'),(6,4,'department_head',5,3,'College of Science',2,'resolved','2024-01-12 06:50:00','2025-04-14 11:05:11','2025-04-14 11:05:11','jjfjf',3,'assignment'),(7,5,'libraries_service_directorate',9,3,NULL,3,'resolved','2024-01-11 06:00:00','2024-01-11 12:00:00','2024-01-11 12:00:00','Library Director contacted bookstore and publisher.',3,'assignment'),(8,6,'department_head',14,3,'College of Technology',1,'resolved','2025-04-12 19:40:00','2025-04-12 19:45:00','2025-04-12 19:45:00','Issue resolved by Department Head.',3,'assignment'),(9,7,'college_dean',6,3,NULL,NULL,'escalated','2025-04-14 11:15:52','2025-04-14 11:17:17',NULL,NULL,3,'assignment'),(10,8,'department_head',5,3,NULL,NULL,'resolved','2025-04-14 14:10:23','2025-04-14 14:11:01','2025-04-14 14:11:01','ufrueru',3,'assignment'),(11,9,'college_dean',6,3,NULL,NULL,'resolved','2025-04-14 15:10:03','2025-04-14 19:27:06','2025-04-14 19:27:06','jjjdcfcfjne',3,'assignment'),(12,10,'college_dean',15,3,NULL,NULL,'pending','2025-04-14 18:33:16',NULL,NULL,NULL,3,'assignment'),(13,11,'department_head',5,3,NULL,NULL,'resolved','2025-04-14 19:18:18','2025-04-14 19:21:25','2025-04-14 19:21:25','kjojopkpp',3,'assignment'),(14,12,'college_dean',15,3,NULL,NULL,'resolved','2025-04-14 19:28:48','2025-04-14 19:34:47',NULL,'jjjfdjcndjnfejiefoejvv for',3,'assignment'),(15,12,'academic_vp',10,15,NULL,NULL,'resolved','2025-04-14 19:34:47','2025-04-14 19:51:22',NULL,'jhuhuhuiiuiuuoiiiioi',3,'escalation'),(16,12,'president',13,10,NULL,NULL,'resolved','2025-04-14 19:51:22','2025-04-15 06:51:54','2025-04-15 06:51:54','hfhhghjdkghkf',3,'escalation'),(17,13,'academic_vp',10,3,NULL,NULL,'pending','2025-04-14 19:53:29',NULL,NULL,NULL,3,'assignment'),(18,14,'college_dean',15,3,NULL,NULL,'resolved','2025-04-15 06:54:26','2025-04-15 07:08:50','2025-04-15 07:08:50','Escalated to Academic Vice President by Dean. Reason: jfjfjfjjfj',3,'assignment'),(19,14,'academic_vp',10,15,NULL,NULL,'resolved','2025-04-15 07:08:50','2025-04-15 07:10:49',NULL,'nnnnnnnnnnnnnnnnnnnnn',3,'escalation'),(20,14,'president',13,10,NULL,NULL,'resolved','2025-04-15 07:10:49','2025-04-15 07:20:19','2025-04-15 07:20:19','hfkgkfkfjhgfkjg',3,'escalation'),(21,15,'president',13,3,NULL,NULL,'resolved','2025-04-15 08:33:06','2025-04-15 14:40:24','2025-04-15 14:40:24','dsdwhsgwjsf',3,'assignment'),(22,17,'department_head',19,3,NULL,NULL,'resolved','2025-04-15 14:18:18','2025-04-15 14:23:37','2025-04-15 14:23:37','jifjgeoieo[',3,'assignment'),(23,18,'college_dean',6,3,NULL,NULL,'escalated','2025-04-15 14:52:44','2025-04-15 15:00:39',NULL,NULL,3,'assignment'),(24,2,'academic_vp',10,6,NULL,NULL,'resolved','2025-04-15 15:08:58','2025-04-15 22:31:24',NULL,'itititiieie',3,'escalation'),(25,19,'academic_vp',10,3,NULL,NULL,'pending','2025-04-15 22:09:14',NULL,NULL,NULL,3,'assignment'),(26,2,'president',13,10,NULL,NULL,'resolved','2025-04-15 22:31:24','2025-04-15 23:06:46','2025-04-15 23:06:46','fjfjfjf',3,'escalation'),(27,20,'college_dean',6,3,NULL,NULL,'escalated','2025-04-15 22:35:48','2025-04-15 22:36:29',NULL,NULL,3,'assignment');
/*!40000 ALTER TABLE `escalations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feedback`
--

DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback`
--

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
INSERT INTO `feedback` VALUES (1,1,'The system is easy to use, but response times could be faster.','2024-01-14 07:00:00'),(2,2,'It would be helpful to see who my complaint is currently assigned to.','2024-01-14 08:00:00'),(3,17,'i was satisfied by this system','2025-04-15 14:47:30');
/*!40000 ALTER TABLE `feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notices`
--

DROP TABLE IF EXISTS `notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `handler_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `handler_id` (`handler_id`),
  CONSTRAINT `notices_ibfk_1` FOREIGN KEY (`handler_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notices`
--

LOCK TABLES `notices` WRITE;
/*!40000 ALTER TABLE `notices` DISABLE KEYS */;
INSERT INTO `notices` VALUES (1,3,'System Maintenance Scheduled','The Complaint Management System will be unavailable on Jan 20th from 2 AM to 4 AM for scheduled maintenance.','2024-01-15 11:00:00'),(2,3,'new','eeeeeee','2025-04-15 14:27:34');
/*!40000 ALTER TABLE `notices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `complaint_id` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `complaint_id` (`complaint_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`complaint_id`) REFERENCES `complaints` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=159 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,1,1,'Your complaint \"WiFi Unreliable in Library Wing B\" has been assigned to Library Services.',0,'2024-01-12 06:11:00'),(2,9,1,'New complaint assigned: \"WiFi Unreliable in Library Wing B\" from user John Doe.',0,'2024-01-12 06:12:00'),(3,1,1,'Your complaint \"WiFi Unreliable in Library Wing B\" has been resolved: WiFi issue resolved by IT team.',0,'2024-01-12 12:06:00'),(4,3,1,'You received a final decision regarding Complaint #1 from Libraries Service Directorate.',0,'2024-01-12 12:07:00'),(5,2,2,'Your complaint \"Incorrect Grade Calculation for Midterm\" has been assigned to Department Head.',0,'2024-01-12 06:21:00'),(6,5,2,'New complaint assigned: \"Incorrect Grade Calculation for Midterm\" from user Jane Smith.',1,'2024-01-12 06:22:00'),(7,2,2,'Your complaint \"Incorrect Grade Calculation for Midterm\" has been escalated to College Dean.',0,'2024-01-13 08:01:00'),(8,6,2,'A complaint (ID #2) has been escalated to you for review.',1,'2024-01-13 08:02:00'),(9,3,2,'Complaint #2 has been escalated from Department Head to College Dean.',0,'2024-01-13 08:03:00'),(10,1,3,'Your complaint \"Request for Accessible Seating\" has been assigned to College Dean.',0,'2024-01-12 06:41:00'),(11,6,3,'New complaint assigned: \"Request for Accessible Seating\" from user John Doe.',1,'2024-01-12 06:42:00'),(12,1,3,'Your complaint \"Request for Accessible Seating\" has been escalated to Academic VP.',0,'2024-01-14 11:21:00'),(13,10,3,'A complaint (ID #3) has been escalated to you for review.',1,'2024-01-14 11:22:00'),(14,3,3,'Complaint #3 has been escalated from College Dean to Academic VP.',0,'2024-01-14 11:23:00'),(15,2,4,'Your complaint \"Lab Equipment Calibration Issue\" has been assigned to Department Head.',0,'2024-01-12 06:51:00'),(16,5,4,'New complaint assigned: \"Lab Equipment Calibration Issue\" from user Jane Smith.',1,'2024-01-12 06:52:00'),(17,1,5,'Your complaint \"Textbook Availability Concern\" has been assigned to Library Services.',0,'2024-01-11 06:01:00'),(18,9,5,'New complaint assigned: \"Textbook Availability Concern\" from user John Doe.',0,'2024-01-11 06:02:00'),(19,1,5,'Your complaint \"Textbook Availability Concern\" has been resolved: Additional copies were ordered and expedited.',1,'2024-01-11 12:31:00'),(20,3,5,'You received a final decision regarding Complaint #5 from Libraries Service Directorate.',0,'2024-01-11 12:06:00'),(21,1,6,'Your complaint \"grading c\" has been assigned to Department Head.',0,'2025-04-12 19:41:00'),(22,14,6,'New complaint assigned: \"grading c\" from user John Doe.',0,'2025-04-12 19:42:00'),(23,3,6,'You received a final decision regarding Complaint #6 from Department Head.',0,'2025-04-12 19:46:00'),(24,1,NULL,'Your account has been suspended for 2 hours due to the use of inappropriate language: stupid.',0,'2024-01-10 09:30:00'),(25,3,4,'A final decision has been made on Complaint #4 by the Department Head.',0,'2025-04-14 11:05:11'),(26,2,4,'Your complaint has been resolved: jjfjf',0,'2025-04-14 11:05:11'),(27,13,4,'A new resolved report for Complaint #4 has been submitted by Sarah Johnson.',1,'2025-04-14 11:05:11'),(28,1,7,'Your complaint \"nahom\" has been submitted and is pending review.',0,'2025-04-14 11:06:21'),(29,3,7,'A new complaint (#7) has been assigned to you.',0,'2025-04-14 11:06:21'),(30,1,7,'Your Complaint #7 has been validated.',0,'2025-04-14 11:10:24'),(31,5,7,'A complaint (ID #7) has been assigned to you for review.',1,'2025-04-14 11:15:52'),(32,1,7,'Your complaint has been assigned to a higher authority.',0,'2025-04-14 11:15:52'),(33,13,7,'A new assigned report for Complaint #7 has been submitted by Michael Green.',0,'2025-04-14 11:15:52'),(34,6,7,'Complaint #7 has been escalated to you by the Department Head.',1,'2025-04-14 11:17:17'),(35,1,8,'Your complaint \"someme\" has been submitted and is pending review.',0,'2025-04-14 14:09:31'),(36,3,8,'A new complaint (#8) has been assigned to you.',0,'2025-04-14 14:09:31'),(37,1,8,'Your Complaint #8 has been validated.',0,'2025-04-14 14:10:11'),(38,5,8,'A complaint (ID #8) has been assigned to you for review.',1,'2025-04-14 14:10:23'),(39,1,8,'Your complaint has been assigned to a higher authority.',0,'2025-04-14 14:10:23'),(40,13,8,'A new assigned report for Complaint #8 has been submitted by Michael Green.',0,'2025-04-14 14:10:23'),(41,3,8,'A final decision has been made on Complaint #8 by the Department Head.',0,'2025-04-14 14:11:01'),(42,1,8,'Your complaint has been resolved: ufrueru',0,'2025-04-14 14:11:01'),(43,13,8,'A new resolved report for Complaint #8 has been submitted by Sarah Johnson.',0,'2025-04-14 14:11:01'),(44,1,9,'Your complaint \"foiiogoifg\" has been submitted and is pending review.',0,'2025-04-14 15:08:57'),(45,3,9,'A new complaint (#9) has been assigned to you.',0,'2025-04-14 15:08:57'),(46,1,9,'Your Complaint #9 has been validated.',0,'2025-04-14 15:09:45'),(47,6,9,'A complaint (ID #9) has been assigned to you for review.',1,'2025-04-14 15:10:03'),(48,1,9,'Your complaint has been assigned to a higher authority.',0,'2025-04-14 15:10:03'),(49,13,9,'A new assigned report for Complaint #9 has been submitted by Michael Green.',0,'2025-04-14 15:10:03'),(50,1,10,'Your complaint \"gjitggigu\" has been submitted and is pending review.',0,'2025-04-14 18:31:30'),(51,3,10,'A new complaint (#10) has been assigned to you.',0,'2025-04-14 18:31:30'),(52,1,10,'Your Complaint #10 has been validated.',0,'2025-04-14 18:32:45'),(53,15,10,'A complaint (ID #10) has been assigned to you for review.',1,'2025-04-14 18:33:16'),(54,1,10,'Your complaint has been assigned to a higher authority.',0,'2025-04-14 18:33:16'),(55,13,10,'A new assigned report for Complaint #10 has been submitted by Michael Green.',0,'2025-04-14 18:33:16'),(56,1,11,'Your complaint \"jfjfffiewpof\" has been submitted and is pending review.',0,'2025-04-14 19:17:09'),(57,3,11,'A new complaint (#11) has been assigned to you.',0,'2025-04-14 19:17:09'),(58,1,11,'Your Complaint #11 has been validated.',0,'2025-04-14 19:18:08'),(59,5,11,'A complaint (ID #11) has been assigned to you for review.',1,'2025-04-14 19:18:18'),(60,1,11,'Your complaint has been assigned to a higher authority.',0,'2025-04-14 19:18:18'),(61,13,11,'A new assigned report for Complaint #11 has been submitted by Michael Green.',0,'2025-04-14 19:18:18'),(62,3,11,'A final decision has been made on Complaint #11 by the Department Head.',0,'2025-04-14 19:21:25'),(63,1,11,'Your complaint has been resolved: kjojopkpp',0,'2025-04-14 19:21:25'),(64,13,11,'A new resolved report for Complaint #11 has been submitted by Sarah Johnson.',0,'2025-04-14 19:21:25'),(65,3,9,'A final decision has been made on Complaint #9 by the College Dean.',0,'2025-04-14 19:27:06'),(66,1,9,'Your complaint has been resolved: jjjdcfcfjne',0,'2025-04-14 19:27:06'),(67,13,9,'A new resolved report for Complaint #9 has been submitted by David Lee.',0,'2025-04-14 19:27:06'),(68,1,12,'Your complaint \"abebe\" has been submitted and is pending review.',0,'2025-04-14 19:27:52'),(69,3,12,'A new complaint (#12) has been assigned to you.',0,'2025-04-14 19:27:52'),(70,1,12,'Your Complaint #12 has been validated.',0,'2025-04-14 19:28:27'),(71,15,12,'A complaint (ID #12) has been assigned to you for review.',1,'2025-04-14 19:28:48'),(72,1,12,'Your complaint has been assigned to a higher authority.',0,'2025-04-14 19:28:48'),(73,13,12,'A new assigned report for Complaint #12 has been submitted by Michael Green.',0,'2025-04-14 19:28:48'),(74,10,12,'A complaint (ID #12) has been escalated to you for review: jjjfdjcndjnfejiefoejvv for',1,'2025-04-14 19:34:47'),(75,3,12,'Complaint #12 has been escalated to Academic Vice President by the College Dean: jjjfdjcndjnfejiefoejvv for',0,'2025-04-14 19:34:47'),(76,1,12,'Your complaint has been escalated to Academic Vice President: jjjfdjcndjnfejiefoejvv for',0,'2025-04-14 19:34:47'),(77,13,12,'A new escalated report for Complaint #12 has been submitted by seid yimam.',0,'2025-04-14 19:34:47'),(78,1,3,'Your complaint #3 has been resolved by the Academic Vice President: jjfnjvnjnjvcnierf0-',0,'2025-04-14 19:44:06'),(79,3,3,'Complaint #3, which you handled, has been resolved by the Academic Vice President: jjfnjvnjnjvcnierf0-',0,'2025-04-14 19:44:06'),(80,6,3,'Complaint #3, which you escalated, has been resolved by the Academic Vice President: jjfnjvnjnjvcnierf0-',1,'2025-04-14 19:44:06'),(81,13,3,'A new resolved report for Complaint #3 has been submitted by Emily Davis.',0,'2025-04-14 19:44:06'),(82,13,12,'A complaint (ID #12) has been escalated to you for review: jhuhuhuiiuiuuoiiiioi',0,'2025-04-14 19:51:22'),(83,3,12,'Complaint #12 has been escalated to the President by the Academic Vice President: jhuhuhuiiuiuuoiiiioi',0,'2025-04-14 19:51:22'),(84,1,12,'Your complaint has been escalated to the President: jhuhuhuiiuiuuoiiiioi',0,'2025-04-14 19:51:22'),(85,13,12,'A new escalated report for Complaint #12 has been submitted by Emily Davis.',0,'2025-04-14 19:51:22'),(86,1,13,'Your complaint \"dddd\" has been submitted and is pending review.',0,'2025-04-14 19:52:16'),(87,3,13,'A new complaint (#13) has been assigned to you.',0,'2025-04-14 19:52:16'),(88,1,13,'Your Complaint #13 has been validated.',0,'2025-04-14 19:53:20'),(89,10,13,'A complaint (ID #13) has been assigned to you for review.',1,'2025-04-14 19:53:29'),(90,1,13,'Your complaint has been assigned to a higher authority.',0,'2025-04-14 19:53:29'),(91,13,13,'A new assigned report for Complaint #13 has been submitted by Michael Green.',0,'2025-04-14 19:53:29'),(92,1,14,'Your complaint \"exit exit exit\" has been submitted and is pending review.',0,'2025-04-15 06:53:38'),(93,3,14,'A new complaint (#14) has been assigned to you.',0,'2025-04-15 06:53:38'),(94,1,14,'Your Complaint #14 has been validated.',0,'2025-04-15 06:54:10'),(95,15,14,'A complaint (ID #14) has been assigned to you for review.',0,'2025-04-15 06:54:26'),(96,1,14,'Your complaint has been assigned to a higher authority.',0,'2025-04-15 06:54:26'),(97,13,14,'A new assigned report for Complaint #14 has been submitted by Michael Green.',0,'2025-04-15 06:54:26'),(98,10,14,'Complaint #14 has been escalated to you by the College Dean for review. Reason: jfjfjfjjfj',0,'2025-04-15 07:08:50'),(99,1,14,'Your Complaint (#14: exit exit exit) has been escalated to the Academic Vice President by the College Dean.',0,'2025-04-15 07:08:50'),(100,3,14,'Complaint #14, which you escalated, has been further escalated by the College Dean to Academic Vice President. Reason: jfjfjfjjfj',0,'2025-04-15 07:08:50'),(101,13,14,'A new \'escalated\' report for Complaint #14 has been submitted by College Dean seid yimam.',0,'2025-04-15 07:08:50'),(102,13,14,'A complaint (ID #14) has been escalated to you for review: nnnnnnnnnnnnnnnnnnnnn',0,'2025-04-15 07:10:49'),(103,3,14,'Complaint #14 has been escalated to the President by the Academic Vice President: nnnnnnnnnnnnnnnnnnnnn',0,'2025-04-15 07:10:49'),(104,1,14,'Your complaint has been escalated to the President: nnnnnnnnnnnnnnnnnnnnn',0,'2025-04-15 07:10:49'),(105,13,14,'A new escalated report for Complaint #14 has been submitted by Emily Davis.',0,'2025-04-15 07:10:49'),(106,1,15,'Your complaint \"something\" has been submitted and is pending review.',0,'2025-04-15 08:32:13'),(107,3,15,'A new complaint (#15) has been assigned to you.',0,'2025-04-15 08:32:13'),(108,1,15,'Your Complaint #15 has been validated.',0,'2025-04-15 08:32:43'),(109,13,15,'A complaint (ID #15) has been assigned to you for review.',0,'2025-04-15 08:33:06'),(110,1,15,'Your complaint has been assigned to a higher authority.',0,'2025-04-15 08:33:06'),(111,13,15,'A new assigned report for Complaint #15 has been submitted by Michael Green.',0,'2025-04-15 08:33:06'),(112,17,16,'Your complaint \"wifi problem\" has been submitted and is pending review.',0,'2025-04-15 14:03:37'),(113,3,16,'A new complaint (#16) has been assigned to you.',0,'2025-04-15 14:03:37'),(114,18,17,'Your complaint \"some\" has been submitted and is pending review.',0,'2025-04-15 14:09:11'),(115,3,17,'A new complaint (#17) has been assigned to you.',0,'2025-04-15 14:09:11'),(116,18,17,'Your Complaint #17 has been validated.',0,'2025-04-15 14:10:52'),(117,19,17,'A complaint (ID #17) has been assigned to you for review.',1,'2025-04-15 14:18:18'),(118,18,17,'Your complaint has been assigned to a higher authority.',0,'2025-04-15 14:18:18'),(119,13,17,'A new assigned report for Complaint #17 has been submitted by Michael Green.',0,'2025-04-15 14:18:18'),(120,3,17,'A final decision has been made on Complaint #17 by the Department Head.',0,'2025-04-15 14:23:37'),(121,18,17,'Your complaint has been resolved: jifjgeoieo[',0,'2025-04-15 14:23:37'),(122,13,17,'A new resolved report for Complaint #17 has been submitted by temesgen adne.',0,'2025-04-15 14:23:37'),(123,17,18,'Your complaint \"grading\" has been submitted and is pending review.',0,'2025-04-15 14:44:50'),(124,3,18,'A new complaint (#18) has been assigned to you.',0,'2025-04-15 14:44:50'),(125,17,18,'Your Complaint #18 has been validated.',0,'2025-04-15 14:51:22'),(126,19,18,'A complaint (ID #18) has been assigned to you for review.',1,'2025-04-15 14:52:44'),(127,17,18,'Your complaint has been assigned to a higher authority.',0,'2025-04-15 14:52:44'),(128,13,18,'A new assigned report for Complaint #18 has been submitted by Michael Green.',0,'2025-04-15 14:52:44'),(129,6,18,'Complaint #18 has been escalated to you by the Department Head.',1,'2025-04-15 15:00:39'),(130,10,2,'Complaint #2 has been escalated to you by the College Dean for review. Reason: fgfjg,gjh',0,'2025-04-15 15:08:58'),(131,2,2,'Your Complaint (#2: Incorrect Grade Calculation for Midterm) has been escalated to the Academic Vice President by the College Dean.',0,'2025-04-15 15:08:58'),(132,5,2,'Complaint #2, which you escalated, has been further escalated by the College Dean to Academic Vice President. Reason: fgfjg,gjh',0,'2025-04-15 15:08:58'),(133,13,2,'A new \'escalated\' report for Complaint #2 has been submitted by College Dean David Lee.',0,'2025-04-15 15:08:58'),(134,1,19,'Your complaint \"some issue on the grade\" has been submitted and is pending review.',0,'2025-04-15 22:08:07'),(135,3,19,'A new complaint (#19) has been assigned to you.',0,'2025-04-15 22:08:07'),(136,1,19,'Your Complaint #19 has been validated.',0,'2025-04-15 22:09:02'),(137,10,19,'A complaint (ID #19) has been assigned to you for review.',0,'2025-04-15 22:09:14'),(138,1,19,'Your complaint has been assigned to a higher authority.',0,'2025-04-15 22:09:14'),(139,13,19,'A new assigned report for Complaint #19 has been submitted by Michael Green.',0,'2025-04-15 22:09:14'),(140,13,2,'A complaint (ID #2) has been escalated to you for review: itititiieie',0,'2025-04-15 22:31:24'),(141,3,2,'Complaint #2 has been escalated to the President by the Academic Vice President: itititiieie',0,'2025-04-15 22:31:24'),(142,2,2,'Your complaint has been escalated to the President: itititiieie',0,'2025-04-15 22:31:24'),(143,13,2,'A new escalated report for Complaint #2 has been submitted by Emily Davis.',0,'2025-04-15 22:31:24'),(144,1,20,'Your complaint \"abebe\" has been submitted and is pending review.',0,'2025-04-15 22:34:49'),(145,3,20,'A new complaint (#20) has been assigned to you.',0,'2025-04-15 22:34:49'),(146,1,20,'Your Complaint #20 has been validated.',0,'2025-04-15 22:35:23'),(147,5,20,'A complaint (ID #20) has been assigned to you for review.',0,'2025-04-15 22:35:48'),(148,1,20,'Your complaint has been assigned to a higher authority.',0,'2025-04-15 22:35:48'),(149,13,20,'A new assigned report for Complaint #20 has been submitted by Michael Green.',0,'2025-04-15 22:35:48'),(150,6,20,'Complaint #20 has been escalated to you by the Department Head.',0,'2025-04-15 22:36:29'),(151,2,2,'Your Complaint (#2: Incorrect Grade Calculation for Midterm) has been resolved by the President. Decision: fjfjfjf',0,'2025-04-15 23:06:46'),(152,3,2,'Complaint #2, which you handled/escalated, has been resolved by the President. Decision: fjfjfjf',0,'2025-04-15 23:06:46'),(153,10,2,'Complaint #2, which you escalated to the President, has been resolved. Decision: fjfjfjf',0,'2025-04-15 23:06:46'),(154,6,2,'A new \'resolved\' report for Complaint #2 has been submitted by President Robert Smith.',0,'2025-04-15 23:06:46'),(155,1,21,'Your complaint \"nahom\" has been submitted and is pending review.',0,'2025-04-16 04:15:39'),(156,3,21,'A new complaint (#21) has been assigned to you.',0,'2025-04-16 04:15:39'),(157,1,22,'Your complaint \"dormitory Equipment faliure\" has been submitted and is pending review.',0,'2025-04-16 04:23:15'),(158,3,22,'A new complaint (#22) has been assigned to you.',0,'2025-04-16 04:23:15');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
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
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `report_type` enum('weekly','monthly') NOT NULL,
  `academic_complaints` int(11) NOT NULL,
  `administrative_complaints` int(11) NOT NULL,
  `total_pending` int(11) NOT NULL,
  `total_in_progress` int(11) NOT NULL,
  `total_resolved` int(11) NOT NULL,
  `total_rejected` int(11) NOT NULL,
  `sent_to` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sent_to` (`sent_to`),
  CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`sent_to`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reports`
--

LOCK TABLES `reports` WRITE;
/*!40000 ALTER TABLE `reports` DISABLE KEYS */;
INSERT INTO `reports` VALUES (1,'2024-01-15 06:00:00','weekly',3,2,1,2,2,0,13);
/*!40000 ALTER TABLE `reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stereotyped_reports`
--

DROP TABLE IF EXISTS `stereotyped_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stereotyped_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` int(11) NOT NULL,
  `handler_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stereotyped_reports`
--

LOCK TABLES `stereotyped_reports` WRITE;
/*!40000 ALTER TABLE `stereotyped_reports` DISABLE KEYS */;
INSERT INTO `stereotyped_reports` VALUES (1,1,3,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 1\nTitle: WiFi Unreliable in Library Wing B\nDescription: The WiFi connection in the second floor, Wing B of the main library frequently drops, making online research impossible.\nCategory: Administrative\nStatus: Resolved\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Jan 12, 2024 09:00\nAdditional Info: Resolved by Libraries Service Directorate','2024-01-12 12:10:00'),(2,2,3,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 2\nTitle: Incorrect Grade Calculation for Midterm\nDescription: The calculation for my Physics 101 midterm grade seems incorrect.\nCategory: Academic\nStatus: In Progress\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Jan 12, 2024 09:15\nAdditional Info: Escalated from Department Head to College Dean','2024-01-13 08:05:00'),(3,3,3,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 3\nTitle: Request for Accessible Seating\nDescription: Classroom 301 in the Tech Building lacks sufficient accessible seating options near the front.\nCategory: Administrative\nStatus: In Progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Jan 12, 2024 09:30\nAdditional Info: Escalated from College Dean to Academic VP','2024-01-14 11:25:00'),(4,5,3,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 5\nTitle: Textbook Availability Concern\nDescription: The required textbook for History 205 is out of stock at the campus bookstore and online options are delayed.\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Jan 10, 2024 11:00\nAdditional Info: Resolved by Libraries Service Directorate','2024-01-11 12:32:00'),(5,6,3,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 6\nTitle: grading c\nDescription: Issue with grading process\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 12, 2025 22:30\nAdditional Info: Resolved by Department Head','2025-04-12 19:50:00'),(6,4,5,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 4\nTitle: Lab Equipment Calibration Issue\nDescription: The spectrophotometer in Chem Lab 2 needs recalibration. Readings are inconsistent.\nCategory: Academic\nStatus: Resolved\nSubmitted By: Jane Smith\nProcessed By: Sarah Johnson\nCreated At: Jan 12, 2024 09:45\nAdditional Info: Resolved by Department Head: jjfjf\n','2025-04-14 11:05:11'),(7,7,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 7\nTitle: nahom\nDescription: nahon nahom nahom nahom nahom nahom nahom and nahom\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 14, 2025 14:06\nAdditional Info: Assigned to: Department head\n','2025-04-14 11:15:52'),(8,8,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 8\nTitle: someme\nDescription: sjfjgjhrigi\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 14, 2025 17:09\nAdditional Info: Assigned to: Department head\n','2025-04-14 14:10:23'),(9,8,5,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 8\nTitle: someme\nDescription: sjfjgjhrigi\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Sarah Johnson\nCreated At: Apr 14, 2025 17:09\nAdditional Info: Resolved by Department Head: ufrueru\n','2025-04-14 14:11:01'),(10,9,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 9\nTitle: foiiogoifg\nDescription: kfkgifvnv\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 14, 2025 18:08\nAdditional Info: Assigned to: College dean\n','2025-04-14 15:10:03'),(11,10,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 10\nTitle: gjitggigu\nDescription: riririfngerjgiehehjigr\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 14, 2025 21:31\nAdditional Info: Assigned to: College dean\n','2025-04-14 18:33:16'),(12,11,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 11\nTitle: jfjfffiewpof\nDescription: sepodoefeopf\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 14, 2025 22:17\nAdditional Info: Assigned to: Department head\n','2025-04-14 19:18:18'),(13,11,5,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 11\nTitle: jfjfffiewpof\nDescription: sepodoefeopf\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Sarah Johnson\nCreated At: Apr 14, 2025 22:17\nAdditional Info: Resolved by Department Head: kjojopkpp\n','2025-04-14 19:21:25'),(14,9,6,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 9\nTitle: foiiogoifg\nDescription: kfkgifvnv\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: David Lee\nCreated At: Apr 14, 2025 18:08\nAdditional Info: Resolved by College Dean: jjjdcfcfjne\n','2025-04-14 19:27:06'),(15,12,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 12\nTitle: abebe\nDescription: abebebebe\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 14, 2025 22:27\nAdditional Info: Assigned to: College dean\n','2025-04-14 19:28:48'),(16,12,15,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 12\nTitle: abebe\nDescription: abebebebe\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nProcessed By: seid yimam\nCreated At: Apr 14, 2025 22:27\nAdditional Info: Escalated to Academic Vice President: jjjfdjcndjnfejiefoejvv for\n','2025-04-14 19:34:47'),(17,3,10,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 3\nTitle: Request for Accessible Seating\nDescription: Classroom 301 in the Tech Building lacks sufficient accessible seating options near the front.\nCategory: Administrative\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Jan 12, 2024 09:30\nAdditional Info: Resolved by Academic Vice President: jjfnjvnjnjvcnierf0-\n','2025-04-14 19:44:06'),(18,12,10,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 12\nTitle: abebe\nDescription: abebebebe\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Apr 14, 2025 22:27\nAdditional Info: Escalated to the President: jhuhuhuiiuiuuoiiiioi\n','2025-04-14 19:51:22'),(19,13,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 13\nTitle: dddd\nDescription: ppppppppppppppppppppppppppp\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 14, 2025 22:52\nAdditional Info: Assigned to: Academic vp\n','2025-04-14 19:53:29'),(20,14,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 14\nTitle: exit exit exit\nDescription: exit exit exit exit exit exit exit exit exit exit exit exit exit exit\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 15, 2025 09:53\nAdditional Info: Assigned to: College dean\n','2025-04-15 06:54:26'),(21,14,15,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 14\nTitle: exit exit exit\nDescription: exit exit exit exit exit exit exit exit exit exit exit exit exit exit\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nProcessed By: seid yimam (College Dean)\nCreated At: Apr 15, 2025 09:53\nAdditional Info/Decision: Escalated by College Dean to Academic Vice President. Reason: jfjfjfjjfj\n','2025-04-15 07:08:50'),(22,14,10,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 14\nTitle: exit exit exit\nDescription: exit exit exit exit exit exit exit exit exit exit exit exit exit exit\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Apr 15, 2025 09:53\nAdditional Info: Escalated to the President: nnnnnnnnnnnnnnnnnnnnn\n','2025-04-15 07:10:49'),(23,15,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 15\nTitle: something\nDescription: something something something new\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 15, 2025 11:32\nAdditional Info: Assigned to: President\n','2025-04-15 08:33:06'),(24,17,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 17\nTitle: some\nDescription: somecase somefkrj\nCategory: Academic\nStatus: In_progress\nSubmitted By: berket demsew\nHandler: Michael Green\nCreated At: Apr 15, 2025 17:09\nAdditional Info: Assigned to: Department head\n','2025-04-15 14:18:18'),(25,17,19,13,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 17\nTitle: some\nDescription: somecase somefkrj\nCategory: Academic\nStatus: Resolved\nSubmitted By: berket demsew\nProcessed By: temesgen adne\nCreated At: Apr 15, 2025 17:09\nAdditional Info: Resolved by Department Head: jifjgeoieo[\n','2025-04-15 14:23:37'),(26,18,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 18\nTitle: grading\nDescription: ihave f grade\nCategory: Academic\nStatus: In_progress\nSubmitted By: henok getnet\nHandler: Michael Green\nCreated At: Apr 15, 2025 17:44\nAdditional Info: Assigned to: Department head\n','2025-04-15 14:52:44'),(27,2,6,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 2\nTitle: Incorrect Grade Calculation for Midterm\nDescription: The calculation for my Physics 101 midterm grade seems incorrect. The points summation doesn\'t match the final percentage shown.\nCategory: Academic\nStatus: In_progress\nSubmitted By: Jane Smith\nProcessed By: David Lee (College Dean)\nCreated At: Jan 12, 2024 09:15\nAdditional Info/Decision: Escalated by College Dean to Academic Vice President. Reason: fgfjg,gjh\n','2025-04-15 15:08:58'),(28,19,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 19\nTitle: some issue on the grade\nDescription: detail is teacher\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 16, 2025 01:08\nAdditional Info: Assigned to: Academic vp\n','2025-04-15 22:09:14'),(29,2,10,13,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 2\nTitle: Incorrect Grade Calculation for Midterm\nDescription: The calculation for my Physics 101 midterm grade seems incorrect. The points summation doesn\'t match the final percentage shown.\nCategory: Academic\nStatus: In_progress\nSubmitted By: Jane Smith\nProcessed By: Emily Davis\nCreated At: Jan 12, 2024 09:15\nAdditional Info: Escalated to the President: itititiieie\n','2025-04-15 22:31:24'),(30,20,3,13,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 20\nTitle: abebe\nDescription: abebebebebebebebe\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 16, 2025 01:34\nAdditional Info: Assigned to: Department head\n','2025-04-15 22:35:48'),(31,2,13,6,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 2\nTitle: Incorrect Grade Calculation for Midterm\nDescription: The calculation for my Physics 101 midterm grade seems incorrect. The points summation doesn\'t match the final percentage shown.\nCategory: Academic\nStatus: Resolved\nSubmitted By: Jane Smith\nProcessed By: Robert Smith (President)\nCreated At: Jan 12, 2024 09:15\nAdditional Info/Decision: fjfjfjf\n','2025-04-15 23:06:46');
/*!40000 ALTER TABLE `stereotyped_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stereotypes`
--

DROP TABLE IF EXISTS `stereotypes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stereotypes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
INSERT INTO `stereotypes` VALUES (1,'discrimination','Complaints involving discriminatory behavior or practices.','2024-01-10 06:00:00'),(2,'harassment','Complaints involving harassment, bullying, or inappropriate behavior.','2024-01-10 06:05:00'),(3,'bias','Complaints involving unfair bias or favoritism.','2024-01-10 06:10:00'),(4,'accessibility','Complaints related to accessibility issues for students with disabilities.','2024-01-10 06:15:00');
/*!40000 ALTER TABLE `stereotypes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','handler','admin','department_head','college_dean','sims','cost_sharing_customer_service','libraries_service_directorate','academic_vp','directorate_officer','admin_vp','president') NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'user1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','user','John','Doe','1234567890','male','john.doe@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:00:00'),(2,'user2','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','user','Jane','Smith','9876543210','female','jane.smith@example.com','Physics','College of Science','active',NULL,'2024-01-10 05:05:00'),(3,'handler1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','handler','Michael','Green','0987654321','male','michael.green@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:10:00'),(4,'admin1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','admin','Admin','User','5555555555','other','admin@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:15:00'),(5,'depthead1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','department_head','Sarah','Johnson','1112223333','female','sarah.johnson@example.com','Computer Science','College of Technology','active',NULL,'2024-01-10 05:20:00'),(6,'dean1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','college_dean','David','Lee','4445556666','male','david.lee@example.com',NULL,'College of Technology','active',NULL,'2024-01-10 05:25:00'),(7,'sims1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','sims','Carol','Jones','7778889999','female','carol.jones@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:30:00'),(8,'costsharing1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','cost_sharing_customer_service','Tom','Brown','2223334444','male','tom.brown@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:35:00'),(9,'library1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','libraries_service_directorate','Eve','Wilson','6667778888','female','eve.wilson@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:40:00'),(10,'academicvp1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','academic_vp','Emily','Davis','9990001111','female','emily.davis@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:45:00'),(11,'directorate1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','directorate_officer','Grace','Taylor','3334445555','female','grace.taylor@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:50:00'),(12,'adminvp1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','admin_vp','Henry','Moore','8889990000','male','henry.moore@example.com',NULL,NULL,'active',NULL,'2024-01-10 05:55:00'),(13,'president1','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','president','Robert','Smith','1231231234','male','president@example.com',NULL,NULL,'active',NULL,'2024-01-10 06:00:00'),(14,'depthead2','$2y$10$Piju0M4w5MpnXtu0u92OueafOcQsTCAQOXSLvTd7z29NHLVu3zDAS','department_head','Samuel','Aschalew','5556667777','male','samuel.aschalew@example.com','Computer Science','College of Technology','active',NULL,'2024-01-10 06:05:00'),(15,'seid','$2y$10$GHdVT3cPI1lkr.vecYsLmuPkfYGNx6QpE0JCO.BZ3Cj7nInyLsN5W','college_dean','seid','yimam','0945454545','male','seid@gmail.com',NULL,'College of Social Sciences and Humanities','active',NULL,'2025-04-14 18:24:45'),(16,'sosi','$2y$10$C0lkemRQjH10LVLl/tyVH.yFvoO.79MF1hBW.PIHHV5szqRa.5O4m','user','sosina','arrijefo',NULL,'other','sossari@gmail.com',NULL,NULL,'active',NULL,'2025-04-14 18:48:30'),(17,'henok','$2y$10$0ftnRYa15JWcjRP1WGZ2yOCiaQrOsjDSVjhA74eh1L7pR2ghpqmeG','user','henok','getnet',NULL,'other','henok@gmail.com',NULL,NULL,'active',NULL,'2025-04-15 14:01:45'),(18,'beki','$2y$10$/UMhNl0VCrjk2RtusglDieeeV4mvxunvuSIlPdqrnVdSweqDNiVIO','user','berket','demsew',NULL,'other','beki@gmail.com',NULL,NULL,'active',NULL,'2025-04-15 14:08:06'),(19,'temu','$2y$10$XX4X6GghYXbVFI4hiOonX.zro229tUjihZIua1HJ.Xlo.B4W.LyIy','department_head','temesgen','adne','0988888856','male','temu1@gmail.com','Animal Science','College of Agriculture and Natural Science','active',NULL,'2025-04-15 14:14:50'),(20,'abebe','$2y$10$RyFWBV65OOiKO4WRxfT2PumHxYUZLr2yZ9amojGzqogmykfrJTBqW','department_head','abebe','temesgen','092326676','male','abebe1@gmail.com','Peace and Development','College of Social Sciences and Humanities','active',NULL,'2025-04-16 11:01:25');
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

-- Dump completed on 2025-04-16 14:12:39
