-- Pool Queue update: the camera API that Pool Vision (and the laptop camera) post pots to.
--
-- For a database that is already live (no shell): phpMyAdmin → select the database →
-- Import → this file. Safe to run twice. It adds two tables and records their
-- migrations, so a later `php artisan migrate` sees nothing left to do.
--
-- A fresh install doesn't need this: database/schema.sql already has everything.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `personal_access_tokens` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint UNSIGNED NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vision_events` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` char(36) NOT NULL,
  `game_id` bigint UNSIGNED DEFAULT NULL,
  `table_label` varchar(40) DEFAULT NULL,
  `type` varchar(20) NOT NULL,
  `kind` varchar(20) NOT NULL,
  `pocket` varchar(40) DEFAULT NULL,
  `kind_conf` decimal(4,2) DEFAULT NULL,
  `video_t` decimal(10,2) DEFAULT NULL,
  `applied` tinyint(1) NOT NULL DEFAULT '0',
  `outcome` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vision_events_event_id_unique` (`event_id`),
  KEY `vision_events_game_id_created_at_index` (`game_id`,`created_at`),
  CONSTRAINT `vision_events_game_id_foreign` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT m.migration, (SELECT COALESCE(MAX(batch), 0) + 1 FROM `migrations` AS b)
FROM (
  SELECT '2026_10_07_000000_create_vision_events_table' AS migration
  UNION ALL SELECT '2026_10_07_133710_create_personal_access_tokens_table'
) AS m
WHERE NOT EXISTS (SELECT 1 FROM `migrations` AS e WHERE e.migration = m.migration);
