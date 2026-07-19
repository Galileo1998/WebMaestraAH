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
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `letter_attachments`
--

LOCK TABLES `letter_attachments` WRITE;
/*!40000 ALTER TABLE `letter_attachments` DISABLE KEYS */;
INSERT INTO `letter_attachments` VALUES (1,23,'DRAWING','uploads/draw_23_1767048195.png','2025-12-29 22:43:15',NULL),(2,23,'PHOTO','uploads/photo_23_695304039d32f.jpg','2025-12-29 22:43:15',NULL),(3,23,'PHOTO','uploads/photo_23_695304039d560.jpg','2025-12-29 22:43:15',NULL),(4,23,'PHOTO','uploads/photo_23_695304039d857.jpg','2025-12-29 22:43:15',NULL),(5,26,'DRAWING','uploads/draw_26_1767048401.png','2025-12-29 22:46:41',NULL),(6,26,'PHOTO','uploads/photo_26_695304d161012.jpg','2025-12-29 22:46:41',NULL),(7,26,'PHOTO','uploads/photo_26_695304d161690.jpg','2025-12-29 22:46:41',NULL),(8,26,'PHOTO','uploads/photo_26_695304d161f64.jpg','2025-12-29 22:46:41',NULL),(9,24,'DRAWING','uploads/draw_24_1767056543.png','2025-12-30 01:02:23',NULL),(10,24,'PHOTO','uploads/photo_24_6953249fa5048.jpg','2025-12-30 01:02:23',NULL),(11,24,'PHOTO','uploads/photo_24_6953249fa524c.jpg','2025-12-30 01:02:23',NULL),(12,24,'PHOTO','uploads/photo_24_6953249fa543b.jpg','2025-12-30 01:02:23',NULL),(13,21,'DRAWING','uploads/draw_21_1767057388.png','2025-12-30 01:16:28',NULL),(17,22,'DRAWING','uploads/draw_22_1767137409.png','2025-12-30 23:30:09',NULL),(19,22,'PHOTO','uploads/photo_22_69546081c010c.jpg','2025-12-30 23:30:09',NULL),(20,22,'PHOTO','uploads/photo_22_69546081c071b.jpg','2025-12-30 23:30:09',NULL),(21,21,'DRAWING','uploads/draw_21_1767147083.png','2025-12-31 02:11:23',NULL),(22,21,'PHOTO','uploads/photo_21_6954864b5f7bb.jpg','2025-12-31 02:11:23',NULL),(23,21,'PHOTO','uploads/photo_21_6954864b60947.jpg','2025-12-31 02:11:23',NULL),(24,21,'PHOTO','uploads/photo_21_6954864b6258d.jpg','2025-12-31 02:11:23',NULL),(25,22,'DRAWING','uploads/draw_22_1767147867.png','2025-12-31 02:24:27',NULL),(29,59,'DRAWING','uploads/draw_59_1767149150.png','2025-12-31 02:45:50',NULL),(30,59,'PHOTO','uploads/photo_59_69548e5ec38d5.jpg','2025-12-31 02:45:50',NULL),(31,59,'PHOTO','uploads/photo_59_69548e5ec5788.jpg','2025-12-31 02:45:50',NULL),(32,59,'PHOTO','uploads/photo_59_69548e5ec6f2a.jpg','2025-12-31 02:45:50',NULL),(33,45,'DRAWING','uploads/draw_45_1767624860.png','2026-01-05 14:54:20',NULL),(34,45,'PHOTO','uploads/photo_45_695bd09c0b315.jpg','2026-01-05 14:54:20',NULL),(35,45,'PHOTO','uploads/photo_45_695bd09c0b79f.jpg','2026-01-05 14:54:20',NULL),(36,45,'PHOTO','uploads/photo_45_695bd09c0bc48.jpg','2026-01-05 14:54:20',NULL),(37,22,'DRAWING','uploads/draw_22_1767626979.png','2026-01-05 15:29:39',NULL),(38,22,'PHOTO','uploads/photo_22_695bd8e3d77ef.jpg','2026-01-05 15:29:39',NULL),(39,22,'PHOTO','uploads/photo_22_695bd8e3d7af5.jpg','2026-01-05 15:29:39',NULL),(40,22,'PHOTO','uploads/photo_22_695bd8e3d7e16.jpg','2026-01-05 15:29:39',NULL),(41,36,'DRAWING','uploads/draw_36_1767631385.png','2026-01-05 16:43:05',NULL),(42,36,'PHOTO','uploads/photo_36_695bea192811a.jpg','2026-01-05 16:43:05',NULL),(43,36,'PHOTO','uploads/photo_36_695bea1928e50.jpg','2026-01-05 16:43:05',NULL),(44,36,'PHOTO','uploads/photo_36_695bea1929a32.jpg','2026-01-05 16:43:05',NULL),(45,58,'DRAWING','uploads/draw_58_1767644375.png','2026-01-05 20:19:35',NULL),(49,58,'DRAWING','uploads/draw_58_1767644556.png','2026-01-05 20:22:36',NULL),(53,58,'DRAWING','uploads/draw_58_1767646778.png','2026-01-05 20:59:38',NULL),(54,58,'PHOTO','uploads/photo_58_695c263a683fd.jpg','2026-01-05 20:59:38',NULL),(55,58,'PHOTO','uploads/photo_58_695c263a6920d.jpg','2026-01-05 20:59:38',NULL),(56,58,'PHOTO','uploads/photo_58_695c263a69cdc.jpg','2026-01-05 20:59:38',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `letters`
--

LOCK TABLES `letters` WRITE;
/*!40000 ALTER TABLE `letters` DISABLE KEYS */;
INSERT INTO `letters` VALUES (21,'L1766204565_1751_0','0042411413',NULL,'847281230','847281230','Mabelly Sarahi Henriquez Ramos','18-May-2021','F','Culguaque','847364406','ChildFund International','Sharon Wilson','COMPLETADO',2,'2025-12-30 00:39:18',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-30 20:11:23','Corregida ok','Fotografías erroneas'),(22,'L1766204565_6914_1','0042414853',NULL,'847253672','847253672','Maryeri Gabriela Funes Henriquez','27-Feb-2023','F','Ocote Hueco','847364889','ChildFund International','Jennifer Plummer','RETURNED',2,'2025-12-30 01:17:48',NULL,NULL,'2025-12-19 22:22:45','05-Jan-2026','2026-01-05 09:29:39','Hola hffhrww','El nombre del Padrino esta incorrecto'),(23,'L1766204565_2806_2','0042411416',NULL,'847285562','847285562','Adonis Orlando Ramos Martinez','09-Mar-2019','M','Ocote Hueco','847364399','ChildFund International','Becky Pastuszek','COMPLETADO',1,'2025-12-29 19:51:03',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 16:43:15','SENTIMIENTOS: \nACTIVIDADES: \nAPRENDIZAJE: \nCOMPARTIR: \nAGRADECIMIENTO:',NULL),(24,'L1766204565_7911_3','0042411216',NULL,'845691202','845691202','Erick Antonio Lopez Martinez','26-Apr-2016','M','El Naranjo','847364403','ChildFund International','Trista Naugle','COMPLETADO',2,'2025-12-30 00:39:15',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 19:02:23','Hola hol',NULL),(25,'L1766204565_7549_4','0042411417',NULL,'847285563','847285563','Alis Rosmery Servellon Hernandez','02-Nov-2020','F','Ocote Hueco','847364402','ChildFund International','Michelle Reichenbach','COMPLETADO',1,'2025-12-29 18:50:05',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 16:07:14','SENTIMIENTOS: \nACTIVIDADES: \nAPRENDIZAJE: \nCOMPARTIR: \nAGRADECIMIENTO:',NULL),(26,'L1766204565_1505_5','0042411255',NULL,'846713495','846713495','Josselin Banessa Silva Avila','02-Jun-2016','F','Ocote Hueco','847364393','ChildFund International','Jeff Heintzelman Child Welcome Letter Slip Id: 0042411413 Community Id: 4089 Child Welcome Letter Slip Id: 0042414853 Community Id: 4089 Child Welcome Letter Slip Id: 0042411416 Community Id: 4089 Date Request: 15-Dec-2025 Due Date: 04-Jan-2026 Date ','COMPLETADO',1,'2025-12-29 22:03:40',NULL,NULL,'2025-12-19 22:22:45','04-Jan-2026','2025-12-29 16:46:41','Hola h',NULL),(34,NULL,'0042428357',NULL,'847243570',NULL,'Anyeli Nahomy Funes Lopez','24-Jan-2021','F','Culguaque','847358995','ChildFund International','Bree Daly','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(35,NULL,'0042428353',NULL,'847224366',NULL,'Dennis Josafat Almendares Argueta','03-Oct-2020','M','Lepaterique Centro','3494588','ChildFund International','Linda Carr','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(36,NULL,'0042428861',NULL,'151054694',NULL,'Emerson Obed Funes Sierra','12-Dec-2013','M','Ocote Hueco','847365940','ChildFund International','Mr. Paul Becher','RETURNED',2,'2026-01-05 16:41:26',NULL,NULL,'2025-12-29 20:23:16','08-Jan-2026','2026-01-05 10:43:05','Hola hokyy','Las fotografías no cumplen con los criterios'),(37,NULL,'0042428354',NULL,'847224534',NULL,'Dayany Sofia Avila Sanchez','29-Mar-2023','F','Oropule','6081147','ChildFund International','Mr. John Cantrell','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(38,NULL,'0042428365',NULL,'847260916',NULL,'Erick Noel Funez Martinez','17-Aug-2022','M','El Naranjo','847365827','ChildFund International','Shawn Allen','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(39,NULL,'0042428358',NULL,'847244194',NULL,'Valeri Sofia Funes Martinez','01-Nov-2022','F','Ocote Hueco','847353074','ChildFund International','Mrs. Lorena Estrada','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(40,NULL,'0042428306',NULL,'846387033',NULL,'Ariela Marbella Borjas Martinez','08-Apr-2017','F','Oropule','3441752','ChildFund International','Ms. Fredra Kodama','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(41,NULL,'0042428361',NULL,'847252764',NULL,'Elcy Celena Martinez Avila','25-Dec-2023','F','Oropule','2678408','ChildFund International','Ms. Maria Gonzalez Child Welcome Letter Slip Id: 0042428357 Community Id: 4089 Child Welcome Letter Slip Id: 0042428353 Community Id: 4089 Child Welcome Letter Slip Id: 0042428861 Community Id: 4089 Child Welcome Letter Slip Id: 0042428354 Community ','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(42,NULL,'0042428362',NULL,'847252784',NULL,'Delmer Elias Martinez Martinez','11-May-2024','M','Oropule','5053446','ChildFund International','Ms. Kait Winkey','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(43,NULL,'0042428367',NULL,'847262908',NULL,'Cesia Lucia Funes Ramos','17-Jun-2021','F','Oropule','6553470','ChildFund International','Mrs. Roberta Staats','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(44,NULL,'0042428366',NULL,'847262904',NULL,'Deybi Adanil Funes Martinez','25-Jan-2020','M','Oropule','6661387','ChildFund International','Mr. Trevor Valdez','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-29 20:23:16','07-Jan-2026',NULL,NULL,NULL),(45,NULL,'0042428878',NULL,'847241786',NULL,'Dylan Isaac Verde Garcia','17-Sep-2023','M','Turturupe','847365449','ChildFund International','Mr. Michael Sneary Child Welcome Letter Slip Id: 0042428362 Community Id: 4089 Child Welcome Letter Slip Id: 0042428367 Community Id: 4089 Date Request: 18-Dec-2025 Due Date: 07-Jan-2026 Date Request: 18-Dec-2025 Due Date: 07-Jan-2026 *0042428362**00','COMPLETADO',2,'2026-01-05 14:52:36',NULL,NULL,'2025-12-29 20:23:16','08-Jan-2026','2026-01-05 08:54:20','Hola hola',NULL),(46,NULL,'0042393074',NULL,'847300462',NULL,'Meghan Bridget Castillo Castro','15-Jul-2023','F','Alubaren','847363319','ChildFund International','Karla Marshall','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(47,NULL,'0042393624',NULL,'846073154',NULL,'Katerin Nicol Lopez Ortiz','24-Dec-2017','F','Alubaren','847364142','ChildFund International','Barry Willhite','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','01-Jan-2026',NULL,NULL,NULL),(48,NULL,'0042393626',NULL,'846106943',NULL,'Eliana Monserrath Sabillon Velasquez','12-Sep-2017','F','El Chaparral','847364141','ChildFund International','Deborah Beasley','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','01-Jan-2026',NULL,NULL,NULL),(49,NULL,'0042393072',NULL,'847299996',NULL,'Sherlin Kaylani Barahona Funes','26-Mar-2024','F','El Llano','2872175','ChildFund International','Mr. John Lafontaine','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(50,NULL,'0042393073',NULL,'847300451',NULL,'Henri Janier Isidro Dias','20-Feb-2023','M','Alubaren','3071450','ChildFund International','Mr. John Dejong','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(51,NULL,'0042393038',NULL,'151053613',NULL,'Orlin Jesus Cruz Sanchez','28-Oct-2015','M','Alubaren','3073471','Taiwan Fund for Children and Families (TFCF)','Ms. Wan Hsin Wen','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(52,NULL,'0042393067',NULL,'847291538',NULL,'Iker Yoel Oliva Flores','07-Oct-2023','M','El Llano','3236327','ChildFund International','Mr. Lenny Nelson Child Welcome Letter Slip Id: 0042393074 Community Id: 2607 Child Welcome Letter Slip Id: 0042393624 Community Id: 2607 Child Welcome Letter Slip Id: 0042393626 Community Id: 2607 Child Welcome Letter Slip Id: 0042393072 Community Id','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(53,NULL,'0042393698',NULL,'847267106',NULL,'Cecia Nohemy Funez Cerrato','11-Jan-2022','F','Los Tablones','847364163','ChildFund International','Julia Kruger','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','01-Jan-2026',NULL,NULL,NULL),(54,NULL,'0042393070',NULL,'847297559',NULL,'William Jhoniel Bonilla Flores','10-Aug-2022','M','Malagua','1293439','ChildFund International','Miss Ann Davis Cannon','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(55,NULL,'0042393667',NULL,'847194175',NULL,'Alice Rebeca Ruiz Reyes','04-Jan-2020','F','San Marcos','847364154','ChildFund International','Mark Jeffris','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','01-Jan-2026',NULL,NULL,NULL),(56,NULL,'0042393006',NULL,'847210606',NULL,'Erlin Yariel Perez Perez','16-Feb-2018','M','Emituca','847363300','ChildFund International','Caleb and Rita Cicalo','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(57,NULL,'0042392985',NULL,'847145111',NULL,'Jhosep Aaron Medina Funez','09-Nov-2016','M','Los Tablones','1234841','ChildFund International','Charles and Norma Kamar','ASSIGNED',NULL,NULL,NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025',NULL,NULL,NULL),(58,NULL,'0042393075',NULL,'847303509',NULL,'Joseth Fernando Aguero Valladares','28-Dec-2019','M','Reitoca','1237976','ChildFund International','Mr. Albert Malone Jr.','COMPLETADO',1,'2025-12-31 02:13:39',NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025','2026-01-05 14:59:38','Querido padrino quiero decirle que usted es el mejor padrino del mundo. Es el mejor padrino que he tenido, no lo cambiaría por nadie.\n','Hay una fotografía borrosa, favor corregir.'),(59,NULL,'0042393060',NULL,'847268625',NULL,'Hexcer Enoc Castillo Alvarado','08-Nov-2023','M','San Marcos','1427203','ChildFund International','Ms. Mary Almas Child Welcome Letter Slip Id: 0042393698 Community Id: 2607 Child Welcome Letter Slip Id: 0042393070 Community Id: 2607 Child Welcome Letter Slip Id: 0042393667 Community Id: 2607 Date Request: 12-Dec-2025 Due Date: 01-Jan-2026 Date Re','COMPLETADO',2,'2025-12-31 02:04:18',NULL,NULL,'2025-12-30 17:20:21','31-Dec-2025','2025-12-30 20:45:50','Hoy tyii8',NULL),(61,NULL,'0042434109',NULL,'847270053',NULL,'Maria Carolina Escalante Martinez','10-Sep-2020','F','Culguaque','847367189','ChildFund International','Andrea Plocher','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','11-Jan-2026',NULL,NULL,NULL),(62,NULL,'0042434067',NULL,'847224266',NULL,'Keily Nahomy Servellon Flores','30-Oct-2020','F','Lepaterique Centro','847366505','ChildFund International','Lynn Wickstra','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','11-Jan-2026',NULL,NULL,NULL),(63,NULL,'0042441020',NULL,'845808116',NULL,'Gimena Abigail Hernandez Acosta','03-Jan-2019','F','Ocote Hueco','847287159','ChildFund International','Mr Keith Davis','ASSIGNED',1,'2026-01-05 22:32:10',NULL,NULL,'2026-01-05 10:45:46','15-Jan-2026',NULL,NULL,NULL),(64,NULL,'0042434034',NULL,'847213258',NULL,'Keila Dariany Funes Funes','17-Feb-2021','F','Oropule','847366503','ChildFund International','Kirk and Amy Schultz','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','11-Jan-2026',NULL,NULL,NULL),(65,NULL,'0042435731',NULL,'845795693',NULL,'Maryam Ariana Funes Zuniga','19-Jul-2015','F','Culguaque','847366456','ChildFund International','Cheryl Woods','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','12-Jan-2026',NULL,NULL,NULL),(66,NULL,'0042434100',NULL,'847260152',NULL,'Ever Enmanuel Martinez Funes','29-Jan-2022','M','Estancia','847366347','ChildFund International','Katharyn Bracewell','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','11-Jan-2026',NULL,NULL,NULL),(67,NULL,'0042435992',NULL,'847285569',NULL,'Emely Celeste Soza Garcia','17-Apr-2024','F','Ocote Hueco','847366433','ChildFund International','Ms. Pamela Schwald','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','12-Jan-2026',NULL,NULL,NULL),(68,NULL,'0042435732',NULL,'845802936',NULL,'Emely Dariana Soza Funes','13-Nov-2013','F','Ocote hueco','847367034','ChildFund International','Ms. Alex Tharp Child Welcome Letter Slip Id: 0042434109 Community Id: 4089 Child Welcome Letter Slip Id: 0042434067 Community Id: 4089 Child Welcome Letter Slip Id: 0042441020 Community Id: 4089 Child Welcome Letter Slip Id: 0042434034 Community Id: ','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','12-Jan-2026',NULL,NULL,NULL),(69,NULL,'0042436002',NULL,'847304315',NULL,'Ander Enrique Verde Alvarado','25-May-2024','M','Turturupe','847366335','ChildFund International','Mr. George S. Macias','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','12-Jan-2026',NULL,NULL,NULL),(70,NULL,'0042435953',NULL,'847241815',NULL,'Genesis Suyapa Funes Funes','14-Apr-2021','F','Turturupe','847367450','ChildFund International','Mr. Cameron Kynard Child Welcome Letter Slip Id: 0042436002 Community Id: 4089 Date Request: 23-Dec-2025 Due Date: 12-Jan-2026 *0042436002* Child Welcome Letter Slip Id: 0042435953 Community Id: 4089 Date Request: 23-Dec-2025 Due Date: 12-Jan-2026 *0','ASSIGNED',NULL,NULL,NULL,NULL,'2026-01-05 10:45:46','12-Jan-2026',NULL,NULL,NULL);
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

-- Dump completed on 2026-01-06  9:08:19
