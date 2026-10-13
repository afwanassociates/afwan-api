-- MySQL dump 10.13  Distrib 8.0.46, for Linux (x86_64)
--
-- Host: localhost    Database: afwan_api
-- ------------------------------------------------------
-- Server version	8.0.46

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
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `app_settings_key_unique` (`key`),
  KEY `app_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `app_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_settings`
--

LOCK TABLES `app_settings` WRITE;
/*!40000 ALTER TABLE `app_settings` DISABLE KEYS */;
INSERT INTO `app_settings` VALUES (1,'default_company_country_code','MY',NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),(2,'default_passport_country_code','MY',2,'2026-10-05 12:43:36','2026-10-10 20:02:30');
/*!40000 ALTER TABLE `app_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('laravel-cache-app_settings','a:2:{s:28:\"default_company_country_code\";s:2:\"MY\";s:29:\"default_passport_country_code\";s:2:\"MY\";}',2107059468),('laravel-cache-ataentry@afwan.test|172.18.0.1','i:1;',1791027478),('laravel-cache-ataentry@afwan.test|172.18.0.1:timer','i:1791027478;',1791027478),('laravel-cache-throttle-1790849634@afwan.test|192.168.65.1','i:5;',1790849699),('laravel-cache-throttle-1790849634@afwan.test|192.168.65.1:timer','i:1790849699;',1790849699);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
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
-- Table structure for table `companies`
--

DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `companies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `agent_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bd_agency_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quota` int unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `companies_name_unique` (`name`),
  KEY `companies_created_by_foreign` (`created_by`),
  KEY `companies_country_code_index` (`country_code`),
  CONSTRAINT `companies_country_code_foreign` FOREIGN KEY (`country_code`) REFERENCES `countries` (`code`) ON DELETE RESTRICT,
  CONSTRAINT `companies_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `companies`
--

LOCK TABLES `companies` WRITE;
/*!40000 ALTER TABLE `companies` DISABLE KEYS */;
INSERT INTO `companies` VALUES (1,'Desert Rose Construction','MY',NULL,NULL,NULL,NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(2,'Gulf Star Catering','MY',NULL,NULL,NULL,NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(3,'Pearl Coast Facilities','MY',NULL,NULL,NULL,NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(4,'Blue Dune Logistics','MY',NULL,NULL,NULL,NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(5,'Crescent Hospitality','MY',NULL,NULL,NULL,NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(6,'sen soon','MY',NULL,NULL,NULL,NULL,NULL,1,3,'2026-10-11 07:20:41','2026-10-11 07:20:41',NULL),(7,'mydin','MY',NULL,NULL,NULL,NULL,NULL,1,1,'2026-10-12 10:12:27','2026-10-12 10:12:27',NULL);
/*!40000 ALTER TABLE `companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `countries`
--

DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `code` char(2) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint unsigned NOT NULL DEFAULT '0',
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`code`),
  UNIQUE KEY `countries_name_unique` (`name`),
  KEY `countries_updated_by_foreign` (`updated_by`),
  CONSTRAINT `countries_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `countries`
--

LOCK TABLES `countries` WRITE;
/*!40000 ALTER TABLE `countries` DISABLE KEYS */;
INSERT INTO `countries` VALUES ('AD','Andorra',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AE','United Arab Emirates',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AF','Afghanistan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AG','Antigua and Barbuda',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AI','Anguilla',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AL','Albania',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AM','Armenia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AO','Angola',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AQ','Antarctica',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AR','Argentina',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AS','American Samoa',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AT','Austria',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AU','Australia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AW','Aruba',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AX','Åland Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('AZ','Azerbaijan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BA','Bosnia and Herzegovina',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BB','Barbados',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BD','Bangladesh',1,1,1,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BE','Belgium',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BF','Burkina Faso',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BG','Bulgaria',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BH','Bahrain',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BI','Burundi',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BJ','Benin',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BL','Saint Barthélemy',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BM','Bermuda',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BN','Brunei',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BO','Bolivia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BQ','Caribbean Netherlands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BR','Brazil',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BS','Bahamas',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BT','Bhutan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BV','Bouvet Island',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BW','Botswana',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BY','Belarus',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('BZ','Belize',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CA','Canada',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CC','Cocos (Keeling) Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CD','Congo (Democratic Republic)',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CF','Central African Republic',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CG','Congo',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CH','Switzerland',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CI','Côte d\'Ivoire',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CK','Cook Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CL','Chile',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CM','Cameroon',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CN','China',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CO','Colombia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CR','Costa Rica',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CU','Cuba',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CV','Cabo Verde',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CW','Curaçao',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CX','Christmas Island',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CY','Cyprus',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('CZ','Czechia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('DE','Germany',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('DJ','Djibouti',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('DK','Denmark',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('DM','Dominica',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('DO','Dominican Republic',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('DZ','Algeria',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('EC','Ecuador',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('EE','Estonia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('EG','Egypt',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('EH','Western Sahara',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ER','Eritrea',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ES','Spain',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ET','Ethiopia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('FI','Finland',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('FJ','Fiji',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('FK','Falkland Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('FM','Micronesia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('FO','Faroe Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('FR','France',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GA','Gabon',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GB','United Kingdom',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GD','Grenada',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GE','Georgia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GF','French Guiana',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GG','Guernsey',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GH','Ghana',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GI','Gibraltar',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GL','Greenland',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GM','Gambia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GN','Guinea',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GP','Guadeloupe',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GQ','Equatorial Guinea',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GR','Greece',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GS','South Georgia and the South Sandwich Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GT','Guatemala',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GU','Guam',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GW','Guinea-Bissau',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('GY','Guyana',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('HK','Hong Kong',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('HM','Heard Island and McDonald Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('HN','Honduras',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('HR','Croatia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('HT','Haiti',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('HU','Hungary',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ID','Indonesia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IE','Ireland',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IL','Israel',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IM','Isle of Man',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IN','India',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IO','British Indian Ocean Territory',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IQ','Iraq',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IR','Iran',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IS','Iceland',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('IT','Italy',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('JE','Jersey',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('JM','Jamaica',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('JO','Jordan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('JP','Japan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KE','Kenya',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KG','Kyrgyzstan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KH','Cambodia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KI','Kiribati',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KM','Comoros',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KN','Saint Kitts and Nevis',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KP','North Korea',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KR','South Korea',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KW','Kuwait',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KY','Cayman Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('KZ','Kazakhstan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LA','Laos',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LB','Lebanon',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LC','Saint Lucia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LI','Liechtenstein',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LK','Sri Lanka',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LR','Liberia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LS','Lesotho',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LT','Lithuania',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LU','Luxembourg',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LV','Latvia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('LY','Libya',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MA','Morocco',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MC','Monaco',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MD','Moldova',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ME','Montenegro',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MF','Saint Martin (French part)',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MG','Madagascar',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MH','Marshall Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MK','North Macedonia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ML','Mali',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MM','Myanmar',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MN','Mongolia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MO','Macao',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MP','Northern Mariana Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MQ','Martinique',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MR','Mauritania',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MS','Montserrat',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MT','Malta',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MU','Mauritius',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MV','Maldives',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MW','Malawi',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MX','Mexico',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('MY','Malaysia',1,1,2,NULL,'2026-10-05 12:43:36','2026-10-10 20:02:30'),('MZ','Mozambique',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NA','Namibia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NC','New Caledonia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NE','Niger',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NF','Norfolk Island',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NG','Nigeria',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NI','Nicaragua',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NL','Netherlands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NO','Norway',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NP','Nepal',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NR','Nauru',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NU','Niue',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('NZ','New Zealand',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('OM','Oman',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PA','Panama',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PE','Peru',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PF','French Polynesia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PG','Papua New Guinea',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PH','Philippines',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PK','Pakistan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PL','Poland',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PM','Saint Pierre and Miquelon',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PN','Pitcairn Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PR','Puerto Rico',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PS','Palestine',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PT','Portugal',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PW','Palau',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('PY','Paraguay',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('QA','Qatar',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('RE','Réunion',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('RO','Romania',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('RS','Serbia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('RU','Russia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('RW','Rwanda',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SA','Saudi Arabia',1,1,0,1,'2026-10-05 12:43:36','2026-10-07 07:42:28'),('SB','Solomon Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SC','Seychelles',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SD','Sudan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SE','Sweden',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SG','Singapore',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SH','Saint Helena, Ascension and Tristan da Cunha',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SI','Slovenia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SJ','Svalbard and Jan Mayen',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SK','Slovakia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SL','Sierra Leone',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SM','San Marino',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SN','Senegal',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SO','Somalia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SR','Suriname',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SS','South Sudan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ST','São Tomé and Príncipe',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SV','El Salvador',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SX','Sint Maarten (Dutch part)',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SY','Syria',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('SZ','Eswatini',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TC','Turks and Caicos Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TD','Chad',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TF','French Southern Territories',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TG','Togo',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TH','Thailand',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TJ','Tajikistan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TK','Tokelau',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TL','Timor-Leste',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TM','Turkmenistan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TN','Tunisia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TO','Tonga',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TR','Türkiye',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TT','Trinidad and Tobago',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TV','Tuvalu',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TW','Taiwan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('TZ','Tanzania',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('UA','Ukraine',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('UG','Uganda',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('UM','United States Minor Outlying Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('US','United States',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('UY','Uruguay',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('UZ','Uzbekistan',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('VA','Holy See',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('VC','Saint Vincent and the Grenadines',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('VE','Venezuela',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('VG','British Virgin Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('VI','United States Virgin Islands',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('VN','Vietnam',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('VU','Vanuatu',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('WF','Wallis and Futuna',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('WS','Samoa',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('XK','Kosovo',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('YE','Yemen',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('YT','Mayotte',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ZA','South Africa',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ZM','Zambia',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36'),('ZW','Zimbabwe',1,0,0,NULL,'2026-10-05 12:43:36','2026-10-05 12:43:36');
/*!40000 ALTER TABLE `countries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
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
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
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
-- Table structure for table `medical_centers`
--

DROP TABLE IF EXISTS `medical_centers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `medical_centers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `medical_centers_name_unique` (`name`),
  KEY `medical_centers_created_by_foreign` (`created_by`),
  CONSTRAINT `medical_centers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medical_centers`
--

LOCK TABLES `medical_centers` WRITE;
/*!40000 ALTER TABLE `medical_centers` DISABLE KEYS */;
INSERT INTO `medical_centers` VALUES (1,'SKN Health Services',1,NULL,'2026-10-09 20:19:12','2026-10-09 20:19:12',NULL),(2,'Meem Medical Center',1,NULL,'2026-10-09 20:19:12','2026-10-09 20:19:12',NULL),(3,'Catharsis Medical Center',1,NULL,'2026-10-09 20:19:12','2026-10-09 20:19:12',NULL),(4,'Zain Medical Center',1,NULL,'2026-10-09 20:19:12','2026-10-09 20:19:12',NULL);
/*!40000 ALTER TABLE `medical_centers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `medical_records`
--

DROP TABLE IF EXISTS `medical_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `medical_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `passport_entry_id` bigint unsigned NOT NULL,
  `medical_date` date NOT NULL,
  `issue_date` date DEFAULT NULL,
  `result` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valid_until` date DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned NOT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `medical_records_created_by_foreign` (`created_by`),
  KEY `medical_records_updated_by_foreign` (`updated_by`),
  KEY `medical_records_deleted_by_foreign` (`deleted_by`),
  KEY `medical_records_passport_entry_id_medical_date_id_index` (`passport_entry_id`,`medical_date`,`id`),
  KEY `medical_records_medical_date_index` (`medical_date`),
  KEY `medical_records_result_index` (`result`),
  KEY `medical_records_valid_until_index` (`valid_until`),
  CONSTRAINT `medical_records_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `medical_records_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `medical_records_passport_entry_id_foreign` FOREIGN KEY (`passport_entry_id`) REFERENCES `passport_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `medical_records_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medical_records`
--

LOCK TABLES `medical_records` WRITE;
/*!40000 ALTER TABLE `medical_records` DISABLE KEYS */;
INSERT INTO `medical_records` VALUES (1,19,'2026-10-08','2026-10-08','fit','2027-01-08',NULL,1,1,NULL,'2026-10-08 09:36:01','2026-10-08 09:36:01',NULL),(2,1,'2026-10-08','2026-10-08','unfit',NULL,NULL,1,1,NULL,'2026-10-08 09:38:43','2026-10-08 09:38:43',NULL),(3,18,'2026-10-11','2026-10-11','fit','2027-01-11',NULL,1,1,NULL,'2026-10-11 06:19:39','2026-10-11 06:19:39',NULL),(4,21,'2026-10-11','2026-10-11','fit','2027-01-11',NULL,3,3,NULL,'2026-10-11 07:01:51','2026-10-11 07:01:51',NULL),(5,22,'2026-10-11','2026-10-11','fit','2027-01-11',NULL,3,3,NULL,'2026-10-11 09:23:41','2026-10-11 09:23:41',NULL),(6,23,'2026-10-12','2026-10-12','fit','2027-01-12',NULL,2,2,NULL,'2026-10-11 18:32:25','2026-10-11 18:32:25',NULL),(7,20,'2026-10-11','2026-10-12','fit','2027-01-12',NULL,3,3,NULL,'2026-10-11 18:36:19','2026-10-11 18:36:19',NULL),(8,24,'2026-10-11','2026-10-11','fit','2027-01-11',NULL,2,2,NULL,'2026-10-11 19:01:55','2026-10-11 19:01:55',NULL),(9,25,'2026-10-11','2026-10-11','fit','2027-01-11',NULL,2,2,NULL,'2026-10-11 19:15:26','2026-10-11 19:15:26',NULL),(10,26,'2026-10-12','2026-10-12','fit','2027-01-12',NULL,1,1,NULL,'2026-10-12 10:13:58','2026-10-12 10:13:58',NULL);
/*!40000 ALTER TABLE `medical_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_10_01_080314_create_personal_access_tokens_table',1),(5,'2026_10_01_080409_add_role_columns_to_users_table',1),(6,'2026_10_04_000001_create_references_table',2),(7,'2026_10_04_000002_create_companies_table',2),(8,'2026_10_04_000003_create_passport_entries_table',2),(9,'2026_10_05_000001_create_countries_table',3),(10,'2026_10_05_000002_create_app_settings_table',3),(11,'2026_10_05_000003_add_passport_details_to_passport_entries_table',3),(12,'2026_10_05_000004_add_country_code_to_companies_table',3),(13,'2026_10_07_000001_create_medical_records_table',4),(14,'2026_10_07_000002_add_current_medical_id_to_passport_entries_table',4),(15,'2026_10_09_000001_add_stage_status_date_to_passport_entries_table',5),(16,'2026_10_10_000001_create_passport_step_records_table',6),(17,'2026_10_10_000002_add_stage_columns_to_passport_entries_table',6),(18,'2026_10_11_000001_create_medical_centers_table',7),(19,'2026_10_11_000002_add_center_and_slip_to_medical_records_table',7),(20,'2026_10_11_000003_add_agent_and_quota_to_companies_table',7),(21,'2026_10_12_000001_move_medical_slip_to_passport_entries_table',8),(22,'2026_10_13_000001_add_name_index_to_references_table',9),(23,'2026_10_14_000001_make_reference_optional_on_passport_entries_table',9),(24,'2026_10_15_000001_ensure_malaysia_default_passport_country',9),(25,'2026_10_16_000001_add_medical_date_to_passport_entries_table',9),(26,'2026_10_17_000001_add_scan_to_passport_entries_table',10),(27,'2026_10_17_000002_drop_medical_slip_no_from_passport_entries_table',10),(28,'2026_10_18_000001_add_issue_date_and_entry_mode',11),(29,'2026_10_19_000001_convert_keyin_entry_mode_to_online',12),(30,'2026_10_20_000001_reshape_calling_records',13),(31,'2026_10_21_000001_calling_permitted_and_visa_tabs',14),(32,'2026_10_22_000001_calling_without_calling_date',15),(33,'2026_10_23_000001_flight_confirm_tabs',15),(34,'2026_10_24_000001_bmet_milestones',16),(35,'2026_10_25_000001_flight_booking_and_confirm',17);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passport_entries`
--

DROP TABLE IF EXISTS `passport_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `passport_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `passport_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `passport_number` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_code` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `company_id` bigint unsigned NOT NULL,
  `passport_received_date` date NOT NULL,
  `passport_expiry_date` date DEFAULT NULL,
  `medical_slip_date` date DEFAULT NULL,
  `medical_center_id` bigint unsigned DEFAULT NULL,
  `medical_date` date DEFAULT NULL,
  `entry_mode` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scan_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scan_original_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scan_mime` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scan_size` int unsigned DEFAULT NULL,
  `scan_uploaded_at` timestamp NULL DEFAULT NULL,
  `current_medical_id` bigint unsigned DEFAULT NULL,
  `current_stage` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'passport',
  `stage_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_status_date` date DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `passport_entries_passport_number_unique` (`passport_number`),
  KEY `passport_entries_reference_id_foreign` (`reference_id`),
  KEY `passport_entries_company_id_foreign` (`company_id`),
  KEY `passport_entries_created_by_foreign` (`created_by`),
  KEY `passport_entries_updated_by_foreign` (`updated_by`),
  KEY `passport_entries_passport_name_index` (`passport_name`),
  KEY `passport_entries_passport_received_date_index` (`passport_received_date`),
  KEY `passport_entries_deleted_by_foreign` (`deleted_by`),
  KEY `passport_entries_country_code_index` (`country_code`),
  KEY `passport_entries_current_medical_id_passport_received_date_index` (`current_medical_id`,`passport_received_date`),
  KEY `passport_entries_stage_status_date_index` (`stage_status_date`),
  KEY `passport_entries_current_stage_index` (`current_stage`),
  KEY `passport_entries_stage_status_index` (`stage_status`),
  KEY `passport_entries_medical_center_id_foreign` (`medical_center_id`),
  KEY `passport_entries_medical_slip_date_index` (`medical_slip_date`),
  KEY `passport_entries_entry_mode_index` (`entry_mode`),
  CONSTRAINT `passport_entries_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `passport_entries_country_code_foreign` FOREIGN KEY (`country_code`) REFERENCES `countries` (`code`) ON DELETE RESTRICT,
  CONSTRAINT `passport_entries_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `passport_entries_current_medical_id_foreign` FOREIGN KEY (`current_medical_id`) REFERENCES `medical_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `passport_entries_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `passport_entries_medical_center_id_foreign` FOREIGN KEY (`medical_center_id`) REFERENCES `medical_centers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `passport_entries_reference_id_foreign` FOREIGN KEY (`reference_id`) REFERENCES `references` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `passport_entries_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passport_entries`
--

LOCK TABLES `passport_entries` WRITE;
/*!40000 ALTER TABLE `passport_entries` DISABLE KEYS */;
INSERT INTO `passport_entries` VALUES (1,'ESTELLE TILLMAN','PR8212426',NULL,NULL,1,1,'2025-10-25',NULL,NULL,NULL,'2026-10-08',NULL,NULL,NULL,NULL,NULL,NULL,2,'medical','unfit','2026-10-08',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(2,'ELWIN HELLER','JN1008985',NULL,NULL,2,2,'2026-03-01',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-03-01',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(3,'ORAN HILPERT','XG7500318',NULL,NULL,3,3,'2025-11-23',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2025-11-23',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(4,'MARIA STARK','ZS5373665',NULL,NULL,4,4,'2026-03-21',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-03-21',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(5,'MISS DELTA SCHULTZ','PZ0860421',NULL,NULL,5,5,'2026-08-26',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-08-26',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(6,'SONIA BORER','YM9412979',NULL,NULL,1,1,'2025-12-29',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2025-12-29',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(7,'MAUDIE SAUER','NX2744728',NULL,NULL,2,2,'2025-12-13',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2025-12-13',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(8,'LYDA SCHUMM','IB1459992',NULL,NULL,3,3,'2026-06-03',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-06-03',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(9,'HOLLIE PADBERG','YK4706574',NULL,NULL,4,4,'2025-11-08',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2025-11-08',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(10,'PROF. ELISA GUSIKOWSKI','RF0123559',NULL,NULL,5,5,'2026-09-30',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-09-30',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(11,'DR. JEFFRY KRAJCIK','BO1026585',NULL,NULL,1,1,'2026-01-02',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-01-02',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(12,'TRENTON DANIEL','VV8427667',NULL,NULL,2,2,'2026-04-07',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-04-07',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(13,'KATHRYN CARTWRIGHT SR.','GB4815550',NULL,NULL,3,3,'2026-03-09',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-03-09',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(14,'RAFAELA BRADTKE','LU3917051',NULL,NULL,4,4,'2026-10-03',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'passport','not_started','2026-10-03',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(15,'ADDIE SCHULTZ','YA7678053','MY','2026-10-10',5,5,'2026-07-09','2026-10-11',NULL,NULL,NULL,NULL,'passport-scans/15/e2d67920-6ef1-49d7-9b5c-41eb4a86af59.jpg','Bangladeshi_E_Passport.jpg','image/jpeg',577122,'2026-10-11 10:56:09',NULL,'passport','not_started','2026-07-09',3,3,NULL,'2026-10-04 12:44:46','2026-10-11 10:56:09',NULL),(16,'BLANCA LEBSACK','OA4355733',NULL,NULL,1,1,'2026-02-10',NULL,'2026-10-19',4,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'medical','pending','2026-10-19',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(17,'LOREN O\'REILLY','NN4221725',NULL,NULL,2,2,'2026-05-06',NULL,'2026-10-12',4,NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,'medical','pending','2026-10-12',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(18,'PINK MERTZ SR.','AW0806241',NULL,NULL,3,3,'2026-05-17',NULL,'2026-10-10',3,'2026-10-11','online',NULL,NULL,NULL,NULL,NULL,3,'visa','in_process','2026-10-13',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(19,'CARA WOLFF','DC9460371',NULL,NULL,4,4,'2025-10-16',NULL,NULL,NULL,'2026-10-08','online',NULL,NULL,NULL,NULL,NULL,1,'completed',NULL,'2026-10-13',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(20,'MRS. AVA SCHMELER','ZW0751612',NULL,NULL,5,5,'2026-01-10',NULL,'2026-10-11',3,'2026-10-11','online','passport-scans/20/57111179-5c0a-47c1-9d7f-e8ea5a635eee.pdf','passport.pdf','application/pdf',32890,'2026-10-11 10:12:58',7,'flight','waiting','2026-10-13',3,3,NULL,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(21,'KAWSAR','A23456789','MY','1999-06-08',1,4,'2026-10-11','2030-05-13','2026-10-11',3,'2026-10-11',NULL,NULL,NULL,NULL,NULL,NULL,4,'completed',NULL,'2026-10-13',3,3,NULL,'2026-10-11 06:58:13','2026-10-11 06:58:13',NULL),(22,'MAHADI HASAN RIEAD','A03825490','MY','2000-01-01',7,6,'2026-10-11','2030-05-12','2026-10-11',1,'2026-10-11','online',NULL,NULL,NULL,NULL,NULL,5,'visa','in_process','2026-10-13',3,3,NULL,'2026-10-11 07:21:26','2026-10-11 07:21:26',NULL),(23,'LIVE CHECK SCAN','LV3677962','MY','1990-05-17',1,5,'2026-10-11','2031-10-04','2026-10-11',3,'2026-10-12','online','passport-scans/23/fe47f0ed-fc2e-4920-9212-d29540c4a3c1.pdf','passport-23.pdf','application/pdf',32890,'2026-10-11 10:16:17',6,'bmet','waiting','2026-10-12',2,2,NULL,'2026-10-11 10:14:25','2026-10-11 10:14:25',NULL),(24,'LIVE FIT CHECK','FT0101261','MY','1990-01-15',1,1,'2026-10-11','2032-01-01','2026-10-11',1,'2026-10-11','online','passport-scans/24/423ba295-9500-48ec-beb5-8ca032ed80a3.jpg','scan.jpg','image/jpeg',28941,'2026-10-11 19:01:26',8,'completed',NULL,'2026-11-25',2,2,NULL,'2026-10-11 19:01:26','2026-10-11 19:01:26',NULL),(25,'LIVE ONLINE CHECK','ON0115041','MY','1990-01-15',1,1,'2026-10-11','2032-01-01','2026-10-11',1,'2026-10-11','online','passport-scans/25/234ab380-16a5-47e8-9b0c-d296997ccda7.jpg','Bangladeshi_E_Passport.jpg','image/jpeg',577122,'2026-10-12 08:10:38',9,'completed',NULL,'2026-11-20',2,2,NULL,'2026-10-11 19:15:04','2026-10-11 19:15:04',NULL),(26,'MHR','A014764356','MY','2023-06-13',8,7,'2026-10-12','2031-06-11','2026-10-12',2,'2026-10-12','online','passport-scans/26/db1cc1ba-539f-422c-812f-2808c1ada0f0.png','Afwan_Associates_logo.png','image/png',88742,'2026-10-12 10:12:31',10,'bmet','waiting','2026-10-13',1,1,NULL,'2026-10-12 10:12:31','2026-10-12 10:12:31',NULL);
/*!40000 ALTER TABLE `passport_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `passport_step_records`
--

DROP TABLE IF EXISTS `passport_step_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `passport_step_records` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `passport_entry_id` bigint unsigned NOT NULL,
  `step_key` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_no` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `step_date` date NOT NULL,
  `valid_until` date DEFAULT NULL,
  `details` json DEFAULT NULL,
  `remarks` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned NOT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `deleted_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `passport_step_records_created_by_foreign` (`created_by`),
  KEY `passport_step_records_updated_by_foreign` (`updated_by`),
  KEY `passport_step_records_deleted_by_foreign` (`deleted_by`),
  KEY `passport_step_records_passport_entry_id_step_key_step_date_index` (`passport_entry_id`,`step_key`,`step_date`),
  KEY `passport_step_records_step_key_index` (`step_key`),
  KEY `passport_step_records_status_index` (`status`),
  KEY `passport_step_records_valid_until_index` (`valid_until`),
  CONSTRAINT `passport_step_records_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `passport_step_records_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `passport_step_records_passport_entry_id_foreign` FOREIGN KEY (`passport_entry_id`) REFERENCES `passport_entries` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `passport_step_records_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `passport_step_records`
--

LOCK TABLES `passport_step_records` WRITE;
/*!40000 ALTER TABLE `passport_step_records` DISABLE KEYS */;
INSERT INTO `passport_step_records` VALUES (1,21,'calling','completed','232323','2026-10-11','2028-01-10','{\"receive_date\": \"2026-10-11\"}',NULL,3,3,NULL,'2026-10-11 07:03:06','2026-10-11 07:03:46',NULL),(2,21,'visa','completed','123123123','2026-10-11','2027-01-10',NULL,NULL,3,3,NULL,'2026-10-11 07:04:41','2026-10-11 07:05:13',NULL),(3,21,'bmet','completed','r445454','2026-10-11',NULL,'{\"finger_date\": \"2026-10-07\", \"training_certificate_date\": \"2026-10-01\"}',NULL,3,3,NULL,'2026-10-11 07:05:55','2026-10-11 07:06:45',NULL),(4,25,'calling','completed',NULL,'2026-10-10','2027-10-11','{\"issue_date\": \"2026-10-11\", \"receive_date\": \"2026-10-11\"}',NULL,2,2,NULL,'2026-10-12 06:01:19','2026-10-12 06:01:20',NULL),(5,22,'calling','completed',NULL,'2026-10-13','2026-10-31','{\"issue_date\": \"2026-10-13\", \"calling_date\": \"2026-10-12\", \"receive_date\": \"2026-10-13\"}',NULL,1,1,NULL,'2026-10-12 07:00:33','2026-10-12 18:21:49',NULL),(6,23,'calling','completed',NULL,'2026-10-10','2027-10-11','{\"issue_date\": \"2026-10-11\", \"receive_date\": \"2026-10-11\"}',NULL,3,3,NULL,'2026-10-12 07:36:02','2026-10-12 07:36:02',NULL),(7,23,'visa','completed','37736534','2026-10-12','2026-10-31','{\"start_date\": \"2026-10-11\"}',NULL,3,1,NULL,'2026-10-12 07:36:03','2026-10-12 07:51:06',NULL),(8,25,'visa','completed','LIVEV25','2026-10-12','2027-04-12','{\"start_date\": \"2026-10-12\"}',NULL,1,3,NULL,'2026-10-12 07:40:16','2026-10-12 12:37:17',NULL),(9,20,'calling','completed',NULL,'2026-10-12','2026-10-30','{\"issue_date\": \"2026-10-12\", \"receive_date\": \"2026-10-12\"}',NULL,1,1,NULL,'2026-10-12 07:44:00','2026-10-12 07:44:00',NULL),(10,20,'visa','completed','0011','2026-10-12','2027-01-12','{\"start_date\": \"2026-10-12\"}',NULL,1,1,NULL,'2026-10-12 07:45:06','2026-10-12 10:22:16',NULL),(11,26,'calling','completed',NULL,'2026-10-12','2027-02-09','{\"issue_date\": \"2026-10-12\", \"receive_date\": \"2026-10-12\"}',NULL,1,1,NULL,'2026-10-12 10:21:25','2026-10-12 10:21:25',NULL),(12,24,'calling','completed',NULL,'2026-10-11','2027-10-11','{\"issue_date\": \"2026-10-11\", \"receive_date\": \"2026-10-11\"}',NULL,3,3,NULL,'2026-10-12 11:11:07','2026-10-12 11:11:07',NULL),(13,26,'visa','completed',NULL,'2026-10-13','2026-10-31','{\"issue_date\": \"2026-10-13\", \"start_date\": \"2026-10-12\"}',NULL,1,1,NULL,'2026-10-12 11:17:47','2026-10-12 18:22:45',NULL),(14,25,'bmet','completed','LIVEB25','2026-10-12',NULL,'{\"start_date\": \"2026-10-12\", \"finger_date\": \"2026-10-11\", \"training_certificate_date\": \"2026-10-10\"}',NULL,3,3,NULL,'2026-10-12 12:37:17','2026-10-12 12:37:18',NULL),(15,25,'flight','completed',NULL,'2026-11-20',NULL,'{\"airline\": \"Biman\", \"flight_no\": \"BG084\", \"booking_date\": \"2026-11-20\", \"confirmed_date\": \"2026-11-20\", \"departure_date\": \"2026-11-20\"}',NULL,3,3,NULL,'2026-10-12 12:37:18','2026-10-12 12:37:19',NULL),(16,24,'visa','completed',NULL,'2026-10-13','2027-01-13','{\"issue_date\": \"2026-10-13\", \"start_date\": \"2026-10-13\"}',NULL,3,3,NULL,'2026-10-12 18:13:44','2026-10-12 18:13:44',NULL),(17,24,'bmet','in_process',NULL,'2026-10-13',NULL,'{\"training_certificate_date\": \"2026-10-13\"}',NULL,3,3,3,'2026-10-12 18:13:45','2026-10-12 18:13:45','2026-10-12 18:13:45'),(18,24,'bmet','completed',NULL,'2026-10-13',NULL,'{\"start_date\": \"2026-10-12\", \"finger_date\": \"2026-10-12\", \"training_certificate_date\": \"2026-10-13\"}',NULL,3,3,NULL,'2026-10-12 18:13:46','2026-10-12 18:13:46',NULL),(19,24,'flight','completed',NULL,'2026-11-25',NULL,'{\"booking_date\": \"2026-11-25\", \"confirmed_date\": \"2026-11-25\", \"departure_date\": \"2026-11-25\"}',NULL,3,3,NULL,'2026-10-12 18:13:47','2026-10-12 18:13:47',NULL),(20,22,'visa','in_process',NULL,'2026-10-13',NULL,NULL,NULL,1,1,NULL,'2026-10-12 18:22:09','2026-10-12 18:22:09',NULL),(21,20,'bmet','completed',NULL,'2026-10-13',NULL,'{\"start_date\": \"2026-10-13\", \"finger_date\": \"2026-10-13\", \"training_certificate_date\": \"2026-10-13\"}',NULL,1,1,NULL,'2026-10-12 18:24:29','2026-10-12 18:24:39',NULL),(22,19,'calling','completed',NULL,'2026-10-13','2026-12-05','{\"issue_date\": \"2026-10-13\", \"receive_date\": \"2026-10-13\"}',NULL,1,1,NULL,'2026-10-12 19:31:56','2026-10-12 19:31:56',NULL),(23,19,'visa','completed',NULL,'2026-10-13','2027-01-13','{\"issue_date\": \"2026-10-13\", \"start_date\": \"2026-10-13\"}',NULL,1,3,NULL,'2026-10-12 19:32:07','2026-10-13 09:14:49',NULL),(24,18,'calling','completed',NULL,'2026-10-13','2027-02-04','{\"issue_date\": \"2026-10-13\", \"receive_date\": \"2026-10-13\"}',NULL,1,1,NULL,'2026-10-13 07:16:08','2026-10-13 07:16:08',NULL),(25,18,'visa','in_process',NULL,'2026-10-13',NULL,NULL,NULL,1,1,NULL,'2026-10-13 07:16:26','2026-10-13 07:16:26',NULL),(26,19,'flight','in_process',NULL,'2026-12-05',NULL,'{\"booking_date\": \"2026-12-05\"}',NULL,3,3,3,'2026-10-13 09:14:50','2026-10-13 09:28:35','2026-10-13 09:28:35'),(27,19,'bmet','completed',NULL,'2026-10-13',NULL,'{\"start_date\": \"2026-10-13\", \"finger_date\": \"2026-10-13\", \"training_certificate_date\": \"2026-10-13\"}',NULL,3,3,NULL,'2026-10-13 09:14:50','2026-10-13 09:28:36',NULL),(28,19,'flight','completed',NULL,'2026-10-13',NULL,'{\"booking_date\": \"2026-12-05\", \"confirmed_date\": \"2026-10-13\"}',NULL,3,3,NULL,'2026-10-13 09:28:36','2026-10-13 09:28:37',NULL),(29,21,'flight','completed',NULL,'2026-10-13',NULL,'{\"booking_date\": \"2026-10-13\", \"confirmed_date\": \"2026-10-13\"}',NULL,1,1,NULL,'2026-10-13 09:30:56','2026-10-13 09:31:00',NULL);
/*!40000 ALTER TABLE `passport_step_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
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
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `references`
--

DROP TABLE IF EXISTS `references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `references` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `references_type_name_unique` (`type`,`name`),
  KEY `references_created_by_foreign` (`created_by`),
  KEY `references_type_index` (`type`),
  KEY `references_name_index` (`name`),
  CONSTRAINT `references_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `references`
--

LOCK TABLES `references` WRITE;
/*!40000 ALTER TABLE `references` DISABLE KEYS */;
INSERT INTO `references` VALUES (1,'person','Abdul Karim',NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(2,'person','Rahima Begum',NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(3,'person','Jamal Hossain',NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(4,'agency','Sea Star Overseas',NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(5,'agency','Green Field Recruiting Agency',NULL,NULL,1,3,'2026-10-04 12:44:46','2026-10-04 12:44:46',NULL),(6,'person','shajahan',NULL,NULL,1,3,'2026-10-05 07:06:42','2026-10-05 07:06:42',NULL),(7,'person','mahadi hasan','01401438862',NULL,1,3,'2026-10-11 07:18:49','2026-10-11 07:18:49',NULL),(8,'person','east wast',NULL,NULL,1,1,'2026-10-12 10:09:15','2026-10-12 10:09:15',NULL);
/*!40000 ALTER TABLE `references` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
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
INSERT INTO `sessions` VALUES ('31DJBqwJGAzdzYHpFtTtQ5SpacUBDsrXhrI7qGtp',3,'172.18.0.1','curl/8.7.1','eyJfdG9rZW4iOiJUanlWbEtaMXF1dTRmTEZpS0tZZ2NoTGVIeDE0bUZ3V3Q1MDR2RHo0IiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwXC9hcGlcL2RhdGEtZW50cnlcL2NvbXBhbmllcz9xPVpaJTIwTGl2ZSIsInJvdXRlIjoiZGF0YS1lbnRyeS5jb21wYW5pZXMuaW5kZXgifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MywicGFzc3dvcmRfaGFzaF93ZWIiOiI3YzVkNjY5MmY1ZjBhNDYxMjg1MDliYzIwODM2NGI4NzExZTk4YWM2NWFhNGNhZjBmNDcxNDcxMTE4MmUwYjI1In0=',1791883738),('bcWor3FTYpvRBr9eEgi47EuEIdYiQVrrvfbPuMqn',3,'172.18.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/155.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJtalNxYzhyYjlQWjA1RnhiQUQ0MnBETjlJcmVGUzA0eFU5ekRqdkhSIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MywicGFzc3dvcmRfaGFzaF93ZWIiOiI3YzVkNjY5MmY1ZjBhNDYxMjg1MDliYzIwODM2NGI4NzExZTk4YWM2NWFhNGNhZjBmNDcxNDcxMTE4MmUwYjI1In0=',1791883738),('Q6YaJwsbJxcjZlv9Rlb2z0U9Ey2AKmmIfcXPxK9N',1,'172.18.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/155.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJka2ZJOURMd0dWT0ZXTlBORE1xeDRBR01SM3hTaXBxNEpCUkVuQlJHIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MSwicGFzc3dvcmRfaGFzaF93ZWIiOiI5Mzg3MzUxYzlhYzM5YWEwYjA1ZTBhN2M2YWJlMTk3ZWExZmEzNWUxMWEyY2E2YjkyMGE3ZGMzMzQ4ZWNkMDFlIn0=',1791883863),('slchRb3VemQmm8sTwlbf0NTJM1gayCmPDc2IxF9E',3,'172.18.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/155.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJGNklEckU5ZHZHTVRLRkxvWUNudEJuTDF2dWNUV2szSjNjekpoSUdPIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MywicGFzc3dvcmRfaGFzaF93ZWIiOiI3YzVkNjY5MmY1ZjBhNDYxMjg1MDliYzIwODM2NGI4NzExZTk4YWM2NWFhNGNhZjBmNDcxNDcxMTE4MmUwYjI1In0=',1791882892),('VkAKXGasYaex3XkvVmN7x1HB9CT2y8s0U0k0yCv9',3,'172.18.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/155.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJhUHkzWGR1YXAxbmZadXRjaGFlQ1pOOURUOEthUTl2aFhwTTllcm84IiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MywicGFzc3dvcmRfaGFzaF93ZWIiOiI3YzVkNjY5MmY1ZjBhNDYxMjg1MDliYzIwODM2NGI4NzExZTk4YWM2NWFhNGNhZjBmNDcxNDcxMTE4MmUwYjI1In0=',1791883718),('xSPjxkA9r1N9KUiTuRbxzyqq9UjfBZpMdToHcnCl',3,'172.18.0.1','curl/8.7.1','eyJfdG9rZW4iOiJ5V01DMjl1cHJYYks0R0FsMzVIN29EeklTTHljZ0REcFZvejIzbHpvIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwXC9hcGlcL2RhdGEtZW50cnlcL3dvcmtmbG93XC9zdW1tYXJ5Iiwicm91dGUiOiJkYXRhLWVudHJ5LndvcmtmbG93LnN1bW1hcnkifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MywicGFzc3dvcmRfaGFzaF93ZWIiOiI3YzVkNjY5MmY1ZjBhNDYxMjg1MDliYzIwODM2NGI4NzExZTk4YWM2NWFhNGNhZjBmNDcxNDcxMTE4MmUwYjI1In0=',1791882100),('yhin8z61jrAT83XnghWmnOtPUltVK9swZ1GrQ8uO',3,'172.18.0.1','Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/155.0.0.0 Safari/537.36','eyJfdG9rZW4iOiJKekQwVVQ2aE9ucHJzUDdLRHpwZFJRRTNxeVNXc0hQRVpKWW9RMnJOIiwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MywicGFzc3dvcmRfaGFzaF93ZWIiOiI3YzVkNjY5MmY1ZjBhNDYxMjg1MDliYzIwODM2NGI4NzExZTk4YWM2NWFhNGNhZjBmNDcxNDcxMTE4MmUwYjI1In0=',1791883752),('ZRqtfGTBlCMf3jyiyKQYXP9QEBn296kkIz0locZI',3,'172.18.0.1','curl/8.7.1','eyJfdG9rZW4iOiIwMGFtR2E5UEMwdjBuWVVvUHpISjZGd053QTE1VnNWbVpQbmU1YWFwIiwiX3ByZXZpb3VzIjp7InVybCI6Imh0dHA6XC9cL2xvY2FsaG9zdDo4MDAwXC9hcGlcL2RhdGEtZW50cnlcL3N0ZXBzXC9ibWV0XC9wYXNzcG9ydHM/c2VhcmNoPURDOTQ2MDM3MSZ0YWI9Y2xlYXJlZCIsInJvdXRlIjoiZGF0YS1lbnRyeS5zdGVwcy5wYXNzcG9ydHMifSwiX2ZsYXNoIjp7Im9sZCI6W10sIm5ldyI6W119LCJsb2dpbl93ZWJfNTliYTM2YWRkYzJiMmY5NDAxNTgwZjAxNGM3ZjU4ZWE0ZTMwOTg5ZCI6MywicGFzc3dvcmRfaGFzaF93ZWIiOiI3YzVkNjY5MmY1ZjBhNDYxMjg1MDliYzIwODM2NGI4NzExZTk4YWM2NWFhNGNhZjBmNDcxNDcxMTE4MmUwYjI1In0=',1791883283);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_created_by_foreign` (`created_by`),
  KEY `users_role_index` (`role`),
  CONSTRAINT `users_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Super Admin','superadmin@afwan.test','2026-10-01 10:06:44','$2y$12$5D.dRPDK/zf3xEd.F1lLkeKtd0rDVUfEwR0xIj50pO7ffOlR7l.Oe','super_admin',1,NULL,NULL,'2026-10-01 10:06:44','2026-10-01 10:13:19'),(2,'Admin','admin@afwan.test','2026-10-01 10:06:45','$2y$12$NwlQEtdhvD8H4ZmUia6JsehV4DVBzxxACJqPFAakeUOadB8LOjz1u','admin',1,NULL,NULL,'2026-10-01 10:06:45','2026-10-01 10:13:19'),(3,'Data Entry','dataentry@afwan.test','2026-10-01 10:06:45','$2y$12$f5DjAbgJ9R7WkGQQM/fpm.oaEI2ZBeqaK6vnBhHlTsRSWA/1jp4hO','data_entry',1,NULL,NULL,'2026-10-01 10:06:45','2026-10-01 10:13:19'),(4,'Accounts','accounts@afwan.test','2026-10-01 10:06:45','$2y$12$NOJd29mOw3XAWyKo/IJOOeDX5IKlkBOVPSi3sF4q/Ykk5z/eSM.xS','accounts',1,NULL,NULL,'2026-10-01 10:06:45','2026-10-01 10:13:20');
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

-- Dump completed on 2026-10-13  9:38:02
