-- =============================================================================
--  Pool Queue — MySQL schema (complete)
--
--  Use this only if you cannot run `php artisan migrate` on the server.
--  Import it through cPanel > phpMyAdmin, with your app's database selected.
--
--  It creates BOTH:
--    * the framework tables Laravel needs (sessions / cache / jobs / users) —
--      required because this app uses SESSION_DRIVER=database and
--      CACHE_STORE=database, so the site errors on the first request without them
--    * the Pool Queue application tables
--
--  It also seeds the `migrations` table so a later `php artisan migrate` sees
--  everything as already run and won't try to re-create it.
--
--  Requires MySQL 5.7+ or MariaDB 10.2+.
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- --  Laravel's own migration ledger  -----------------------------------------

CREATE TABLE IF NOT EXISTS `migrations` (
  `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  Framework tables (from Laravel's default 0001_01_01_* migrations)
-- =============================================================================

-- --  users + password_reset_tokens (this app has no auth, but the default
-- --  migration creates them and code/tools may still expect them)  -----------

CREATE TABLE `users` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --  sessions (SESSION_DRIVER=database — hit on every request)  --------------

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --  cache + cache_locks (CACHE_STORE=database)  ----------------------------

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --  jobs / job_batches / failed_jobs (QUEUE_CONNECTION=database)  ----------

CREATE TABLE `jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint UNSIGNED NOT NULL,
  `reserved_at` int UNSIGNED DEFAULT NULL,
  `available_at` int UNSIGNED NOT NULL,
  `created_at` int UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  Pool Queue application tables
-- =============================================================================

-- --  players  ---------------------------------------------------------------

CREATE TABLE `players` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `nickname` varchar(40) DEFAULT NULL,
  `side_a_color` char(7) NOT NULL DEFAULT '#3a6f96',
  `side_b_color` char(7) NOT NULL DEFAULT '#c1483f',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `players_name_unique` (`name`),
  KEY `players_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --  games  -----------------------------------------------------------------
--  game_type + target_score come from the create form (8-ball fills 8, 9-ball 9).
--  win_streak / champion_side / previous_game_id track a winner-stays run: the
--  crown shows while win_streak >= 1 and clears when a fresh game is racked.

CREATE TABLE `games` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `format` enum('1v1','2v1','2v2') NOT NULL,
  `game_type` enum('eight_ball','nine_ball') NOT NULL DEFAULT 'eight_ball',
  `target_score` tinyint UNSIGNED NOT NULL DEFAULT '8',
  `status` enum('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
  `table_label` varchar(40) DEFAULT NULL,
  `break_side` enum('a','b') DEFAULT NULL,
  `shooting_side` enum('a','b') DEFAULT NULL,
  `side_a_ball_group` enum('stripes','solids') DEFAULT NULL,
  `side_b_ball_group` enum('stripes','solids') DEFAULT NULL,
  `side_a_score` smallint UNSIGNED NOT NULL DEFAULT '0',
  `side_b_score` smallint UNSIGNED NOT NULL DEFAULT '0',
  `winner_side` enum('a','b') DEFAULT NULL,
  `win_streak` int UNSIGNED NOT NULL DEFAULT '0',
  `champion_side` enum('a','b') DEFAULT NULL,
  `previous_game_id` bigint UNSIGNED DEFAULT NULL,
  `notes` text,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `games_status_started_at_index` (`status`,`started_at`),
  KEY `games_completed_at_index` (`completed_at`),
  KEY `games_previous_game_id_foreign` (`previous_game_id`),
  CONSTRAINT `games_previous_game_id_foreign` FOREIGN KEY (`previous_game_id`) REFERENCES `games` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --  game_players (who played on which side)  --------------------------------

CREATE TABLE `game_players` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `game_id` bigint UNSIGNED NOT NULL,
  `player_id` bigint UNSIGNED NOT NULL,
  `side` enum('a','b') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `game_players_game_id_player_id_unique` (`game_id`,`player_id`),
  KEY `game_players_player_id_side_index` (`player_id`,`side`),
  CONSTRAINT `game_players_game_id_foreign` FOREIGN KEY (`game_id`) REFERENCES `games` (`id`) ON DELETE CASCADE,
  CONSTRAINT `game_players_player_id_foreign` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --  queue_entries (a slot in line: one player or a pair)  -------------------

CREATE TABLE `queue_entries` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `position` int UNSIGNED NOT NULL DEFAULT '0',
  `label` varchar(60) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `queue_entries_position_index` (`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `queue_entry_players` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `queue_entry_id` bigint UNSIGNED NOT NULL,
  `player_id` bigint UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `queue_entry_players_queue_entry_id_player_id_unique` (`queue_entry_id`,`player_id`),
  KEY `queue_entry_players_player_id_index` (`player_id`),
  CONSTRAINT `queue_entry_players_queue_entry_id_foreign` FOREIGN KEY (`queue_entry_id`) REFERENCES `queue_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `queue_entry_players_player_id_foreign` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
--  Mark every migration as already run
-- =============================================================================

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('0001_01_01_000000_create_users_table', 1),
  ('0001_01_01_000001_create_cache_table', 1),
  ('0001_01_01_000002_create_jobs_table', 1),
  ('2026_08_29_000001_create_players_table', 1),
  ('2026_08_29_000002_create_games_table', 1),
  ('2026_08_29_000003_create_game_players_table', 1),
  ('2026_08_29_000004_create_queue_entries_table', 1),
  ('2026_08_29_000005_create_queue_entry_players_table', 1),
  ('2026_09_02_000001_add_game_type_and_streak_to_games_table', 1),
  ('2026_09_02_000002_add_side_colors_to_players_table', 1),
  ('2026_09_02_000003_add_shooting_side_to_games_table', 1);

SET FOREIGN_KEY_CHECKS = 1;
