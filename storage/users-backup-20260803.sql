-- MySQL dump 10.13  Distrib 8.0.45, for Linux (x86_64)
--
-- Host: localhost    Database: crawler
-- ------------------------------------------------------
-- Server version	8.0.45

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `profile_prompt` text COLLATE utf8mb4_unicode_ci,
  `escalation_ladder` tinyint unsigned DEFAULT NULL,
  `fcm_token` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_schedule_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `ai_window_start` time DEFAULT NULL,
  `ai_window_end` time DEFAULT NULL,
  `ai_timezone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_manual_state` tinyint(1) DEFAULT NULL,
  `ai_manual_until` timestamp NULL DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_escalation_ladder_unique` (`escalation_ladder`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Dr. Jarret Ratke','hoeger.lora@example.net','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','a3FqjZeT18','2023-05-17 11:42:13','2023-05-17 11:42:13'),(2,'Ms. Name Feest','ekessler@example.org','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ff9Rqg03UL','2023-05-17 11:42:13','2023-05-17 11:42:13'),(3,'Lloyd Hand','tnader@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','1W4QCGAJMe','2023-05-17 11:42:13','2023-05-17 11:42:13'),(4,'Bryce Kuhic','rkub@example.net','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','SsTgRj2OJn','2023-05-17 11:42:13','2023-05-17 11:42:13'),(5,'Columbus Toy','caterina.gibson@example.net','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Wc1JrulyuG','2023-05-17 11:42:13','2023-05-17 11:42:13'),(6,'Dixie Hirthe IV','elbert94@example.org','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','9gt3cFsPdG','2023-05-17 11:42:13','2023-05-17 11:42:13'),(7,'Paige Abernathy','qkoepp@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','p4Fk2gvFnp','2023-05-17 11:42:13','2023-05-17 11:42:13'),(8,'Delilah Stanton','aglae09@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','f0pE8FptCb','2023-05-17 11:42:13','2023-05-17 11:42:13'),(9,'Lilliana Dietrich Jr.','donnell40@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','nLYKYRZk05','2023-05-17 11:42:13','2023-05-17 11:42:13'),(10,'Domenick Wiza DVM','gussie.schuppe@example.org','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:12','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','QXYv6GwkaQ','2023-05-17 11:42:13','2023-05-17 11:42:13'),(11,'Test User','test@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:42:13','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','04R2ECFBkK','2023-05-17 11:42:13','2023-05-17 11:42:13'),(12,'Aubree Hermiston','ayla38@example.org','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','kbsgiB4kDH','2023-05-17 11:46:36','2023-05-17 11:46:36'),(13,'Destiney Mosciski','karelle20@example.net','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','i2wQoWGBrm','2023-05-17 11:46:36','2023-05-17 11:46:36'),(14,'Dr. Uriel Legros PhD','angelita09@example.net','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','ErQgh3c13s','2023-05-17 11:46:36','2023-05-17 11:46:36'),(15,'Lenore Klocko DDS','karson28@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','WVdtYRh4Ai','2023-05-17 11:46:36','2023-05-17 11:46:36'),(16,'Katlynn O\'Keefe MD','bridgette33@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','Xh727hHbv3','2023-05-17 11:46:36','2023-05-17 11:46:36'),(17,'Palma Koch','bergstrom.brandyn@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','RogkA0ooAh','2023-05-17 11:46:36','2023-05-17 11:46:36'),(18,'Loy Hill III','denesik.hazel@example.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','OkKzoReZxB','2023-05-17 11:46:36','2023-05-17 11:46:36'),(19,'Tyshawn Marquardt','heather.koepp@example.net','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','MOA23NwuRI','2023-05-17 11:46:36','2023-05-17 11:46:36'),(20,'Alvena Abernathy IV','boyle.joelle@example.net','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','rBKnUfbqAj','2023-05-17 11:46:36','2023-05-17 11:46:36'),(21,'Adela Zboncak','ellen.volkman@example.org','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,'2023-05-17 11:46:36','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','1EAf0KuPRe','2023-05-17 11:46:36','2023-05-17 11:46:36'),(23,'Crawler Admin','admin@crawler.com','admin',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,'$2y$10$5Pkt0IU2Slp5Bw1VmyotwO1hz.mP892z9U2SIyGj18qEDeBt41gZG',NULL,'2023-05-18 08:37:05','2023-05-18 08:37:05'),(24,'Team User','team@crawler.com','team',NULL,NULL,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,'$2y$10$OPcCfgJJ39ZA/nmPSf5GFujjL1VCW/0bS5SjUTqmyxK0lacsDl7Lm',NULL,'2026-07-15 07:42:02','2026-07-15 07:42:02'),(25,'Umer Malik','umer@wolfiz.com','mobile','Mobile App projects',1,'eEDv5p2Y20tuh-uiLfNxKs:APA91bH1JgyqZ1hW1n-J5RoCWu7IeusCrF9tg-YsB7AwINsFrvPHfPme0xOxEOpBuzKJKaLs3LTdZ-gzRel61eCo33_8XZ6XmpxDTTad6ArgfdGDKg_HwYs',0,'09:00:00','18:00:00','Asia/Karachi',0,NULL,NULL,'$2y$10$IM6GzFMxF/u3NADf4GnqZe./g8DBMgVpd34c6FtWZyh73mk6QNgim',NULL,'2026-07-21 17:26:41','2026-08-03 03:57:34'),(26,'Demo Account','demo@wolfiz.com','mobile','Assign any project which have any skill assign to this person',2,NULL,0,NULL,NULL,NULL,NULL,NULL,NULL,'$2y$10$AsMMDHe9KgN/6fnPv9cBGeIvG7lkWITbct7WKFIwa/wnIb6bBZcEW',NULL,'2026-07-22 10:07:37','2026-07-22 10:52:42');
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

-- Dump completed on 2026-08-03  4:34:34
