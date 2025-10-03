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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `abusive_words`
--

LOCK TABLES `abusive_words` WRITE;
/*!40000 ALTER TABLE `abusive_words` DISABLE KEYS */;
INSERT INTO `abusive_words` VALUES (1,'hate','2025-04-19 09:30:08'),(2,'stupid','2025-04-19 09:30:08'),(3,'idiot','2025-04-19 09:30:08');
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
INSERT INTO `committees` VALUES (1,12,13,'2025-04-02 07:00:00');
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
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaint_logs`
--

LOCK TABLES `complaint_logs` WRITE;
/*!40000 ALTER TABLE `complaint_logs` DISABLE KEYS */;
INSERT INTO `complaint_logs` VALUES (1,1,'Abusive Complaint Attempt','User attempted to submit a complaint with abusive words: stupid','2025-04-01 12:30:00'),(2,1,'User Suspended','User suspended for 2 hours due to abusive content: stupid','2025-04-01 12:30:00'),(3,1,'Submitted','Complaint #1: Incorrect Course Registration','2025-04-02 09:00:00'),(4,2,'Submitted','Complaint #2: Payment Issue','2025-04-02 09:15:00'),(5,1,'Submitted','Complaint #3: Grading Issue','2025-04-12 22:30:00'),(6,2,'Submitted','Complaint #4: Transcript Error','2025-04-13 08:00:00'),(7,1,'Submitted','Complaint #5: Lab Access Issue','2025-04-15 10:00:00'),(8,2,'Submitted','Complaint #6: Fee Refund Delay','2025-04-16 11:00:00'),(9,1,'Complaint Filed','User submitted complaint #7: rjjrjjjjejejso...','2025-04-19 09:52:36'),(10,1,'Complaint Filed','User submitted complaint #8: oidirwe0r...','2025-04-19 11:19:52'),(11,1,'Complaint Filed','User submitted complaint #9: hi...','2025-04-19 11:57:50'),(12,1,'Complaint Filed','User submitted complaint #10: hi...','2025-04-19 11:58:28'),(13,1,'Complaint Filed','User submitted complaint #11: it to department head...','2025-04-21 02:36:25'),(14,1,'Complaint Filed','User submitted complaint #12: camera...','2025-04-21 03:12:19'),(15,1,'Complaint Filed','User submitted complaint #13: at the end of a day...','2025-04-21 03:41:12'),(16,1,'Complaint Filed','User submitted complaint #14: i do not know...','2025-04-21 04:00:32'),(17,15,'Complaint Filed','User submitted complaint #15: this issue to agricultural...','2025-04-21 10:15:01'),(18,15,'Complaint Filed','User submitted complaint #16: nanana...','2025-04-21 10:33:13'),(19,15,'Complaint Filed','User submitted complaint #17: nono...','2025-04-21 10:49:22'),(20,15,'Complaint Filed','User submitted complaint #18: please please...','2025-04-21 11:53:43'),(21,15,'Complaint Filed','User submitted complaint #19: ante ante ante nene...','2025-04-21 12:01:02'),(22,15,'Complaint Filed','User submitted complaint #20: hho...','2025-04-21 12:04:50'),(23,1,'Complaint Filed','User submitted complaint #21: no time...','2025-04-21 17:18:16'),(24,1,'Complaint Filed','User submitted complaint #22: matter matter matter...','2025-04-21 17:38:59'),(25,1,'Complaint Filed','User submitted complaint #23: matter...','2025-04-21 21:05:34'),(26,1,'Complaint Filed','User submitted complaint #24: dddd...','2025-04-21 21:35:35'),(27,1,'Complaint Filed','User submitted complaint #25: uuuuuuuuuuuuuu...','2025-04-21 21:46:29'),(28,2,'Complaint Filed','User submitted complaint #26: newnenenene...','2025-04-21 21:48:05'),(29,2,'Complaint Filed','User submitted complaint #27: nonono Ethiopia...','2025-04-21 21:49:45'),(30,2,'Complaint Filed','User submitted complaint #28: gjgjgjgj...','2025-04-21 21:52:17'),(31,2,'Complaint Filed','User submitted complaint #29: nvn jndjsijjvv vpn...','2025-04-21 23:16:59'),(32,1,'Complaint Filed','User submitted complaint #30: adega adega adega adega...','2025-04-22 09:50:37'),(33,1,'Complaint Filed','User submitted complaint #31: gogogog...','2025-04-22 11:00:31'),(34,1,'Complaint Filed','User submitted complaint #32: please please...','2025-04-22 11:32:05'),(35,1,'Complaint Filed','User submitted complaint #33: ivjigjijigtihjdfj...','2025-04-22 14:01:35'),(36,1,'Complaint Filed','User submitted complaint #34: xdscdas...','2025-04-22 14:41:08');
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
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `complaints`
--

LOCK TABLES `complaints` WRITE;
/*!40000 ALTER TABLE `complaints` DISABLE KEYS */;
INSERT INTO `complaints` VALUES (1,1,3,'Incorrect Course Registration','I was enrolled in the wrong course for Fall 2025.','academic','in_progress','standard',0,NULL,NULL,'2025-04-02 06:00:00','2025-04-02 07:00:00',NULL,NULL,NULL,'schedule_screenshot.png'),(2,2,3,'Payment Issue','Overcharged for tuition fees.','administrative','resolved','anonymous',0,NULL,NULL,'2025-04-02 06:15:00','2025-04-03 11:00:00','2025-04-03 11:00:00','Refund processed.',NULL,NULL),(3,1,3,'Grading Issue','Issue with grading process in CS101.','academic','in_progress','standard',0,NULL,1,'2025-04-12 19:30:00','2025-04-12 19:40:00',NULL,NULL,NULL,NULL),(4,2,3,'Transcript Error','My transcript shows an incorrect grade.','academic','','standard',0,NULL,NULL,'2025-04-13 05:00:00','2025-04-14 06:00:00',NULL,NULL,NULL,'transcript.pdf'),(5,1,3,'Lab Access Issue','Unable to access the computer lab due to scheduling conflict.','academic','resolved','standard',0,NULL,1,'2025-04-15 07:00:00','2025-04-19 06:30:23',NULL,NULL,NULL,NULL),(6,2,3,'Fee Refund Delay','Requested a refund for a dropped course, but it has been delayed.','administrative','resolved','anonymous',0,NULL,NULL,'2025-04-16 08:00:00','2025-04-19 08:50:08',NULL,NULL,NULL,NULL),(7,1,3,'rjjrjjjjejejso','frifjrjjfjjf','academic','resolved','standard',0,NULL,NULL,'2025-04-19 06:52:36','2025-04-19 06:53:59',NULL,NULL,NULL,NULL),(8,1,3,'oidirwe0r','jdjdjee','academic','resolved','standard',0,NULL,NULL,'2025-04-19 08:19:52','2025-04-19 08:27:04',NULL,NULL,NULL,NULL),(9,1,3,'hi','how are you password','academic','resolved','standard',0,NULL,NULL,'2025-04-19 08:57:50','2025-04-21 00:03:53','2025-04-21 00:03:53','nvnvnv',NULL,NULL),(10,1,3,'hi','how are you password','academic','resolved','standard',0,NULL,NULL,'2025-04-19 08:58:28','2025-04-19 09:00:29',NULL,NULL,NULL,NULL),(11,1,3,'it to department head','gnjrgrgnruiqputusfdoggdg','academic','resolved','standard',0,NULL,NULL,'2025-04-20 23:36:25','2025-04-20 23:39:13','2025-04-20 23:39:13','gooogdg',NULL,NULL),(12,1,3,'camera','camera camera camera','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 00:12:19','2025-04-21 00:13:44',NULL,NULL,NULL,NULL),(13,1,3,'at the end of a day','what mean at the end of a day????????????????','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 00:41:12','2025-04-21 00:42:32',NULL,NULL,NULL,NULL),(14,1,3,'i do not know','wwwwwwwwwwwwwwwwww','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 01:00:32','2025-04-21 01:01:32',NULL,NULL,NULL,NULL),(15,15,3,'this issue to agricultural','gomen carrot avocado  apple','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 07:15:01','2025-04-21 07:22:43',NULL,NULL,NULL,NULL),(16,15,3,'nanana','ananananoononon','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 07:33:13','2025-04-21 07:34:03',NULL,NULL,NULL,NULL),(17,15,3,'nono','masha teppi teppi teppi','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 07:49:22','2025-04-21 07:50:10',NULL,NULL,NULL,NULL),(18,15,3,'please please','pppplllloooooooooooooooooooooklo','academic','resolved','standard',0,NULL,NULL,'2025-04-21 08:53:43','2025-04-21 08:57:42','2025-04-21 08:57:42','jfnjfjjdfjijfk',NULL,NULL),(19,15,3,'ante ante ante nene','gjrnroormjgvj]dorrmr','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 09:01:02','2025-04-21 09:02:17',NULL,NULL,NULL,NULL),(20,15,3,'hho','hohohohohooh','academic','resolved','standard',0,NULL,NULL,'2025-04-21 09:04:50','2025-04-21 09:05:58','2025-04-21 09:05:58','jfjfjs;daoroproprkro',NULL,NULL),(21,1,3,'no time','no time to it DO !!!! WE HAVE TO DO IT','academic','resolved','standard',0,NULL,NULL,'2025-04-21 14:18:16','2025-04-21 14:22:01','2025-04-21 14:22:01','nice work',NULL,NULL),(22,1,3,'matter matter matter','matter matter matter   matter matter matter  matter matter matter  matter matter matter  matter matter matter  matter matter matter  matter matter matter','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 14:38:59','2025-04-21 14:39:46',NULL,NULL,NULL,NULL),(23,1,3,'matter','nonononnonoononon','academic','resolved','standard',0,NULL,NULL,'2025-04-21 18:05:34','2025-04-21 18:12:19','2025-04-21 18:12:19','jfjfjfjeutig]e',NULL,NULL),(24,1,3,'dddd','vvvvvvvvcvcvcv','academic','resolved','standard',0,NULL,NULL,'2025-04-21 18:35:35','2025-04-21 19:17:10',NULL,NULL,NULL,NULL),(25,1,3,'uuuuuuuuuuuuuu','abebeebebebeb','academic','','standard',0,NULL,NULL,'2025-04-21 18:46:29','2025-04-21 18:55:43',NULL,NULL,NULL,NULL),(26,2,3,'newnenenene','abeebebebebe','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 18:48:05','2025-04-21 18:48:38',NULL,NULL,NULL,NULL),(27,2,3,'nonono Ethiopia','nnnnnnnnnnnnnnnnnnnnnnnnononononononono','academic','','standard',0,NULL,NULL,'2025-04-21 18:49:45','2025-04-22 11:37:18',NULL,NULL,NULL,NULL),(28,2,3,'gjgjgjgj','jdjgvjnvnv  afkigjrojnbjbgn','academic','resolved','standard',0,NULL,NULL,'2025-04-21 18:52:17','2025-04-21 18:55:26','2025-04-21 18:55:26','fhhhhdkkk',NULL,NULL),(29,2,3,'nvn jndjsijjvv vpn','vpnnnnnnnn','academic','in_progress','standard',0,NULL,NULL,'2025-04-21 20:16:59','2025-04-21 20:17:42',NULL,NULL,NULL,NULL),(30,1,3,'adega adega adega adega','kkdkdjrovvbo04rit9epfodkl','academic','in_progress','standard',0,NULL,NULL,'2025-04-22 06:50:37','2025-04-22 06:51:17',NULL,NULL,NULL,NULL),(31,1,3,'gogogog','jojojojojojo','academic','','standard',0,NULL,NULL,'2025-04-22 08:00:31','2025-04-22 12:54:02',NULL,NULL,NULL,NULL),(32,1,3,'please please','fjgjjigjrei hide hide hide','academic','','standard',0,NULL,NULL,'2025-04-22 08:32:05','2025-04-22 12:20:49',NULL,NULL,NULL,NULL),(33,1,3,'ivjigjijigtihjdfj','nnngnfjgkerjgjeritrtirjgnfngjnfjgn','academic','','standard',0,NULL,NULL,'2025-04-22 11:01:35','2025-04-22 11:56:34',NULL,NULL,NULL,NULL),(34,1,3,'xdscdas','xsdfascfecfe','academic','resolved','standard',0,NULL,NULL,'2025-04-22 11:41:08','2025-04-22 12:04:55','2025-04-22 12:04:55','jrjhjrjkrjjrkorgogjiroeprgjjgrejgrp;gj',NULL,NULL);
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
  `receiver_id` int(11) DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `decisions`
--

LOCK TABLES `decisions` WRITE;
/*!40000 ALTER TABLE `decisions` DISABLE KEYS */;
INSERT INTO `decisions` VALUES (1,2,2,6,3,'Refund processed after verifying overcharge.','final','2025-04-03 11:05:00'),(2,5,4,5,7,'Escalated to Campus Registrar for further review.','pending','2025-04-13 06:05:00'),(3,NULL,5,5,NULL,'you complaint solve as','','2025-04-19 06:30:23'),(4,NULL,7,5,NULL,'something must happen ','','2025-04-19 06:53:59'),(5,NULL,8,5,NULL,'hheheheh','','2025-04-19 08:27:04'),(6,NULL,8,3,1,'cdfgrgvffb','final','2025-04-19 08:29:08'),(7,NULL,6,6,NULL,'hhsffhed','','2025-04-19 08:50:08'),(8,NULL,10,6,NULL,'hhhge','','2025-04-19 09:00:29'),(9,11,11,12,3,'gooogdg','final','2025-04-20 23:39:13'),(10,NULL,11,3,1,'ggggggggggggoooooooooooooo000000000000g0g0g0g0g0g0ggg00g0g0g0g0g0g00g0','final','2025-04-20 23:41:40'),(11,12,9,13,1,'nvnvnv','final','2025-04-21 00:03:53'),(12,NULL,12,13,9,'dbbfbbetyrqop[thjththhth]','pending','2025-04-21 00:58:36'),(13,NULL,16,18,13,'ffhfhfhftytqeyuryt','pending','2025-04-21 07:34:52'),(14,NULL,17,18,13,'hfhhfhfhkdjiefi','pending','2025-04-21 07:55:51'),(15,NULL,14,13,9,'fhdhdhhs','pending','2025-04-21 08:33:13'),(16,NULL,13,12,13,'vvgghhgh','pending','2025-04-21 08:48:57'),(17,NULL,15,16,9,'nnjnjk','pending','2025-04-21 08:51:17'),(18,19,18,13,15,'jfnjfjjdfjijfk','final','2025-04-21 08:57:42'),(19,NULL,18,3,15,'jfffjjh','final','2025-04-21 08:58:26'),(20,NULL,19,13,9,'vnvnvnnvnvnv','pending','2025-04-21 09:02:34'),(21,21,20,13,15,'jfjfjs;daoroproprkro','final','2025-04-21 09:05:58'),(22,22,21,12,3,'nice work','final','2025-04-21 14:22:01'),(23,NULL,21,3,1,'be strong','final','2025-04-21 14:36:03'),(24,NULL,22,12,13,'bbcbcbcbcbcbcbcbc','pending','2025-04-21 14:40:52'),(25,24,23,13,1,'jfjfjfjeutig]e','final','2025-04-21 18:12:19'),(26,NULL,23,3,1,'jfjfjfjjajrir','final','2025-04-21 18:13:36'),(27,NULL,24,12,13,'gygyuihuhuh','','2025-04-21 18:38:34'),(28,29,28,12,3,'fhhhhdkkk','final','2025-04-21 18:55:26'),(29,NULL,25,12,13,'cooorkfjgnngjjgvnj','','2025-04-21 18:55:43'),(30,NULL,24,13,9,'nnnfnfnsnnannnnnd','pending','2025-04-21 19:16:14'),(31,NULL,27,13,9,'fjnfjngjnf','pending','2025-04-22 11:37:18'),(32,35,34,9,3,'jrjhjrjkrjjrkorgogjiroeprgjjgrejgrp;gj','final','2025-04-22 12:04:55');
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
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `complaint_id` int(11) NOT NULL,
  `escalated_to` enum('sims','cost_sharing','campus_registrar','university_registrar','academic_vp','president','academic','department_head','college_dean') NOT NULL,
  `escalated_to_id` int(11) DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `escalations`
--

LOCK TABLES `escalations` WRITE;
/*!40000 ALTER TABLE `escalations` DISABLE KEYS */;
INSERT INTO `escalations` VALUES (1,1,'sims',5,3,NULL,NULL,'resolved','2025-04-02 07:00:00','2025-04-22 14:20:22','2025-04-22 14:20:22','Escalated to Campus Registrar by SIMS. Reason: fkkdfeporopeworf',3,'assignment'),(2,2,'cost_sharing',6,3,NULL,NULL,'resolved','2025-04-02 07:15:00','2025-04-03 11:00:00','2025-04-03 11:00:00','Refund processed.',3,'assignment'),(3,3,'department_head',14,3,'College of Technology',1,'pending','2025-04-12 19:40:00',NULL,NULL,NULL,3,'assignment'),(4,4,'sims',5,3,NULL,NULL,'escalated','2025-04-13 05:30:00','2025-04-13 06:00:00',NULL,NULL,3,'assignment'),(5,4,'campus_registrar',7,5,NULL,NULL,'pending','2025-04-13 06:00:00',NULL,NULL,NULL,3,'escalation'),(6,5,'',NULL,3,'College of Technology',1,'resolved','2025-04-15 07:10:00','2025-04-19 06:30:23',NULL,NULL,3,'assignment'),(7,6,'',NULL,3,NULL,NULL,'resolved','2025-04-16 08:10:00','2025-04-19 08:50:08',NULL,NULL,3,'assignment'),(8,7,'',NULL,3,NULL,NULL,'resolved','2025-04-19 06:53:17','2025-04-19 06:53:59',NULL,NULL,3,'assignment'),(9,8,'',NULL,3,NULL,NULL,'resolved','2025-04-19 08:20:37','2025-04-19 08:27:04',NULL,NULL,3,'assignment'),(10,10,'',NULL,3,NULL,NULL,'resolved','2025-04-19 08:59:26','2025-04-19 09:00:29',NULL,NULL,3,'assignment'),(11,11,'department_head',12,3,NULL,NULL,'resolved','2025-04-20 23:37:55','2025-04-20 23:39:13','2025-04-20 23:39:13','gooogdg',3,'assignment'),(12,9,'college_dean',13,3,NULL,NULL,'resolved','2025-04-20 23:58:41','2025-04-21 00:03:53','2025-04-21 00:03:53','nvnvnv',3,'assignment'),(13,12,'academic_vp',9,3,NULL,NULL,'escalated','2025-04-21 00:13:44','2025-04-21 00:58:36',NULL,NULL,3,'assignment'),(14,13,'college_dean',13,3,NULL,NULL,'escalated','2025-04-21 00:42:32','2025-04-21 08:48:57',NULL,NULL,3,'assignment'),(15,14,'academic_vp',9,3,NULL,NULL,'escalated','2025-04-21 01:01:32','2025-04-21 08:33:13',NULL,NULL,3,'assignment'),(16,15,'academic_vp',9,3,NULL,NULL,'escalated','2025-04-21 07:22:43','2025-04-21 08:51:17',NULL,NULL,3,'assignment'),(17,16,'college_dean',13,3,NULL,NULL,'escalated','2025-04-21 07:34:03','2025-04-21 07:34:52',NULL,NULL,3,'assignment'),(18,17,'college_dean',13,3,NULL,NULL,'escalated','2025-04-21 07:50:10','2025-04-21 07:55:51',NULL,NULL,3,'assignment'),(19,18,'college_dean',13,3,NULL,NULL,'resolved','2025-04-21 08:55:20','2025-04-21 08:57:42','2025-04-21 08:57:42','jfnjfjjdfjijfk',3,'assignment'),(20,19,'academic_vp',9,3,NULL,NULL,'escalated','2025-04-21 09:02:17','2025-04-21 09:02:34',NULL,NULL,3,'assignment'),(21,20,'college_dean',13,3,NULL,NULL,'resolved','2025-04-21 09:05:27','2025-04-21 09:05:58','2025-04-21 09:05:58','jfjfjs;daoroproprkro',3,'assignment'),(22,21,'department_head',12,3,NULL,NULL,'resolved','2025-04-21 14:19:30','2025-04-21 14:22:01','2025-04-21 14:22:01','nice work',3,'assignment'),(23,22,'college_dean',13,3,NULL,NULL,'escalated','2025-04-21 14:39:46','2025-04-21 14:40:52',NULL,NULL,3,'assignment'),(24,23,'college_dean',13,3,NULL,NULL,'resolved','2025-04-21 18:06:28','2025-04-21 18:12:19','2025-04-21 18:12:19','jfjfjfjeutig]e',3,'assignment'),(25,24,'academic_vp',9,3,NULL,NULL,'resolved','2025-04-21 18:36:53','2025-04-21 19:17:10',NULL,'jvjjfjoriroorkofk',3,'escalation'),(26,25,'college_dean',13,3,NULL,NULL,'escalated','2025-04-21 18:47:23','2025-04-21 18:55:43',NULL,NULL,3,'assignment'),(27,26,'college_dean',13,3,NULL,NULL,'pending','2025-04-21 18:48:38',NULL,NULL,NULL,3,'assignment'),(28,27,'academic_vp',9,3,NULL,NULL,'pending','2025-04-21 18:51:21','2025-04-22 11:37:18',NULL,NULL,3,'escalation'),(29,28,'department_head',12,3,NULL,NULL,'resolved','2025-04-21 18:53:00','2025-04-21 18:55:26','2025-04-21 18:55:26','fhhhhdkkk',3,'assignment'),(30,29,'department_head',12,3,NULL,NULL,'pending','2025-04-21 20:17:42',NULL,NULL,NULL,3,'assignment'),(31,30,'academic_vp',9,3,NULL,NULL,'pending','2025-04-22 06:51:17',NULL,NULL,NULL,3,'assignment'),(32,31,'academic_vp',9,3,NULL,NULL,'','2025-04-22 08:01:08','2025-04-22 12:54:02','2025-04-22 12:54:02','vcvfdvdfvdfgvsfgvvdfdf',3,'assignment'),(33,32,'academic_vp',9,3,NULL,NULL,'','2025-04-22 08:32:51','2025-04-22 12:20:49',NULL,'jfnjnjfnjnjfijfvkvlfkvsv',3,'assignment'),(34,33,'academic_vp',9,3,NULL,NULL,'','2025-04-22 11:02:11','2025-04-22 11:56:34','2025-04-22 11:56:34','jfgjhjhd;kfjkgvhfjkghjvh',3,'assignment'),(35,34,'academic_vp',9,3,NULL,NULL,'resolved','2025-04-22 11:42:11','2025-04-22 12:04:55','2025-04-22 12:04:55','jrjhjrjkrjjrkorgogjiroeprgjjgrejgrp;gj',3,'assignment'),(36,33,'president',10,9,NULL,NULL,'pending','2025-04-22 11:56:34',NULL,NULL,NULL,3,'escalation'),(37,32,'president',10,9,NULL,NULL,'pending','2025-04-22 12:20:49',NULL,NULL,NULL,3,'escalation'),(38,31,'president',10,9,NULL,NULL,'pending','2025-04-22 12:54:02',NULL,NULL,NULL,3,'escalation'),(39,1,'campus_registrar',7,5,NULL,NULL,'pending','2025-04-22 14:20:22',NULL,NULL,NULL,3,'escalation');
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
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `handler_id` int(11) DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=246 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,1,1,'Your complaint \"Incorrect Course Registration\" has been assigned to SIMS.',0,'2025-04-02 07:01:00'),(2,5,1,'New complaint assigned: \"Incorrect Course Registration\".',0,'2025-04-02 07:01:00'),(3,2,2,'Your complaint \"Payment Issue\" has been resolved: Refund processed.',0,'2025-04-03 11:01:00'),(4,6,2,'New complaint assigned: \"Payment Issue\".',0,'2025-04-02 07:16:00'),(5,1,3,'Your complaint \"Grading Issue\" has been assigned to Department Head.',0,'2025-04-12 19:41:00'),(6,14,3,'New complaint assigned: \"Grading Issue\".',0,'2025-04-12 19:41:00'),(7,2,4,'Your complaint \"Transcript Error\" has been assigned to SIMS.',0,'2025-04-13 05:31:00'),(8,5,4,'New complaint assigned: \"Transcript Error\".',0,'2025-04-13 05:31:00'),(9,2,4,'Your complaint \"Transcript Error\" has been escalated to Campus Registrar.',0,'2025-04-13 06:01:00'),(10,7,4,'New complaint escalated: \"Transcript Error\".',0,'2025-04-13 06:01:00'),(11,1,NULL,'Your account has been suspended for 2 hours due to the use of inappropriate language: stupid.',0,'2025-04-01 09:30:00'),(12,1,5,'Your complaint \"Lab Access Issue\" has been assigned to SIMS.',0,'2025-04-15 07:11:00'),(13,5,5,'New complaint assigned: \"Lab Access Issue\".',0,'2025-04-15 07:11:00'),(14,2,6,'Your complaint \"Fee Refund Delay\" has been assigned to Cost Sharing.',0,'2025-04-16 08:11:00'),(15,6,6,'New complaint assigned: \"Fee Refund Delay\".',0,'2025-04-16 08:11:00'),(16,1,7,'Your complaint \"rjjrjjjjejejso\" has been submitted and is pending review.',0,'2025-04-19 06:52:36'),(17,3,7,'A new complaint (#7) has been assigned to you.',0,'2025-04-19 06:52:36'),(18,1,7,'Your Complaint #7 has been validated.',0,'2025-04-19 06:52:57'),(19,5,7,'A complaint (ID #7) has been assigned to you for review.',0,'2025-04-19 06:53:17'),(20,1,7,'Your complaint has been assigned to a higher authority.',0,'2025-04-19 06:53:17'),(21,10,7,'A new assigned report for Complaint #7 has been submitted by Michael Green.',0,'2025-04-19 06:53:17'),(22,1,8,'Your complaint \"oidirwe0r\" has been submitted and is pending review.',0,'2025-04-19 08:19:52'),(23,3,8,'A new complaint (#8) has been assigned to you.',0,'2025-04-19 08:19:52'),(24,1,8,'Your Complaint #8 has been validated.',0,'2025-04-19 08:20:20'),(25,5,8,'A complaint (ID #8) has been assigned to you for review.',0,'2025-04-19 08:20:37'),(26,1,8,'Your complaint has been assigned to a higher authority.',0,'2025-04-19 08:20:37'),(27,10,8,'A new assigned report for Complaint #8 has been submitted by Michael Green.',0,'2025-04-19 08:20:37'),(28,1,8,'You received a final decision regarding Complaint #8.',0,'2025-04-19 08:29:08'),(29,1,9,'Your complaint \"hi\" has been submitted and is pending review.',0,'2025-04-19 08:57:50'),(30,3,9,'A new complaint (#9) has been assigned to you.',0,'2025-04-19 08:57:50'),(31,1,10,'Your complaint \"hi\" has been submitted and is pending review.',0,'2025-04-19 08:58:28'),(32,3,10,'A new complaint (#10) has been assigned to you.',0,'2025-04-19 08:58:28'),(33,1,10,'Your Complaint #10 has been validated.',0,'2025-04-19 08:58:50'),(34,1,9,'Your Complaint #9 has been validated.',0,'2025-04-19 08:59:04'),(35,6,10,'A complaint (ID #10) has been assigned to you for review.',0,'2025-04-19 08:59:26'),(36,1,10,'Your complaint has been assigned to a higher authority.',0,'2025-04-19 08:59:26'),(37,10,10,'A new assigned report for Complaint #10 has been submitted by Michael Green.',0,'2025-04-19 08:59:26'),(38,1,11,'Your complaint \"it to department head\" has been submitted and is pending review.',0,'2025-04-20 23:36:25'),(39,3,11,'A new complaint (#11) has been assigned to you.',0,'2025-04-20 23:36:25'),(40,1,11,'Your Complaint #11 has been validated.',0,'2025-04-20 23:37:09'),(41,12,11,'A complaint (ID #11) has been assigned to you for review.',1,'2025-04-20 23:37:55'),(42,1,11,'Your complaint has been assigned to a higher authority.',0,'2025-04-20 23:37:55'),(43,10,11,'A new assigned report for Complaint #11 has been submitted by Michael Green.',0,'2025-04-20 23:37:55'),(44,3,11,'A final decision has been made on Complaint #11 by the Department Head.',0,'2025-04-20 23:39:13'),(45,1,11,'Your complaint has been resolved: gooogdg',0,'2025-04-20 23:39:13'),(46,10,11,'A new resolved report for Complaint #11 has been submitted by Henry Clark.',0,'2025-04-20 23:39:13'),(47,1,11,'You received a final decision regarding Complaint #11.',0,'2025-04-20 23:41:40'),(48,13,9,'A complaint (ID #9) has been assigned to you for review.',1,'2025-04-20 23:58:41'),(49,1,9,'Your complaint has been assigned to a higher authority.',0,'2025-04-20 23:58:41'),(50,10,9,'A new assigned report for Complaint #9 has been submitted by Michael Green.',0,'2025-04-20 23:58:41'),(51,1,9,'Your Complaint (#9: hi) has been resolved by the College Dean. Decision: nvnvnv',0,'2025-04-21 00:03:53'),(52,3,9,'Complaint #9, which you escalated, has been resolved by the College Dean. Decision: nvnvnv',0,'2025-04-21 00:03:53'),(53,10,9,'A new \'resolved\' report for Complaint #9 has been submitted by College Dean Grace Taylor.',0,'2025-04-21 00:03:53'),(54,1,12,'Your complaint \"camera\" has been submitted and is pending review.',0,'2025-04-21 00:12:19'),(55,3,12,'A new complaint (#12) has been assigned to you.',0,'2025-04-21 00:12:19'),(56,1,12,'Your Complaint #12 has been validated.',0,'2025-04-21 00:13:30'),(57,13,12,'A complaint (ID #12) has been assigned to you for review.',1,'2025-04-21 00:13:44'),(58,1,12,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 00:13:44'),(59,10,12,'A new assigned report for Complaint #12 has been submitted by Michael Green.',0,'2025-04-21 00:13:44'),(60,1,13,'Your complaint \"at the end of a day\" has been submitted and is pending review.',0,'2025-04-21 00:41:12'),(61,3,13,'A new complaint (#13) has been assigned to you.',0,'2025-04-21 00:41:12'),(62,1,13,'Your Complaint #13 has been validated.',0,'2025-04-21 00:42:02'),(63,12,13,'A complaint (ID #13) has been assigned to you for review.',1,'2025-04-21 00:42:32'),(64,1,13,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 00:42:32'),(65,10,13,'A new assigned report for Complaint #13 has been submitted by Michael Green.',0,'2025-04-21 00:42:32'),(66,9,12,'Complaint #12 has been escalated to you by the College Dean.',1,'2025-04-21 00:58:36'),(67,1,14,'Your complaint \"i do not know\" has been submitted and is pending review.',0,'2025-04-21 01:00:32'),(68,3,14,'A new complaint (#14) has been assigned to you.',0,'2025-04-21 01:00:32'),(69,1,14,'Your Complaint #14 has been validated.',0,'2025-04-21 01:01:01'),(70,13,14,'A complaint (ID #14) has been assigned to you for review.',1,'2025-04-21 01:01:32'),(71,1,14,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 01:01:32'),(72,10,14,'A new assigned report for Complaint #14 has been submitted by Michael Green.',0,'2025-04-21 01:01:32'),(73,15,15,'Your complaint \"this issue to agricultural\" has been submitted and is pending review.',0,'2025-04-21 07:15:01'),(74,3,15,'A new complaint (#15) has been assigned to you.',0,'2025-04-21 07:15:01'),(75,15,15,'Your Complaint #15 has been validated.',0,'2025-04-21 07:15:29'),(76,16,15,'A complaint (ID #15) has been assigned to you for review.',1,'2025-04-21 07:22:43'),(77,15,15,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 07:22:43'),(78,10,15,'A new assigned report for Complaint #15 has been submitted by Michael Green.',0,'2025-04-21 07:22:43'),(79,15,16,'Your complaint \"nanana\" has been submitted and is pending review.',0,'2025-04-21 07:33:13'),(80,3,16,'A new complaint (#16) has been assigned to you.',0,'2025-04-21 07:33:13'),(81,15,16,'Your Complaint #16 has been validated.',0,'2025-04-21 07:33:39'),(82,18,16,'A complaint (ID #16) has been assigned to you for review.',0,'2025-04-21 07:34:03'),(83,15,16,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 07:34:03'),(84,10,16,'A new assigned report for Complaint #16 has been submitted by Michael Green.',0,'2025-04-21 07:34:03'),(85,13,16,'Complaint #16 has been escalated to you by the Department Head.',1,'2025-04-21 07:34:52'),(86,15,17,'Your complaint \"nono\" has been submitted and is pending review.',0,'2025-04-21 07:49:22'),(87,3,17,'A new complaint (#17) has been assigned to you.',0,'2025-04-21 07:49:22'),(88,15,17,'Your Complaint #17 has been validated.',0,'2025-04-21 07:49:54'),(89,18,17,'A complaint (ID #17) has been assigned to you for review.',0,'2025-04-21 07:50:10'),(90,15,17,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 07:50:10'),(91,10,17,'A new assigned report for Complaint #17 has been submitted by Michael Green.',0,'2025-04-21 07:50:10'),(92,13,17,'Complaint #17 has been escalated to you by the Department Head.',1,'2025-04-21 07:55:51'),(93,9,14,'Complaint #14 has been escalated to you by the College Dean.',1,'2025-04-21 08:33:13'),(94,13,13,'Complaint #13 has been escalated to you by the Department Head.',1,'2025-04-21 08:48:57'),(95,9,15,'Complaint #15 has been escalated to you by the College Dean.',1,'2025-04-21 08:51:17'),(96,15,18,'Your complaint \"please please\" has been submitted and is pending review.',0,'2025-04-21 08:53:43'),(97,3,18,'A new complaint (#18) has been assigned to you.',0,'2025-04-21 08:53:43'),(98,15,18,'Your Complaint #18 has been validated.',0,'2025-04-21 08:54:44'),(99,13,18,'A complaint (ID #18) has been assigned to you for review.',1,'2025-04-21 08:55:20'),(100,15,18,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 08:55:20'),(101,10,18,'A new assigned report for Complaint #18 has been submitted by Michael Green.',0,'2025-04-21 08:55:20'),(102,15,18,'Your Complaint (#18: please please) has been resolved by the College Dean. Decision: jfnjfjjdfjijfk',0,'2025-04-21 08:57:42'),(103,3,18,'Complaint #18, which you escalated, has been resolved by the College Dean. Decision: jfnjfjjdfjijfk',0,'2025-04-21 08:57:42'),(104,10,18,'A new \'resolved\' report for Complaint #18 has been submitted by College Dean Grace Taylor.',0,'2025-04-21 08:57:42'),(105,15,18,'You received a final decision regarding Complaint #18.',0,'2025-04-21 08:58:26'),(106,15,19,'Your complaint \"ante ante ante nene\" has been submitted and is pending review.',0,'2025-04-21 09:01:02'),(107,3,19,'A new complaint (#19) has been assigned to you.',0,'2025-04-21 09:01:02'),(108,15,19,'Your Complaint #19 has been validated.',0,'2025-04-21 09:01:35'),(109,13,19,'A complaint (ID #19) has been assigned to you for review.',1,'2025-04-21 09:02:17'),(110,15,19,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 09:02:17'),(111,10,19,'A new assigned report for Complaint #19 has been submitted by Michael Green.',0,'2025-04-21 09:02:17'),(112,9,19,'Complaint #19 has been escalated to you by the College Dean.',1,'2025-04-21 09:02:34'),(113,15,20,'Your complaint \"hho\" has been submitted and is pending review.',0,'2025-04-21 09:04:50'),(114,3,20,'A new complaint (#20) has been assigned to you.',0,'2025-04-21 09:04:50'),(115,15,20,'Your Complaint #20 has been validated.',0,'2025-04-21 09:05:17'),(116,13,20,'A complaint (ID #20) has been assigned to you for review.',1,'2025-04-21 09:05:27'),(117,15,20,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 09:05:27'),(118,10,20,'A new assigned report for Complaint #20 has been submitted by Michael Green.',0,'2025-04-21 09:05:27'),(119,15,20,'Your Complaint (#20: hho) has been resolved by the College Dean. Decision: jfjfjs;daoroproprkro',0,'2025-04-21 09:05:58'),(120,3,20,'Complaint #20, which you escalated, has been resolved by the College Dean. Decision: jfjfjs;daoroproprkro',0,'2025-04-21 09:05:58'),(121,10,20,'A new \'resolved\' report for Complaint #20 has been submitted by College Dean Grace Taylor.',0,'2025-04-21 09:05:58'),(122,1,21,'Your complaint \"no time\" has been submitted and is pending review.',0,'2025-04-21 14:18:16'),(123,3,21,'A new complaint (#21) has been assigned to you.',0,'2025-04-21 14:18:16'),(124,1,21,'Your Complaint #21 has been validated.',0,'2025-04-21 14:19:08'),(125,12,21,'A complaint (ID #21) has been assigned to you for review.',0,'2025-04-21 14:19:30'),(126,1,21,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 14:19:30'),(127,10,21,'A new assigned report for Complaint #21 has been submitted by Michael Green.',0,'2025-04-21 14:19:30'),(128,3,21,'A final decision has been made on Complaint #21 by the Department Head.',0,'2025-04-21 14:22:01'),(129,1,21,'Your complaint has been resolved: nice work',0,'2025-04-21 14:22:01'),(130,10,21,'A new resolved report for Complaint #21 has been submitted by Henry Clark.',0,'2025-04-21 14:22:01'),(131,1,21,'You received a final decision regarding Complaint #21.',0,'2025-04-21 14:36:03'),(132,1,22,'Your complaint \"matter matter matter\" has been submitted and is pending review.',0,'2025-04-21 14:38:59'),(133,3,22,'A new complaint (#22) has been assigned to you.',0,'2025-04-21 14:38:59'),(134,1,22,'Your Complaint #22 has been validated.',0,'2025-04-21 14:39:25'),(135,12,22,'A complaint (ID #22) has been assigned to you for review.',0,'2025-04-21 14:39:46'),(136,1,22,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 14:39:46'),(137,10,22,'A new assigned report for Complaint #22 has been submitted by Michael Green.',0,'2025-04-21 14:39:46'),(138,13,22,'Complaint #22 has been escalated to you by the Department Head.',1,'2025-04-21 14:40:52'),(139,1,23,'Your complaint \"matter\" has been submitted and is pending review.',0,'2025-04-21 18:05:34'),(140,3,23,'A new complaint (#23) has been assigned to you.',0,'2025-04-21 18:05:34'),(141,1,23,'Your Complaint #23 has been validated.',0,'2025-04-21 18:06:10'),(142,13,23,'A complaint (ID #23) has been assigned to you for review.',1,'2025-04-21 18:06:28'),(143,1,23,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 18:06:28'),(144,10,23,'A new assigned report for Complaint #23 has been submitted by Michael Green.',0,'2025-04-21 18:06:28'),(145,1,23,'Your Complaint (#23: matter) has been resolved by the College Dean. Decision: jfjfjfjeutig]e',0,'2025-04-21 18:12:19'),(146,3,23,'Complaint #23, which you escalated, has been resolved by the College Dean. Decision: jfjfjfjeutig]e',0,'2025-04-21 18:12:19'),(147,10,23,'A new \'resolved\' report for Complaint #23 has been submitted by College Dean Grace Taylor.',0,'2025-04-21 18:12:19'),(148,1,23,'You received a final decision regarding Complaint #23.',0,'2025-04-21 18:13:36'),(149,1,24,'Your complaint \"dddd\" has been submitted and is pending review.',0,'2025-04-21 18:35:35'),(150,3,24,'A new complaint (#24) has been assigned to you.',0,'2025-04-21 18:35:35'),(151,1,24,'Your Complaint #24 has been validated.',0,'2025-04-21 18:36:28'),(152,12,24,'A complaint (ID #24) has been assigned to you for review.',0,'2025-04-21 18:36:53'),(153,1,24,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 18:36:53'),(154,10,24,'A new assigned report for Complaint #24 has been submitted by Michael Green.',0,'2025-04-21 18:36:53'),(155,13,24,'Complaint #24 has been escalated to you by the Department Head: Henry Clark',1,'2025-04-21 18:38:34'),(156,1,25,'Your complaint \"uuuuuuuuuuuuuu\" has been submitted and is pending review.',0,'2025-04-21 18:46:29'),(157,3,25,'A new complaint (#25) has been assigned to you.',0,'2025-04-21 18:46:29'),(158,1,25,'Your Complaint #25 has been validated.',0,'2025-04-21 18:47:04'),(159,12,25,'A complaint (ID #25) has been assigned to you for review.',0,'2025-04-21 18:47:23'),(160,1,25,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 18:47:23'),(161,10,25,'A new assigned report for Complaint #25 has been submitted by Michael Green.',0,'2025-04-21 18:47:23'),(162,2,26,'Your complaint \"newnenenene\" has been submitted and is pending review.',0,'2025-04-21 18:48:05'),(163,3,26,'A new complaint (#26) has been assigned to you.',0,'2025-04-21 18:48:05'),(164,2,26,'Your Complaint #26 has been validated.',0,'2025-04-21 18:48:27'),(165,13,26,'A complaint (ID #26) has been assigned to you for review.',1,'2025-04-21 18:48:38'),(166,2,26,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 18:48:38'),(167,10,26,'A new assigned report for Complaint #26 has been submitted by Michael Green.',0,'2025-04-21 18:48:38'),(168,2,27,'Your complaint \"nonono Ethiopia\" has been submitted and is pending review.',0,'2025-04-21 18:49:45'),(169,3,27,'A new complaint (#27) has been assigned to you.',0,'2025-04-21 18:49:45'),(170,2,27,'Your Complaint #27 has been validated.',0,'2025-04-21 18:50:24'),(171,13,27,'A complaint (ID #27) has been assigned to you for review.',1,'2025-04-21 18:51:21'),(172,2,27,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 18:51:21'),(173,10,27,'A new assigned report for Complaint #27 has been submitted by Michael Green.',0,'2025-04-21 18:51:21'),(174,2,28,'Your complaint \"gjgjgjgj\" has been submitted and is pending review.',0,'2025-04-21 18:52:17'),(175,3,28,'A new complaint (#28) has been assigned to you.',0,'2025-04-21 18:52:17'),(176,2,28,'Your Complaint #28 has been validated.',0,'2025-04-21 18:52:39'),(177,12,28,'A complaint (ID #28) has been assigned to you for review.',0,'2025-04-21 18:53:00'),(178,2,28,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 18:53:00'),(179,10,28,'A new assigned report for Complaint #28 has been submitted by Michael Green.',0,'2025-04-21 18:53:00'),(180,3,28,'A final decision has been made on Complaint #28 by Henry Clark.',0,'2025-04-21 18:55:26'),(181,2,28,'Your complaint #28 has been resolved by Henry Clark: fhhhhdkkk',0,'2025-04-21 18:55:26'),(182,10,28,'A new resolved report for Complaint #28 has been submitted by Henry Clark on Apr 21, 2025 20:55.',0,'2025-04-21 18:55:26'),(183,13,25,'Complaint #25 has been escalated to you by the Department Head: Henry Clark',1,'2025-04-21 18:55:43'),(184,9,24,'Complaint #24 has been escalated to you by the College Dean.',1,'2025-04-21 19:16:14'),(185,1,24,'Your complaint #24 has been resolved by the Academic Vice President: jvjjfjoriroorkofk',0,'2025-04-21 19:17:10'),(186,3,24,'Complaint #24, which you handled, has been resolved by the Academic Vice President: jvjjfjoriroorkofk',0,'2025-04-21 19:17:10'),(187,3,24,'Complaint #24, which you escalated, has been resolved by the Academic Vice President: jvjjfjoriroorkofk',0,'2025-04-21 19:17:10'),(188,10,24,'A new resolved report for Complaint #24 has been submitted by Emily Davis.',0,'2025-04-21 19:17:10'),(189,2,29,'Your complaint \"nvn jndjsijjvv vpn\" has been submitted and is pending review.',0,'2025-04-21 20:16:59'),(190,3,29,'A new complaint (#29) has been assigned to you.',0,'2025-04-21 20:16:59'),(191,2,29,'Your Complaint #29 has been validated.',0,'2025-04-21 20:17:27'),(192,12,29,'A complaint (ID #29) has been assigned to you for review.',0,'2025-04-21 20:17:42'),(193,2,29,'Your complaint has been assigned to a higher authority.',0,'2025-04-21 20:17:42'),(194,10,29,'A new assigned report for Complaint #29 has been submitted by Michael Green.',0,'2025-04-21 20:17:42'),(195,1,30,'Your complaint \"adega adega adega adega\" has been submitted and is pending review.',0,'2025-04-22 06:50:37'),(196,3,30,'A new complaint (#30) has been assigned to you.',0,'2025-04-22 06:50:37'),(197,1,30,'Your Complaint #30 has been validated.',0,'2025-04-22 06:51:02'),(198,9,30,'A complaint (ID #30) has been assigned to you for review.',1,'2025-04-22 06:51:17'),(199,1,30,'Your complaint has been assigned to a higher authority.',0,'2025-04-22 06:51:17'),(200,10,30,'A new assigned report for Complaint #30 has been submitted by Michael Green.',0,'2025-04-22 06:51:17'),(201,1,31,'Your complaint \"gogogog\" has been submitted and is pending review.',0,'2025-04-22 08:00:31'),(202,3,31,'A new complaint (#31) has been assigned to you.',0,'2025-04-22 08:00:31'),(203,1,31,'Your Complaint #31 has been validated.',0,'2025-04-22 08:00:58'),(204,9,31,'A complaint (ID #31) has been assigned to you for review.',1,'2025-04-22 08:01:08'),(205,1,31,'Your complaint has been assigned to a higher authority.',0,'2025-04-22 08:01:08'),(206,10,31,'A new assigned report for Complaint #31 has been submitted by Michael Green.',0,'2025-04-22 08:01:08'),(207,1,32,'Your complaint \"please please\" has been submitted and is pending review.',0,'2025-04-22 08:32:05'),(208,3,32,'A new complaint (#32) has been assigned to you.',0,'2025-04-22 08:32:05'),(209,1,32,'Your Complaint #32 has been validated.',0,'2025-04-22 08:32:25'),(210,9,32,'A complaint (ID #32) has been assigned to you for review.',1,'2025-04-22 08:32:51'),(211,1,32,'Your complaint has been assigned to a higher authority.',0,'2025-04-22 08:32:51'),(212,10,32,'A new assigned report for Complaint #32 has been submitted by Michael Green.',0,'2025-04-22 08:32:51'),(213,1,33,'Your complaint \"ivjigjijigtihjdfj\" has been submitted and is pending review.',0,'2025-04-22 11:01:35'),(214,3,33,'A new complaint (#33) has been assigned to you.',0,'2025-04-22 11:01:35'),(215,1,33,'Your Complaint #33 has been validated.',0,'2025-04-22 11:01:56'),(216,9,33,'A complaint (ID #33) has been assigned to you for review.',1,'2025-04-22 11:02:11'),(217,1,33,'Your complaint has been assigned to a higher authority.',0,'2025-04-22 11:02:11'),(218,10,33,'A new assigned report for Complaint #33 has been submitted by Michael Green.',0,'2025-04-22 11:02:11'),(219,9,27,'Complaint #27 has been escalated to you by the College Dean.',0,'2025-04-22 11:37:18'),(220,1,34,'Your complaint \"xdscdas\" has been submitted and is pending review.',0,'2025-04-22 11:41:08'),(221,3,34,'A new complaint (#34) has been assigned to you.',0,'2025-04-22 11:41:08'),(222,1,34,'Your Complaint #34 has been validated.',0,'2025-04-22 11:41:37'),(223,9,34,'A complaint (ID #34) has been assigned to you for review.',0,'2025-04-22 11:42:11'),(224,1,34,'Your complaint has been assigned to a higher authority.',0,'2025-04-22 11:42:11'),(225,10,34,'A new assigned report for Complaint #34 has been submitted by Michael Green.',0,'2025-04-22 11:42:11'),(226,10,33,'Complaint #33 has been escalated to you by Academic VP Emily Davis. Reason: jfgjhjhd;kfjkgvhfjkghjvh',0,'2025-04-22 11:56:34'),(227,1,33,'Update on Complaint #33: Your complaint has been escalated to the President by Academic VP Emily Davis. Reason: jfgjhjhd;kfjkgvhfjkghjvh',0,'2025-04-22 11:56:34'),(228,3,33,'Update on Complaint #33 (originally handled by you): Escalated to President by Academic VP Emily Davis. Reason: jfgjhjhd;kfjkgvhfjkghjvh',0,'2025-04-22 11:56:34'),(229,10,33,'A new escalated report for Complaint #33 has been submitted by Emily Davis on Apr 22, 2025 13:56.',0,'2025-04-22 11:56:34'),(230,3,34,'A final decision has been made on Complaint #34 by Emily Davis: jrjhjrjkrjjrkorgogjiroeprgjjgrejgrp;gj. Please review the resolution on your dashboard.',0,'2025-04-22 12:04:55'),(231,1,34,'Your complaint #34 has been resolved by Emily Davis: jrjhjrjkrjjrkorgogjiroeprgjjgrejgrp;gj',0,'2025-04-22 12:04:55'),(232,3,34,'Complaint #34, which you assigned, has been resolved by Emily Davis: jrjhjrjkrjjrkorgogjiroeprgjjgrejgrp;gj',0,'2025-04-22 12:04:55'),(233,10,34,'A new resolved report for Complaint #34 has been submitted by Emily Davis on Apr 22, 2025 14:04.',0,'2025-04-22 12:04:55'),(234,10,32,'Complaint #32 has been escalated to you by Emily Davis: jfnjnjfnjnjfijfvkvlfkvsv',0,'2025-04-22 12:20:49'),(235,1,32,'Your complaint #32 has been escalated to the President by Emily Davis: jfnjnjfnjnjfijfvkvlfkvsv',0,'2025-04-22 12:20:49'),(236,3,32,'Complaint #32, which you handled, has been escalated to the President by Emily Davis: jfnjnjfnjnjfijfvkvlfkvsv',0,'2025-04-22 12:20:49'),(237,3,32,'Complaint #32, which you assigned, has been escalated to the President by Emily Davis: jfnjnjfnjnjfijfvkvlfkvsv',0,'2025-04-22 12:20:49'),(238,10,32,'A new escalated report for Complaint #32 has been submitted by Emily Davis on Apr 22, 2025 14:20.',0,'2025-04-22 12:20:49'),(239,10,31,'Complaint #31 has been escalated to you by Academic VP Emily Davis. Reason: vcvfdvdfvdfgvsfgvvdfdf',0,'2025-04-22 12:54:02'),(240,1,31,'Update on Complaint #31: Your complaint has been escalated to the President by Academic VP Emily Davis. Reason: vcvfdvdfvdfgvsfgvvdfdf',0,'2025-04-22 12:54:02'),(241,3,31,'Update on Complaint #31 (originally handled by you): Escalated to President by Academic VP Emily Davis. Reason: vcvfdvdfvdfgvsfgvvdfdf',0,'2025-04-22 12:54:02'),(242,10,31,'A new escalated report for Complaint #31 has been submitted by Emily Davis on Apr 22, 2025 14:54.',0,'2025-04-22 12:54:02'),(243,7,1,'Complaint #1 has been escalated to you by SIMS for review. Reason: fkkdfeporopeworf',0,'2025-04-22 14:20:22'),(244,1,1,'Your Complaint (#1: Incorrect Course Registration) has been escalated to the Campus Registrar for further review.',0,'2025-04-22 14:20:22'),(245,3,1,'Complaint #1, which you escalated, has been further escalated by SIMS to the Campus Registrar. Reason: fkkdfeporopeworf',0,'2025-04-22 14:20:22');
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
INSERT INTO `reports` VALUES (1,'2025-04-15 06:00:00','weekly',3,1,2,2,1,0,10);
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
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stereotyped_reports`
--

LOCK TABLES `stereotyped_reports` WRITE;
/*!40000 ALTER TABLE `stereotyped_reports` DISABLE KEYS */;
INSERT INTO `stereotyped_reports` VALUES (1,2,3,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 2\nTitle: Payment Issue\nDescription: Overcharged for tuition fees.\nCategory: Administrative\nStatus: Resolved\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 2, 2025 09:15\nAdditional Info: Resolved by Cost Sharing','2025-04-03 11:10:00'),(2,4,3,10,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 4\nTitle: Transcript Error\nDescription: My transcript shows an incorrect grade.\nCategory: Academic\nStatus: Escalated\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 13, 2025 08:00\nAdditional Info: Escalated from SIMS to Campus Registrar','2025-04-13 06:10:00'),(3,7,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 7\nTitle: rjjrjjjjejejso\nDescription: frifjrjjfjjf\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 19, 2025 09:52\nAdditional Info: Assigned to: Sims\n','2025-04-19 06:53:17'),(4,8,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 8\nTitle: oidirwe0r\nDescription: jdjdjee\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 19, 2025 11:19\nAdditional Info: Assigned to: Sims\n','2025-04-19 08:20:37'),(5,10,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 10\nTitle: hi\nDescription: how are you password\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 19, 2025 11:58\nAdditional Info: Assigned to: Cost sharing\n','2025-04-19 08:59:26'),(6,11,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 11\nTitle: it to department head\nDescription: gnjrgrgnruiqputusfdoggdg\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 02:36\nAdditional Info: Assigned to: Department head\n','2025-04-20 23:37:55'),(7,11,12,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 11\nTitle: it to department head\nDescription: gnjrgrgnruiqputusfdoggdg\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Henry Clark\nCreated At: Apr 21, 2025 02:36\nAdditional Info: Resolved by Department Head: gooogdg\n','2025-04-20 23:39:13'),(8,9,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 9\nTitle: hi\nDescription: how are you password\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 19, 2025 11:57\nAdditional Info: Assigned to: College dean\n','2025-04-20 23:58:41'),(9,9,13,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 9\nTitle: hi\nDescription: how are you password\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Grace Taylor (College Dean)\nCreated At: Apr 19, 2025 11:57\nAdditional Info/Decision: nvnvnv\n','2025-04-21 00:03:53'),(10,12,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 12\nTitle: camera\nDescription: camera camera camera\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 03:12\nAdditional Info: Assigned to: College dean\n','2025-04-21 00:13:44'),(11,13,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 13\nTitle: at the end of a day\nDescription: what mean at the end of a day????????????????\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 03:41\nAdditional Info: Assigned to: Department head\n','2025-04-21 00:42:32'),(12,14,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 14\nTitle: i do not know\nDescription: wwwwwwwwwwwwwwwwww\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 04:00\nAdditional Info: Assigned to: College dean\n','2025-04-21 01:01:32'),(13,15,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 15\nTitle: this issue to agricultural\nDescription: gomen carrot avocado  apple\nCategory: Academic\nStatus: In_progress\nSubmitted By: nahom fekadu\nHandler: Michael Green\nCreated At: Apr 21, 2025 10:15\nAdditional Info: Assigned to: College dean\n','2025-04-21 07:22:43'),(14,16,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 16\nTitle: nanana\nDescription: ananananoononon\nCategory: Academic\nStatus: In_progress\nSubmitted By: nahom fekadu\nHandler: Michael Green\nCreated At: Apr 21, 2025 10:33\nAdditional Info: Assigned to: Department head\n','2025-04-21 07:34:03'),(15,17,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 17\nTitle: nono\nDescription: masha teppi teppi teppi\nCategory: Academic\nStatus: In_progress\nSubmitted By: nahom fekadu\nHandler: Michael Green\nCreated At: Apr 21, 2025 10:49\nAdditional Info: Assigned to: Department head\n','2025-04-21 07:50:10'),(16,18,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 18\nTitle: please please\nDescription: pppplllloooooooooooooooooooooklo\nCategory: Academic\nStatus: In_progress\nSubmitted By: nahom fekadu\nHandler: Michael Green\nCreated At: Apr 21, 2025 11:53\nAdditional Info: Assigned to: College dean\n','2025-04-21 08:55:20'),(17,18,13,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 18\nTitle: please please\nDescription: pppplllloooooooooooooooooooooklo\nCategory: Academic\nStatus: Resolved\nSubmitted By: nahom fekadu\nProcessed By: Grace Taylor (College Dean)\nCreated At: Apr 21, 2025 11:53\nAdditional Info/Decision: jfnjfjjdfjijfk\n','2025-04-21 08:57:42'),(18,19,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 19\nTitle: ante ante ante nene\nDescription: gjrnroormjgvj]dorrmr\nCategory: Academic\nStatus: In_progress\nSubmitted By: nahom fekadu\nHandler: Michael Green\nCreated At: Apr 21, 2025 12:01\nAdditional Info: Assigned to: College dean\n','2025-04-21 09:02:17'),(19,20,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 20\nTitle: hho\nDescription: hohohohohooh\nCategory: Academic\nStatus: In_progress\nSubmitted By: nahom fekadu\nHandler: Michael Green\nCreated At: Apr 21, 2025 12:04\nAdditional Info: Assigned to: College dean\n','2025-04-21 09:05:27'),(20,20,13,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 20\nTitle: hho\nDescription: hohohohohooh\nCategory: Academic\nStatus: Resolved\nSubmitted By: nahom fekadu\nProcessed By: Grace Taylor (College Dean)\nCreated At: Apr 21, 2025 12:04\nAdditional Info/Decision: jfjfjs;daoroproprkro\n','2025-04-21 09:05:58'),(21,21,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 21\nTitle: no time\nDescription: no time to it DO !!!! WE HAVE TO DO IT\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 17:18\nAdditional Info: Assigned to: Department head\n','2025-04-21 14:19:30'),(22,21,12,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 21\nTitle: no time\nDescription: no time to it DO !!!! WE HAVE TO DO IT\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Henry Clark\nCreated At: Apr 21, 2025 17:18\nAdditional Info: Resolved by Department Head: nice work\n','2025-04-21 14:22:01'),(23,22,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 22\nTitle: matter matter matter\nDescription: matter matter matter   matter matter matter  matter matter matter  matter matter matter  matter matter matter  matter matter matter  matter matter matter\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 17:38\nAdditional Info: Assigned to: Department head\n','2025-04-21 14:39:46'),(24,23,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 23\nTitle: matter\nDescription: nonononnonoononon\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 21:05\nAdditional Info: Assigned to: College dean\n','2025-04-21 18:06:28'),(25,23,13,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 23\nTitle: matter\nDescription: nonononnonoononon\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Grace Taylor (College Dean)\nCreated At: Apr 21, 2025 21:05\nAdditional Info/Decision: jfjfjfjeutig]e\n','2025-04-21 18:12:19'),(26,24,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 24\nTitle: dddd\nDescription: vvvvvvvvcvcvcv\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 21:35\nAdditional Info: Assigned to: Department head\n','2025-04-21 18:36:53'),(27,25,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 25\nTitle: uuuuuuuuuuuuuu\nDescription: abebeebebebeb\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 21, 2025 21:46\nAdditional Info: Assigned to: Department head\n','2025-04-21 18:47:23'),(28,26,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 26\nTitle: newnenenene\nDescription: abeebebebebe\nCategory: Academic\nStatus: In_progress\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 21, 2025 21:48\nAdditional Info: Assigned to: College dean\n','2025-04-21 18:48:38'),(29,27,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 27\nTitle: nonono Ethiopia\nDescription: nnnnnnnnnnnnnnnnnnnnnnnnononononononono\nCategory: Academic\nStatus: In_progress\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 21, 2025 21:49\nAdditional Info: Assigned to: College dean\n','2025-04-21 18:51:21'),(30,28,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 28\nTitle: gjgjgjgj\nDescription: jdjgvjnvnv  afkigjrojnbjbgn\nCategory: Academic\nStatus: In_progress\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 21, 2025 21:52\nAdditional Info: Assigned to: Department head\n','2025-04-21 18:53:00'),(31,28,12,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 28\nTitle: gjgjgjgj\nDescription: jdjgvjnvnv  afkigjrojnbjbgn\nCategory: Academic\nStatus: Resolved\nSubmitted By: Jane Smith\nProcessed By: Henry Clark\nCreated At: Apr 21, 2025 21:52\nAdditional Info: Resolved by Department Head: fhhhhdkkk\n','2025-04-21 18:55:26'),(32,24,9,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 24\nTitle: dddd\nDescription: vvvvvvvvcvcvcv\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Apr 21, 2025 21:35\nAdditional Info: Resolved by Academic Vice President: jvjjfjoriroorkofk\n','2025-04-21 19:17:10'),(33,29,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 29\nTitle: nvn jndjsijjvv vpn\nDescription: vpnnnnnnnn\nCategory: Academic\nStatus: In_progress\nSubmitted By: Jane Smith\nHandler: Michael Green\nCreated At: Apr 21, 2025 23:16\nAdditional Info: Assigned to: Department head\n','2025-04-21 20:17:42'),(34,30,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 30\nTitle: adega adega adega adega\nDescription: kkdkdjrovvbo04rit9epfodkl\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 22, 2025 09:50\nAdditional Info: Assigned to: Academic vp\n','2025-04-22 06:51:17'),(35,31,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 31\nTitle: gogogog\nDescription: jojojojojojo\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 22, 2025 11:00\nAdditional Info: Assigned to: Academic vp\n','2025-04-22 08:01:08'),(36,32,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 32\nTitle: please please\nDescription: fjgjjigjrei hide hide hide\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 22, 2025 11:32\nAdditional Info: Assigned to: Academic vp\n','2025-04-22 08:32:51'),(37,33,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 33\nTitle: ivjigjijigtihjdfj\nDescription: nnngnfjgkerjgjeritrtirjgnfngjnfjgn\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 22, 2025 14:01\nAdditional Info: Assigned to: Academic vp\n','2025-04-22 11:02:11'),(38,34,3,10,'assigned','Complaint Report\n----------------\nReport Type: Assigned\nComplaint ID: 34\nTitle: xdscdas\nDescription: xsdfascfecfe\nCategory: Academic\nStatus: In_progress\nSubmitted By: John Doe\nHandler: Michael Green\nCreated At: Apr 22, 2025 14:41\nAdditional Info: Assigned to: Academic vp\n','2025-04-22 11:42:11'),(39,33,9,10,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 33\nTitle: ivjigjijigtihjdfj\nDescription: nnngnfjgkerjgjeritrtirjgnfngjnfjgn\nCategory: Academic\nStatus: \nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Apr 22, 2025 14:01\nAdditional Info: Escalated by Academic Vice President Emily Davis. Reason: jfgjhjhd;kfjkgvhfjkghjvh\n','2025-04-22 11:56:34'),(40,34,9,10,'resolved','Complaint Report\n----------------\nReport Type: Resolved\nComplaint ID: 34\nTitle: xdscdas\nDescription: xsdfascfecfe\nCategory: Academic\nStatus: Resolved\nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Apr 22, 2025 14:41\nAdditional Info: Resolved by Academic Vice President: jrjhjrjkrjjrkorgogjiroeprgjjgrejgrp;gj\n','2025-04-22 12:04:55'),(41,32,9,10,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 32\nTitle: please please\nDescription: fjgjjigjrei hide hide hide\nCategory: Academic\nStatus: \nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Apr 22, 2025 11:32\nAdditional Info: Escalated by Academic Vice President: jfnjnjfnjnjfijfvkvlfkvsv\n','2025-04-22 12:20:49'),(42,31,9,10,'escalated','Complaint Report\n----------------\nReport Type: Escalated\nComplaint ID: 31\nTitle: gogogog\nDescription: jojojojojojo\nCategory: Academic\nStatus: \nSubmitted By: John Doe\nProcessed By: Emily Davis\nCreated At: Apr 22, 2025 11:00\nAdditional Info: Escalated by Academic Vice President Emily Davis. Reason: vcvfdvdfvdfgvsfgvvdfdf\n','2025-04-22 12:54:02');
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
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','handler','admin','sims','cost_sharing','campus_registrar','university_registrar','academic_vp','president','academic','department_head','college_dean') NOT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'user1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','user','John','Doe','1234567890','male','john.doe@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:00:00'),(2,'user2','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','user','Jane','Smith','9876543210','female','jane.smith@example.com','Physics','College of Science','active',NULL,'2025-04-01 05:05:00'),(3,'handler1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','handler','Michael','Green','0987654321','male','michael.green@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:10:00'),(4,'admin1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','admin','Admin','User','5555555555','other','admin@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:15:00'),(5,'sims1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','sims','Carol','Jones','7778889999','female','carol.jones@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:20:00'),(6,'costsharing1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','cost_sharing','Tom','Brown','2223334444','male','tom.brown@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:25:00'),(7,'campusreg1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','campus_registrar','Sarah','Johnson','1112223333','female','sarah.johnson@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:30:00'),(8,'univreg1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','university_registrar','David','Lee','4445556666','male','david.lee@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:35:00'),(9,'academicvp1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','academic_vp','Emily','Davis','9990001111','female','emily.davis@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:40:00'),(10,'president1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','president','Robert','Smith','1231231234','male','president@example.com',NULL,NULL,'active',NULL,'2025-04-01 05:45:00'),(11,'academic1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','academic','Eve','Wilson','6667778888','female','eve.wilson@example.com','Physics','College of Science','active',NULL,'2025-04-01 05:50:00'),(12,'depthead1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','department_head','Henry','Clark','3334445555','male','henry.clark@example.com','Computer Science','College of Technology','active',NULL,'2025-04-01 05:55:00'),(13,'collegedean1','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','college_dean','Grace','Taylor','8889990000','female','grace.taylor@example.com',NULL,'College of Technology','active',NULL,'2025-04-01 06:00:00'),(14,'depthead2','$2y$10$Pqn4KTU8RoVAFMdbSCV9IOLX/0WP1XpvKZdkGkn8d6nM2FOf.7p6O','department_head','Samuel','Aschalew','5556667777','male','samuel.aschalew@example.com','Computer Science','College of Technology','active',NULL,'2025-04-01 06:05:00'),(15,'nahom','$2y$10$lolZR4JN0pnVDNdyIzmMGuL34iF/xyeI6t99QDNV4ZWCACNgnWkY2','user','nahom','fekadu',NULL,'other','fresenay199510@gmail.com',NULL,NULL,'active',NULL,'2025-04-21 06:53:03'),(16,'henok','$2y$10$crUiqpmm8/jZBOhXuaJvvOBpiKx1qSQwCOlE4F0PHpWd3L/3DayoS','college_dean','henok','getnet','0921151599','female','henok10@gmail.com',NULL,'College of Agriculture and Natural Science','active',NULL,'2025-04-21 06:58:14'),(17,'temesgen','$2y$10$fzYDUnBvgWxXYX1FvL1.5.vTQCFLrQEruRd8QgYoi/IYyPmJdtlyC','college_dean','temesgen','adne','0988888856','male','temu1@gmail.com',NULL,'College of Social Sciences and Humanities','active',NULL,'2025-04-21 07:00:23'),(18,'seid','$2y$10$l31gVnWnIqYF82IVUZKKC.oJfqdCCGcSB4KKTopIJYo5tPMD.HDli','department_head','seid','yimam','0945454545','male','seid@gmail.com','Natural Resource Management','College of Agriculture and Natural Science','active',NULL,'2025-04-21 07:30:44');
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

-- Dump completed on 2025-04-22 19:59:07
