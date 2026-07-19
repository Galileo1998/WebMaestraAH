-- MySQL dump 10.13  Distrib 8.0.43, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: magic_letters_db
-- ------------------------------------------------------
-- Server version	8.0.43

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `drawings`
--

DROP TABLE IF EXISTS `drawings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `drawings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `letter_id` varchar(50) NOT NULL,
  `svg_xml` longtext,
  PRIMARY KEY (`id`),
  UNIQUE KEY `letter_id` (`letter_id`),
  CONSTRAINT `drawings_ibfk_1` FOREIGN KEY (`letter_id`) REFERENCES `letters` (`local_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `drawings`
--

LOCK TABLES `drawings` WRITE;
/*!40000 ALTER TABLE `drawings` DISABLE KEYS */;
/*!40000 ALTER TABLE `drawings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `letter_attachments`
--

DROP TABLE IF EXISTS `letter_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `letter_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `letter_id` int NOT NULL,
  `file_type` enum('PHOTO','DRAWING') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `return_reason` text,
  PRIMARY KEY (`id`),
  KEY `letter_id` (`letter_id`),
  CONSTRAINT `letter_attachments_ibfk_1` FOREIGN KEY (`letter_id`) REFERENCES `letters` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `letter_attachments`
--

LOCK TABLES `letter_attachments` WRITE;
/*!40000 ALTER TABLE `letter_attachments` DISABLE KEYS */;
INSERT INTO `letter_attachments` VALUES (1,23,'DRAWING','uploads/draw_23_1767048195.png','2025-12-29 22:43:15',NULL),(2,23,'PHOTO','uploads/photo_23_695304039d32f.jpg','2025-12-29 22:43:15',NULL),(3,23,'PHOTO','uploads/photo_23_695304039d560.jpg','2025-12-29 22:43:15',NULL),(4,23,'PHOTO','uploads/photo_23_695304039d857.jpg','2025-12-29 22:43:15',NULL),(5,26,'DRAWING','uploads/draw_26_1767048401.png','2025-12-29 22:46:41',NULL),(6,26,'PHOTO','uploads/photo_26_695304d161012.jpg','2025-12-29 22:46:41',NULL),(7,26,'PHOTO','uploads/photo_26_695304d161690.jpg','2025-12-29 22:46:41',NULL),(8,26,'PHOTO','uploads/photo_26_695304d161f64.jpg','2025-12-29 22:46:41',NULL),(9,24,'DRAWING','uploads/draw_24_1767056543.png','2025-12-30 01:02:23',NULL),(10,24,'PHOTO','uploads/photo_24_6953249fa5048.jpg','2025-12-30 01:02:23',NULL),(11,24,'PHOTO','uploads/photo_24_6953249fa524c.jpg','2025-12-30 01:02:23',NULL),(12,24,'PHOTO','uploads/photo_24_6953249fa543b.jpg','2025-12-30 01:02:23',NULL),(13,21,'DRAWING','uploads/draw_21_1767057388.png','2025-12-30 01:16:28',NULL),(14,21,'PHOTO','uploads/photo_21_695327ec2e132.jpg','2025-12-30 01:16:28',NULL),(15,21,'PHOTO','uploads/photo_21_695327ec2e5aa.jpg','2025-12-30 01:16:28',NULL),(16,21,'PHOTO','uploads/photo_21_695327ec2e9ee.jpg','2025-12-30 01:16:28',NULL);
/*!40000 ALTER TABLE `letter_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `letters`
--

DROP TABLE IF EXISTS `letters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `letters` (
  `id` int NOT NULL AUTO_INCREMENT,
  `local_id` varchar(50) DEFAULT NULL,
  `slip_id` varchar(50) DEFAULT NULL,
  `community_id` varchar(50) DEFAULT NULL,
  `child_code` varchar(50) NOT NULL,
  `child_nbr` varchar(100) DEFAULT NULL,
  `child_name` varchar(255) DEFAULT NULL,
  `birthdate` varchar(20) DEFAULT NULL,
  `sex` char(1) DEFAULT NULL,
  `village` varchar(255) DEFAULT NULL,
  `contact_id` varchar(300) DEFAULT NULL,
  `ia_id` varchar(300) DEFAULT NULL,
  `contact_name` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'ASSIGNED',
  `tech_id` int DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `assigned_to_user_id` int DEFAULT NULL,
  `text_feelings` text,
  `created_at` datetime NOT NULL,
  `due_date` varchar(20) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `final_message` text,
  `return_reason` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `local_id` (`local_id`),
  KEY `assigned_to_user_id` (`assigned_to_user_id`),
  CONSTRAINT `letters_ibfk_1` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `letters`
--

LOCK TABLES `letters` WRITE;
/*!40000 ALTER TABLE `letters` DISABLE KEYS */;
INSERT INTO `letters` VALUES (21,'L1766204565_1751_0','0042411413',NULL,'847281230','847281230','Mabelly Sarahi Henriquez Ramos','18-May-2021','F','Culguaque','847364406','ChildFund International','Sharon Wilson','COMPLETADO',2,'2025-12-30 00:39:18',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 19:16:28','Hola hola',NULL),(22,'L1766204565_6914_1','0042414853',NULL,'847253672','847253672','Maryeri Gabriela Funes Henriquez','27-Feb-2023','F','Ocote Hueco','847364889','ChildFund International','Jennifer Plummer','PENDIENTE',2,'2025-12-30 01:17:48',NULL,NULL,'2025-12-19 22:22:45','05-Jan-2026',NULL,NULL,NULL),(23,'L1766204565_2806_2','0042411416',NULL,'847285562','847285562','Adonis Orlando Ramos Martinez','09-Mar-2019','M','Ocote Hueco','847364399','ChildFund International','Becky Pastuszek','COMPLETADO',1,'2025-12-29 19:51:03',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 16:43:15','SENTIMIENTOS: \nACTIVIDADES: \nAPRENDIZAJE: \nCOMPARTIR: \nAGRADECIMIENTO:',NULL),(24,'L1766204565_7911_3','0042411216',NULL,'845691202','845691202','Erick Antonio Lopez Martinez','26-Apr-2016','M','El Naranjo','847364403','ChildFund International','Trista Naugle','COMPLETADO',2,'2025-12-30 00:39:15',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 19:02:23','Hola hol',NULL),(25,'L1766204565_7549_4','0042411417',NULL,'847285563','847285563','Alis Rosmery Servellon Hernandez','02-Nov-2020','F','Ocote Hueco','847364402','ChildFund International','Michelle Reichenbach','COMPLETADO',1,'2025-12-29 18:50:05',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 16:07:14','SENTIMIENTOS: \nACTIVIDADES: \nAPRENDIZAJE: \nCOMPARTIR: \nAGRADECIMIENTO:',NULL),(26,'L1766204565_1505_5','0042411255',NULL,'846713495','846713495','Josselin Banessa Silva Avila','02-Jun-2016','F','Ocote Hueco','847364393','ChildFund International','Jeff Heintzelman Child Welcome Letter Slip Id: 0042411413 Community Id: 4089 Child Welcome Letter Slip Id: 0042414853 Community Id: 4089 Child Welcome Letter Slip Id: 0042411416 Community Id: 4089 Date Request: 15-Dec-2025 Due Date: 04-Jan-2026 Date ','COMPLETADO',1,'2025-12-29 22:03:40',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 16:46:41','Hola h',NULL),(34,NULL,'0042428357',NULL,'847243570',NULL,'Anyeli Nahomy Funes Lopez','24-Jan-2021','F','Culguaque','847358995','ChildFund International','Bree Daly','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(35,NULL,'0042428353',NULL,'847224366',NULL,'Dennis Josafat Almendares Argueta','03-Oct-2020','M','Lepaterique Centro','3494588','ChildFund International','Linda Carr','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(36,NULL,'0042428861',NULL,'151054694',NULL,'Emerson Obed Funes Sierra','12-Dec-2013','M','Ocote Hueco','847365940','ChildFund International','Mr. Paul Becher','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','08-Jan-2026',NULL,NULL,NULL),(37,NULL,'0042428354',NULL,'847224534',NULL,'Dayany Sofia Avila Sanchez','29-Mar-2023','F','Oropule','6081147','ChildFund International','Mr. John Cantrell','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(38,NULL,'0042428365',NULL,'847260916',NULL,'Erick Noel Funez Martinez','17-Aug-2022','M','El Naranjo','847365827','ChildFund International','Shawn Allen','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(39,NULL,'0042428358',NULL,'847244194',NULL,'Valeri Sofia Funes Martinez','01-Nov-2022','F','Ocote Hueco','847353074','ChildFund International','Mrs. Lorena Estrada','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(40,NULL,'0042428306',NULL,'846387033',NULL,'Ariela Marbella Borjas Martinez','08-Apr-2017','F','Oropule','3441752','ChildFund International','Ms. Fredra Kodama','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(41,NULL,'0042428361',NULL,'847252764',NULL,'Elcy Celena Martinez Avila','25-Dec-2023','F','Oropule','2678408','ChildFund International','Ms. Maria Gonzalez Child Welcome Letter Slip Id: 0042428357 Community Id: 4089 Child Welcome Letter Slip Id: 0042428353 Community Id: 4089 Child Welcome Letter Slip Id: 0042428861 Community Id: 4089 Child Welcome Letter Slip Id: 0042428354 Community ','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(42,NULL,'0042428362',NULL,'847252784',NULL,'Delmer Elias Martinez Martinez','11-May-2024','M','Oropule','5053446','ChildFund International','Ms. Kait Winkey','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(43,NULL,'0042428367',NULL,'847262908',NULL,'Cesia Lucia Funes Ramos','17-Jun-2021','F','Oropule','6553470','ChildFund International','Mrs. Roberta Staats','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(44,NULL,'0042428366',NULL,'847262904',NULL,'Deybi Adanil Funes Martinez','25-Jan-2020','M','Oropule','6661387','ChildFund International','Mr. Trevor Valdez','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(45,NULL,'0042428878',NULL,'847241786',NULL,'Dylan Isaac Verde Garcia','17-Sep-2023','M','Turturupe','847365449','ChildFund International','Mr. Michael Sneary Child Welcome Letter Slip Id: 0042428362 Community Id: 4089 Child Welcome Letter Slip Id: 0042428367 Community Id: 4089 Date Request: 18-Dec-2025 Due Date: 07-Jan-2026 Date Request: 18-Dec-2025 Due Date: 07-Jan-2026 *0042428362**00','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','08-Jan-2026',NULL,NULL,NULL);
/*!40000 ALTER TABLE `letters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `photos`
--

DROP TABLE IF EXISTS `photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `photos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `letter_id` varchar(50) NOT NULL,
  `photo_url` text NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `letter_id` (`letter_id`),
  CONSTRAINT `photos_ibfk_1` FOREIGN KEY (`letter_id`) REFERENCES `letters` (`local_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `photos`
--

LOCK TABLES `photos` WRITE;
/*!40000 ALTER TABLE `photos` DISABLE KEYS */;
/*!40000 ALTER TABLE `photos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `technicians`
--

DROP TABLE IF EXISTS `technicians`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `technicians` (
  `id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `community_assigned` varchar(100) DEFAULT NULL,
  `status` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `technicians`
--

LOCK TABLES `technicians` WRITE;
/*!40000 ALTER TABLE `technicians` DISABLE KEYS */;
INSERT INTO `technicians` VALUES (1,'Norma Hernández','123456','Ocote Hueco','ACTIVO','2025-12-20 04:42:00'),(2,'David Ramos','1234567','Oropule','ACTIVO','2025-12-29 20:20:21');
/*!40000 ALTER TABLE `technicians` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `rol` enum('ADMIN','TECNICO') DEFAULT 'TECNICO',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'Tecnico de Prueba','tecnico@test.com','123456','TECNICO'),(2,'Admnistrador','admin@test.com','123456','ADMIN');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-12-29 20:24:47
