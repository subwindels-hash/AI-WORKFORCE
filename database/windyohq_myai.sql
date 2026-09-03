-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 03, 2026 at 06:33 AM
-- Server version: 11.4.12-MariaDB-cll-lve-log
-- PHP Version: 8.4.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `windyohq_myai`
--

-- --------------------------------------------------------

--
-- Table structure for table `ai_learning_recommendations`
--

CREATE TABLE `ai_learning_recommendations` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `kind` varchar(24) NOT NULL,
  `message` varchar(400) NOT NULL,
  `evidence` longtext NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'ACTIVE',
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `analysis_runs`
--

CREATE TABLE `analysis_runs` (
  `id` varchar(36) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `timeframe` varchar(5) NOT NULL,
  `bias` varchar(10) NOT NULL,
  `confidence` decimal(5,4) NOT NULL,
  `regime` varchar(20) NOT NULL,
  `recommendation` varchar(10) NOT NULL,
  `synthetic` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(40) NOT NULL,
  `completed_at` varchar(32) NOT NULL,
  `payload` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `type` varchar(32) NOT NULL,
  `at` varchar(32) NOT NULL,
  `actor` varchar(8) NOT NULL DEFAULT 'system',
  `summary` varchar(500) NOT NULL,
  `detail` longtext DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_events`
--

CREATE TABLE `auth_events` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(64) NOT NULL,
  `detail` longtext DEFAULT NULL,
  `at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `backtests`
--

CREATE TABLE `backtests` (
  `id` varchar(36) NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `strategy_id` varchar(60) NOT NULL,
  `strategy_version` varchar(20) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `timeframe` varchar(5) NOT NULL,
  `synthetic` tinyint(1) NOT NULL DEFAULT 0,
  `payload` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ci_sessions`
--

CREATE TABLE `ci_sessions` (
  `id` varchar(128) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `timestamp` int(11) NOT NULL DEFAULT 0,
  `data` mediumtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `collections`
--

CREATE TABLE `collections` (
  `id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `collection_leads`
--

CREATE TABLE `collection_leads` (
  `collection_id` varchar(36) NOT NULL,
  `lead_id` varchar(36) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL,
  `uid` char(12) NOT NULL,
  `sender_name` varchar(120) NOT NULL,
  `sender_email` varchar(190) NOT NULL,
  `sender_phone` varchar(40) DEFAULT NULL,
  `sender_address` varchar(255) DEFAULT NULL,
  `subject` varchar(200) NOT NULL DEFAULT 'Contact form inquiry',
  `body` mediumtext NOT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'contact_form',
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `is_starred` tinyint(4) NOT NULL DEFAULT 0,
  `is_read` tinyint(4) NOT NULL DEFAULT 0,
  `assigned_to` int(11) DEFAULT NULL,
  `last_reply_at` varchar(32) DEFAULT NULL,
  `last_reply_by` int(11) DEFAULT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contact_message_replies`
--

CREATE TABLE `contact_message_replies` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `template_id` int(11) DEFAULT NULL,
  `author_id` int(11) DEFAULT NULL,
  `author_label` varchar(190) NOT NULL,
  `direction` varchar(10) NOT NULL DEFAULT 'outbound',
  `to_email` varchar(190) DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `body` mediumtext NOT NULL,
  `body_text` mediumtext DEFAULT NULL,
  `sent_at` varchar(32) NOT NULL,
  `delivery_status` varchar(20) NOT NULL DEFAULT 'sent',
  `delivery_message` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `conversation_sessions`
--

CREATE TABLE `conversation_sessions` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `scenario` varchar(40) NOT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'casual',
  `correction` varchar(24) NOT NULL DEFAULT 'important',
  `status` varchar(12) NOT NULL DEFAULT 'ACTIVE',
  `state` longtext NOT NULL,
  `turn_count` int(11) NOT NULL DEFAULT 0,
  `started_at` varchar(32) NOT NULL,
  `completed_at` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `daily_learning_plans`
--

CREATE TABLE `daily_learning_plans` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `day` varchar(10) NOT NULL,
  `plan` longtext NOT NULL,
  `est_minutes` int(11) NOT NULL DEFAULT 0,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `duplicate_candidates`
--

CREATE TABLE `duplicate_candidates` (
  `id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `lead_a_id` varchar(36) NOT NULL,
  `lead_b_id` varchar(36) NOT NULL,
  `rule_name` varchar(80) NOT NULL,
  `confidence` decimal(4,3) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `duplicate_resolutions`
--

CREATE TABLE `duplicate_resolutions` (
  `id` varchar(36) NOT NULL,
  `candidate_id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `resolver_id` int(11) NOT NULL,
  `action` varchar(30) NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `email_templates`
--

CREATE TABLE `email_templates` (
  `id` int(11) NOT NULL,
  `code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `category` varchar(40) NOT NULL DEFAULT 'general',
  `description` varchar(255) DEFAULT NULL,
  `subject` varchar(200) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `body_text` mediumtext DEFAULT NULL,
  `variables_json` longtext NOT NULL DEFAULT '{}',
  `is_system` tinyint(4) NOT NULL DEFAULT 0,
  `is_active` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `export_history`
--

CREATE TABLE `export_history` (
  `id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `user_id` int(11) NOT NULL,
  `format` varchar(10) NOT NULL,
  `filters` longtext NOT NULL,
  `lead_count` int(11) NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` varchar(36) NOT NULL,
  `source` varchar(10) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `market` varchar(12) NOT NULL,
  `strategy` varchar(60) DEFAULT NULL,
  `strategy_version` varchar(20) DEFAULT NULL,
  `direction` varchar(5) NOT NULL,
  `entry_time` varchar(32) NOT NULL,
  `entry_price` decimal(20,8) NOT NULL,
  `exit_time` varchar(32) DEFAULT NULL,
  `exit_price` decimal(20,8) DEFAULT NULL,
  `position_size` decimal(20,8) NOT NULL,
  `stop_loss` decimal(20,8) DEFAULT NULL,
  `take_profit` decimal(20,8) DEFAULT NULL,
  `fees` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `slippage` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `pnl` decimal(18,6) DEFAULT NULL,
  `pnl_pct` decimal(12,6) DEFAULT NULL,
  `r_multiple` decimal(12,6) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `ai_confidence` decimal(5,4) DEFAULT NULL,
  `confidence_source` varchar(16) DEFAULT NULL,
  `agent_consensus` varchar(120) DEFAULT NULL,
  `risk_score` decimal(8,6) DEFAULT NULL,
  `execution_time` varchar(32) NOT NULL,
  `backtest_id` varchar(36) DEFAULT NULL,
  `paper_position_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `languages`
--

CREATE TABLE `languages` (
  `code` varchar(8) NOT NULL,
  `name` varchar(60) NOT NULL,
  `native_name` varchar(120) NOT NULL,
  `iso_code` varchar(8) NOT NULL,
  `writing_system` varchar(40) NOT NULL,
  `direction` varchar(3) NOT NULL DEFAULT 'ltr',
  `features` longtext NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `languages`
--

INSERT INTO `languages` (`code`, `name`, `native_name`, `iso_code`, `writing_system`, `direction`, `features`, `active`, `updated_at`) VALUES
('af', 'Afrikaans', 'Afrikaans', 'af', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('ar', 'Arabic', 'العربية', 'ar', 'arabic', 'rtl', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('de', 'German', 'Deutsch', 'de', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"B1\",\"assessment_bank\":12,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('en', 'English', 'English', 'en', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A2\",\"assessment_bank\":10,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('es', 'Spanish', 'Español', 'es', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"B1\",\"assessment_bank\":12,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('fr', 'French', 'Français', 'fr', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"B1\",\"assessment_bank\":12,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('ha', 'Hausa', 'Hausa', 'ha', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('hi', 'Hindi', 'हिन्दी', 'hi', 'devanagari', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('ig', 'Igbo', 'Igbo', 'ig', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('it', 'Italian', 'Italiano', 'it', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"B1\",\"assessment_bank\":12,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('ja', 'Japanese', '日本語', 'ja', 'kana', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('ko', 'Korean', '한국어', 'ko', 'hangul', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('nl', 'Dutch', 'Nederlands', 'nl', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"B1\",\"assessment_bank\":12,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('pt', 'Portuguese', 'Português', 'pt', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"B1\",\"assessment_bank\":12,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('ru', 'Russian', 'Русский', 'ru', 'cyrillic', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('sw', 'Swahili', 'Kiswahili', 'sw', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('tr', 'Turkish', 'Türkçe', 'tr', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('yo', 'Yoruba', 'Yorùbá', 'yo', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('zh', 'Chinese (Mandarin)', '中文', 'zh', 'han', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z'),
('zu', 'Zulu', 'isiZulu', 'zu', 'latin', 'ltr', '{\"registry\":true,\"adaptive_assessment\":true,\"assessment_ceiling\":\"A1\",\"assessment_bank\":5,\"lessons\":false,\"conversation\":false,\"writing_correction\":false,\"vocabulary_srs\":false,\"listening\":false,\"speaking\":false}', 1, '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `language_assessments`
--

CREATE TABLE `language_assessments` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'IN_PROGRESS',
  `state` longtext NOT NULL,
  `result` longtext DEFAULT NULL,
  `started_at` varchar(32) NOT NULL,
  `completed_at` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `language_progress`
--

CREATE TABLE `language_progress` (
  `id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `skill` varchar(12) NOT NULL,
  `level` varchar(10) DEFAULT NULL,
  `value_pct` decimal(5,2) DEFAULT NULL,
  `source` varchar(24) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `source` varchar(40) NOT NULL,
  `source_id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `region` varchar(120) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `phone` varchar(80) DEFAULT NULL,
  `website` text DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `linkedin_url` text DEFAULT NULL,
  `lead_kind` varchar(20) NOT NULL DEFAULT 'business',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `owner_id` int(11) DEFAULT NULL,
  `metadata` longtext NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_activities`
--

CREATE TABLE `lead_activities` (
  `id` varchar(36) NOT NULL,
  `lead_id` varchar(36) DEFAULT NULL,
  `organization_id` varchar(80) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL,
  `detail` longtext NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_notes`
--

CREATE TABLE `lead_notes` (
  `id` varchar(36) NOT NULL,
  `lead_id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `author_id` int(11) NOT NULL,
  `body` text NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_organizations`
--

CREATE TABLE `lead_organizations` (
  `id` varchar(80) NOT NULL,
  `name` varchar(160) NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lead_organizations`
--

INSERT INTO `lead_organizations` (`id`, `name`, `created_at`) VALUES
('org-1', 'Administrator workspace', '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `lead_organization_members`
--

CREATE TABLE `lead_organization_members` (
  `organization_id` varchar(80) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'member',
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lead_organization_members`
--

INSERT INTO `lead_organization_members` (`organization_id`, `user_id`, `role`, `created_at`) VALUES
('org-1', 1, 'owner', '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `lead_outreach`
--

CREATE TABLE `lead_outreach` (
  `id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `lead_id` varchar(36) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `channel` varchar(20) NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `body` text NOT NULL,
  `status` varchar(20) NOT NULL,
  `detail` longtext NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `learning_modules`
--

CREATE TABLE `learning_modules` (
  `id` varchar(36) NOT NULL,
  `path_id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `sequence` int(11) NOT NULL,
  `code` varchar(60) NOT NULL,
  `title` varchar(160) NOT NULL,
  `focus_skill` varchar(12) NOT NULL,
  `level` varchar(10) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'LOCKED',
  `attempts_count` int(11) NOT NULL DEFAULT 0,
  `completed_at` varchar(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `learning_paths`
--

CREATE TABLE `learning_paths` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `from_level` varchar(10) NOT NULL,
  `target_level` varchar(10) NOT NULL,
  `status` varchar(12) NOT NULL DEFAULT 'ACTIVE',
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lesson_attempts`
--

CREATE TABLE `lesson_attempts` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `module_id` varchar(36) DEFAULT NULL,
  `kind` varchar(16) NOT NULL,
  `score_pct` decimal(5,2) DEFAULT NULL,
  `passed` tinyint(1) DEFAULT NULL,
  `detail` longtext NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `listening_attempts`
--

CREATE TABLE `listening_attempts` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `exercise_item_id` varchar(20) NOT NULL,
  `mode` varchar(14) NOT NULL,
  `score_pct` decimal(5,2) DEFAULT NULL,
  `passed` tinyint(1) DEFAULT NULL,
  `detail` longtext NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lotteries`
--

CREATE TABLE `lotteries` (
  `id` int(11) NOT NULL,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `rules_version` varchar(16) NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lotteries`
--

INSERT INTO `lotteries` (`id`, `code`, `name`, `enabled`, `rules_version`, `created_at`, `updated_at`) VALUES
(1, 'EUROMILLIONS', 'EuroMillions', 1, '1.0', '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `lottery_ai_decisions`
--

CREATE TABLE `lottery_ai_decisions` (
  `id` bigint(20) NOT NULL,
  `lottery_code` varchar(32) NOT NULL,
  `combination_id` bigint(20) DEFAULT NULL,
  `model_version` varchar(64) NOT NULL,
  `mode` varchar(32) DEFAULT NULL,
  `decision` mediumtext NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_backtests`
--

CREATE TABLE `lottery_backtests` (
  `id` bigint(20) NOT NULL,
  `lottery_code` varchar(32) NOT NULL,
  `strategy` varchar(40) NOT NULL,
  `model_version` varchar(64) NOT NULL,
  `lines_per_draw` int(11) NOT NULL DEFAULT 1,
  `draws_tested` int(11) NOT NULL DEFAULT 0,
  `period_from` date DEFAULT NULL,
  `period_to` date DEFAULT NULL,
  `dataset_version` varchar(128) DEFAULT NULL,
  `report` mediumtext NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_combinations`
--

CREATE TABLE `lottery_combinations` (
  `id` bigint(20) NOT NULL,
  `lottery_code` varchar(32) NOT NULL,
  `mode` varchar(32) NOT NULL,
  `model_version` varchar(64) NOT NULL,
  `seed` varchar(32) DEFAULT NULL,
  `line_count` int(11) NOT NULL DEFAULT 0,
  `lines` mediumtext NOT NULL,
  `constraints` mediumtext NOT NULL,
  `score_summary` mediumtext NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_data_sources`
--

CREATE TABLE `lottery_data_sources` (
  `id` int(11) NOT NULL,
  `provider_code` varchar(64) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `synthetic` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lottery_data_sources`
--

INSERT INTO `lottery_data_sources` (`id`, `provider_code`, `display_name`, `enabled`, `synthetic`, `created_at`, `updated_at`) VALUES
(1, 'official-euromillions', 'Authorized EuroMillions feed', 0, 0, '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z'),
(2, 'unconfigured', 'No lottery data provider configured', 0, 0, '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `lottery_draws`
--

CREATE TABLE `lottery_draws` (
  `id` bigint(20) NOT NULL,
  `lottery_code` varchar(32) NOT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `external_id` varchar(64) NOT NULL,
  `draw_date` date NOT NULL,
  `jackpot` varchar(32) DEFAULT NULL,
  `rollover` tinyint(1) NOT NULL DEFAULT 0,
  `source` varchar(120) NOT NULL,
  `source_timestamp` varchar(40) NOT NULL,
  `retrieved_at` varchar(32) NOT NULL,
  `verification_status` varchar(32) NOT NULL,
  `payload` mediumtext NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_draw_numbers`
--

CREATE TABLE `lottery_draw_numbers` (
  `id` bigint(20) NOT NULL,
  `draw_id` bigint(20) NOT NULL,
  `kind` varchar(8) NOT NULL,
  `position` int(11) NOT NULL,
  `number` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_model_versions`
--

CREATE TABLE `lottery_model_versions` (
  `id` bigint(20) NOT NULL,
  `model_name` varchar(64) NOT NULL,
  `model_version` varchar(16) NOT NULL,
  `config` mediumtext NOT NULL,
  `dataset_version` varchar(128) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'ACTIVE',
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lottery_model_versions`
--

INSERT INTO `lottery_model_versions` (`id`, `model_name`, `model_version`, `config`, `dataset_version`, `status`, `created_at`) VALUES
(1, 'WINDELS Lottery Model', '1.0', '{\"scoreWeights\":{\"sum\":0.3,\"oddEven\":0.2,\"lowHigh\":0.2,\"spread\":0.15,\"consecutives\":0.15},\"generatorModes\":[\"RANDOM\",\"BALANCED\",\"HISTORICAL\",\"DIVERSIFIED\",\"ANTI-POPULAR\"],\"backtestStrategies\":[\"RANDOM_BASELINE\",\"BALANCED_PROFILE\",\"HISTORICAL_FREQ\",\"ANTI_POPULAR\"]}', 'n=0;last=none', 'ACTIVE', '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `lottery_provider_health`
--

CREATE TABLE `lottery_provider_health` (
  `id` bigint(20) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `status` varchar(32) NOT NULL,
  `response_ms` int(11) DEFAULT NULL,
  `records_received` int(11) NOT NULL DEFAULT 0,
  `invalid_records` int(11) NOT NULL DEFAULT 0,
  `error_rate` decimal(8,5) DEFAULT NULL,
  `last_success_at` varchar(32) DEFAULT NULL,
  `last_failure_at` varchar(32) DEFAULT NULL,
  `last_draw_retrieved` varchar(32) DEFAULT NULL,
  `data_freshness_seconds` int(11) DEFAULT NULL,
  `synthetic` tinyint(1) NOT NULL DEFAULT 0,
  `observed_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_rules`
--

CREATE TABLE `lottery_rules` (
  `id` int(11) NOT NULL,
  `lottery_code` varchar(32) NOT NULL,
  `version` varchar(16) NOT NULL,
  `main_count` int(11) NOT NULL,
  `main_min` int(11) NOT NULL,
  `main_max` int(11) NOT NULL,
  `star_count` int(11) NOT NULL,
  `star_min` int(11) NOT NULL,
  `star_max` int(11) NOT NULL,
  `schedule` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `lottery_rules`
--

INSERT INTO `lottery_rules` (`id`, `lottery_code`, `version`, `main_count`, `main_min`, `main_max`, `star_count`, `star_min`, `star_max`, `schedule`, `active`, `created_at`) VALUES
(1, 'EUROMILLIONS', '1.0', 5, 1, 50, 2, 1, 12, '{\"days\":[2,5],\"time\":\"21:00\",\"timezone\":\"UTC\"}', 1, '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `lottery_sync_runs`
--

CREATE TABLE `lottery_sync_runs` (
  `id` varchar(64) NOT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `job_type` varchar(40) NOT NULL,
  `status` varchar(32) NOT NULL,
  `started_at` varchar(32) NOT NULL,
  `ended_at` varchar(32) DEFAULT NULL,
  `records_processed` int(11) NOT NULL DEFAULT 0,
  `records_created` int(11) NOT NULL DEFAULT 0,
  `records_updated` int(11) NOT NULL DEFAULT 0,
  `errors` text DEFAULT NULL,
  `payload` mediumtext DEFAULT NULL,
  `execution_key` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_tickets`
--

CREATE TABLE `lottery_tickets` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `lottery_code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `draw_date` date DEFAULT NULL,
  `generation_method` varchar(32) NOT NULL,
  `model_version` varchar(64) NOT NULL,
  `configuration` mediumtext NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'OPEN',
  `result` mediumtext DEFAULT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lottery_ticket_lines`
--

CREATE TABLE `lottery_ticket_lines` (
  `id` bigint(20) NOT NULL,
  `ticket_id` bigint(20) NOT NULL,
  `position` int(11) NOT NULL,
  `mains` text NOT NULL,
  `stars` text NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` varchar(36) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `severity` varchar(10) NOT NULL DEFAULT 'info',
  `title` varchar(200) NOT NULL,
  `detail` longtext NOT NULL,
  `dedupe_key` varchar(120) DEFAULT NULL,
  `read_at` varchar(32) DEFAULT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paper_accounts`
--

CREATE TABLE `paper_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(60) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'USD',
  `starting_balance` decimal(18,2) NOT NULL,
  `balance` decimal(18,2) NOT NULL,
  `peak_equity` decimal(18,2) NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paper_deployments`
--

CREATE TABLE `paper_deployments` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `strategy_id` varchar(60) NOT NULL,
  `strategy_version` varchar(20) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `market_class` varchar(12) NOT NULL,
  `timeframe` varchar(5) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `deployed_at` varchar(32) NOT NULL,
  `last_evaluated_at` varchar(32) DEFAULT NULL,
  `last_signal` varchar(8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paper_orders`
--

CREATE TABLE `paper_orders` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `market_class` varchar(12) NOT NULL,
  `side` varchar(4) NOT NULL,
  `type` varchar(6) NOT NULL,
  `units` decimal(20,8) NOT NULL,
  `price` decimal(20,8) DEFAULT NULL,
  `stop_loss` decimal(20,8) DEFAULT NULL,
  `take_profit` decimal(20,8) DEFAULT NULL,
  `status` varchar(10) NOT NULL,
  `reject_reason` text DEFAULT NULL,
  `risk_amount` decimal(18,6) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `ai_confidence` decimal(5,4) DEFAULT NULL,
  `strategy` varchar(60) DEFAULT NULL,
  `created_at` varchar(32) NOT NULL,
  `filled_at` varchar(32) DEFAULT NULL,
  `fill_price` decimal(20,8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paper_positions`
--

CREATE TABLE `paper_positions` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `market_class` varchar(12) NOT NULL,
  `direction` varchar(5) NOT NULL,
  `units` decimal(20,8) NOT NULL,
  `entry_price` decimal(20,8) NOT NULL,
  `stop_loss` decimal(20,8) NOT NULL,
  `take_profit` decimal(20,8) NOT NULL,
  `entry_fee` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `risk_amount` decimal(18,6) DEFAULT NULL,
  `strategy` varchar(60) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `ai_confidence` decimal(5,4) DEFAULT NULL,
  `opened_at` varchar(32) NOT NULL,
  `status` varchar(8) NOT NULL DEFAULT 'OPEN',
  `closed_at` varchar(32) DEFAULT NULL,
  `exit_price` decimal(20,8) DEFAULT NULL,
  `realized_pnl` decimal(18,6) DEFAULT NULL,
  `exit_reason` varchar(16) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `paper_trades`
--

CREATE TABLE `paper_trades` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `position_id` int(11) NOT NULL,
  `leg` varchar(5) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `price` decimal(20,8) NOT NULL,
  `units` decimal(20,8) NOT NULL,
  `fee` decimal(18,6) NOT NULL DEFAULT 0.000000,
  `time` varchar(32) NOT NULL,
  `synthetic` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int(11) NOT NULL,
  `code` varchar(96) NOT NULL,
  `name` varchar(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `code`, `name`) VALUES
(1, 'system.super_admin', 'Full platform administration'),
(2, 'sports.view', 'View sports intelligence'),
(3, 'sports.manage', 'Manage sports providers and configuration'),
(4, 'sports.approve', 'Approve sports tickets'),
(5, 'sports.settle', 'Override sports settlements'),
(6, 'trading.view', 'View trading status, proposals and executions'),
(7, 'trading.control', 'Kill switch, trading mode, risk and automation limits'),
(8, 'trading.execute', 'Propose, approve and route trades through the Execution Supervisor'),
(9, 'lottery.view', 'View lottery intelligence (draws, statistics, tickets, performance)'),
(10, 'lottery.manage', 'Manage lottery providers, data sync and configuration');

-- --------------------------------------------------------

--
-- Table structure for table `platform_state`
--

CREATE TABLE `platform_state` (
  `k` varchar(32) NOT NULL,
  `v` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `platform_state`
--

INSERT INTO `platform_state` (`k`, `v`) VALUES
('state', '{\"tradingMode\":\"ANALYSIS_ONLY\",\"killSwitch\":{\"active\":true,\"activatedAt\":null,\"reason\":\"Default state at boot — orders blocked until explicitly released\"},\"allowSyntheticPaperData\":false}');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `code`, `name`) VALUES
(1, 'super_admin', 'Super administrator'),
(2, 'sports_admin', 'Sports administrator'),
(3, 'sports_viewer', 'Sports viewer'),
(4, 'trading_operator', 'Trading operator (control + execution)'),
(5, 'trading_viewer', 'Trading viewer (read-only)'),
(6, 'lottery_admin', 'Lottery administrator'),
(7, 'lottery_viewer', 'Lottery viewer');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(1, 2),
(2, 2),
(3, 2),
(1, 3),
(2, 3),
(1, 4),
(2, 4),
(1, 5),
(2, 5),
(1, 6),
(4, 6),
(5, 6),
(1, 7),
(4, 7),
(1, 8),
(4, 8),
(1, 9),
(6, 9),
(7, 9),
(1, 10),
(6, 10);

-- --------------------------------------------------------

--
-- Table structure for table `search_history`
--

CREATE TABLE `search_history` (
  `id` varchar(36) NOT NULL,
  `organization_id` varchar(80) NOT NULL,
  `user_id` int(11) NOT NULL,
  `query` text NOT NULL,
  `provider` varchar(40) NOT NULL,
  `filters` longtext NOT NULL,
  `results_returned` int(11) NOT NULL,
  `new_leads_created` int(11) NOT NULL,
  `duplicates_detected` int(11) NOT NULL,
  `errors` text DEFAULT NULL,
  `duration_ms` int(11) NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speaking_attempts`
--

CREATE TABLE `speaking_attempts` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `prompt_text` varchar(400) NOT NULL,
  `transcript` text DEFAULT NULL,
  `word_accuracy_pct` decimal(5,2) DEFAULT NULL,
  `exact_match` tinyint(1) NOT NULL DEFAULT 0,
  `provider` varchar(24) NOT NULL DEFAULT 'none',
  `detail` longtext NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_backtests`
--

CREATE TABLE `sports_backtests` (
  `id` varchar(40) NOT NULL,
  `created_at` datetime NOT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `params` text NOT NULL,
  `report` text NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'COMPLETED'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_calibrations`
--

CREATE TABLE `sports_calibrations` (
  `id` int(11) NOT NULL,
  `model_version_id` int(11) NOT NULL,
  `method` varchar(16) NOT NULL DEFAULT 'platt',
  `intercept` decimal(8,6) NOT NULL,
  `slope` decimal(8,6) NOT NULL,
  `brier` decimal(8,6) DEFAULT NULL,
  `ece` decimal(8,6) DEFAULT NULL,
  `samples` int(11) NOT NULL DEFAULT 0,
  `bins` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'PENDING',
  `created_by` varchar(64) DEFAULT NULL,
  `approved_by` varchar(64) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_configurations`
--

CREATE TABLE `sports_configurations` (
  `id` int(11) NOT NULL,
  `version` int(11) NOT NULL,
  `module_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `ticket_engine_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `platform_mode` varchar(16) NOT NULL DEFAULT 'SANDBOX',
  `engine_mode` varchar(32) NOT NULL DEFAULT 'USER_APPROVAL_REQUIRED',
  `target_odds_min` decimal(10,4) NOT NULL DEFAULT 5.0000,
  `target_odds_max` decimal(10,4) NOT NULL DEFAULT 8.0000,
  `max_selections` int(11) NOT NULL DEFAULT 5,
  `risk_level` varchar(16) NOT NULL DEFAULT 'CONSERVATIVE',
  `min_confidence` decimal(5,2) NOT NULL DEFAULT 75.00,
  `min_expected_value` decimal(8,5) NOT NULL DEFAULT 0.02000,
  `max_correlation` varchar(8) NOT NULL DEFAULT 'MEDIUM',
  `min_data_quality` smallint(6) NOT NULL DEFAULT 80,
  `min_liquidity` decimal(10,4) DEFAULT NULL,
  `allowed_markets` text NOT NULL,
  `allowed_leagues` text NOT NULL,
  `max_exposure` decimal(12,2) NOT NULL DEFAULT 100.00,
  `stake_amount` decimal(12,2) NOT NULL DEFAULT 10.00,
  `void_policy` varchar(16) NOT NULL DEFAULT 'RESTITUTE_ODDS',
  `require_calibration` tinyint(1) NOT NULL DEFAULT 1,
  `updated_by` varchar(64) NOT NULL DEFAULT 'system',
  `reason` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sports_configurations`
--

INSERT INTO `sports_configurations` (`id`, `version`, `module_enabled`, `ticket_engine_enabled`, `platform_mode`, `engine_mode`, `target_odds_min`, `target_odds_max`, `max_selections`, `risk_level`, `min_confidence`, `min_expected_value`, `max_correlation`, `min_data_quality`, `min_liquidity`, `allowed_markets`, `allowed_leagues`, `max_exposure`, `stake_amount`, `void_policy`, `require_calibration`, `updated_by`, `reason`, `created_at`) VALUES
(1, 0, 1, 1, 'SANDBOX', 'USER_APPROVAL_REQUIRED', 5.0000, 8.0000, 5, 'CONSERVATIVE', 75.00, 0.02000, 'MEDIUM', 80, NULL, '[]', '[]', 100.00, 10.00, 'RESTITUTE_ODDS', 1, 'system', 'built-in defaults', '2026-08-24 00:00:00');

-- --------------------------------------------------------

--
-- Table structure for table `sports_daily_tickets`
--

CREATE TABLE `sports_daily_tickets` (
  `id` int(11) NOT NULL,
  `date` date NOT NULL,
  `ticket_id` varchar(36) DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `configuration_version` int(11) DEFAULT NULL,
  `candidates_evaluated` int(11) NOT NULL DEFAULT 0,
  `predictions_recorded` int(11) NOT NULL DEFAULT 0,
  `rejections` int(11) NOT NULL DEFAULT 0,
  `rejection_summary` text DEFAULT NULL,
  `message` varchar(500) DEFAULT NULL,
  `provider` varchar(64) DEFAULT NULL,
  `run_id` varchar(40) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_data_quality_assessments`
--

CREATE TABLE `sports_data_quality_assessments` (
  `id` bigint(20) NOT NULL,
  `match_id` bigint(20) NOT NULL,
  `score` int(11) NOT NULL,
  `band` varchar(16) NOT NULL,
  `freshness_score` int(11) NOT NULL,
  `provider_reliability_score` int(11) NOT NULL,
  `eligible_prediction` tinyint(1) NOT NULL,
  `eligible_ticket` tinyint(1) NOT NULL,
  `missing_fields` longtext NOT NULL,
  `checks_payload` longtext NOT NULL,
  `assessed_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_data_sources`
--

CREATE TABLE `sports_data_sources` (
  `id` int(11) NOT NULL,
  `provider_code` varchar(64) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sports_data_sources`
--

INSERT INTO `sports_data_sources` (`id`, `provider_code`, `display_name`, `enabled`, `created_at`, `updated_at`) VALUES
(1, 'manual', 'Manual / approved source', 0, '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z');

-- --------------------------------------------------------

--
-- Table structure for table `sports_job_runs`
--

CREATE TABLE `sports_job_runs` (
  `id` varchar(40) NOT NULL,
  `job_type` varchar(48) NOT NULL,
  `status` varchar(16) NOT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `records_processed` int(11) NOT NULL DEFAULT 0,
  `records_created` int(11) NOT NULL DEFAULT 0,
  `records_updated` int(11) NOT NULL DEFAULT 0,
  `errors` text DEFAULT NULL,
  `provider` varchar(64) DEFAULT NULL,
  `execution_key` varchar(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_matches`
--

CREATE TABLE `sports_matches` (
  `id` bigint(20) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `external_id` varchar(128) NOT NULL,
  `sport` varchar(32) NOT NULL,
  `competition` varchar(160) NOT NULL,
  `home_team` varchar(160) NOT NULL,
  `away_team` varchar(160) NOT NULL,
  `kickoff_at` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL,
  `source_timestamp` varchar(32) NOT NULL,
  `payload` longtext NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_model_metrics`
--

CREATE TABLE `sports_model_metrics` (
  `id` int(11) NOT NULL,
  `model_version_id` int(11) NOT NULL,
  `window_days` int(11) NOT NULL,
  `sample_type` varchar(16) NOT NULL DEFAULT 'live',
  `predictions` int(11) NOT NULL DEFAULT 0,
  `settled` int(11) NOT NULL DEFAULT 0,
  `accuracy` decimal(8,5) DEFAULT NULL,
  `brier` decimal(8,5) DEFAULT NULL,
  `ece` decimal(8,5) DEFAULT NULL,
  `win_rate` decimal(8,5) DEFAULT NULL,
  `roi` decimal(8,5) DEFAULT NULL,
  `max_drawdown` decimal(12,4) DEFAULT NULL,
  `computed_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_model_versions`
--

CREATE TABLE `sports_model_versions` (
  `id` int(11) NOT NULL,
  `model_name` varchar(120) NOT NULL,
  `model_version` varchar(64) NOT NULL,
  `feature_version` varchar(64) NOT NULL,
  `calibration_version` varchar(64) DEFAULT NULL,
  `status` varchar(24) NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_odds`
--

CREATE TABLE `sports_odds` (
  `id` bigint(20) NOT NULL,
  `match_id` bigint(20) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `market` varchar(96) NOT NULL,
  `selection` varchar(160) NOT NULL,
  `decimal_odds` decimal(12,6) NOT NULL,
  `observed_at` varchar(32) NOT NULL,
  `payload` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_performance_snapshots`
--

CREATE TABLE `sports_performance_snapshots` (
  `id` int(11) NOT NULL,
  `as_of` datetime NOT NULL,
  `window` varchar(8) NOT NULL,
  `payload` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_predictions`
--

CREATE TABLE `sports_predictions` (
  `id` varchar(36) NOT NULL,
  `match_id` bigint(20) NOT NULL,
  `model_version_id` int(11) NOT NULL,
  `market` varchar(96) NOT NULL,
  `selection` varchar(160) NOT NULL,
  `raw_probability` decimal(10,8) DEFAULT NULL,
  `calibrated_probability` decimal(10,8) DEFAULT NULL,
  `implied_probability` decimal(10,8) DEFAULT NULL,
  `expected_value` decimal(12,8) DEFAULT NULL,
  `confidence` decimal(10,8) DEFAULT NULL,
  `risk` varchar(16) NOT NULL,
  `correlation` varchar(16) NOT NULL,
  `data_quality_score` int(11) NOT NULL,
  `decision` varchar(48) NOT NULL,
  `rejection_reasons` longtext DEFAULT NULL,
  `factors` longtext NOT NULL,
  `input_version` varchar(64) NOT NULL,
  `odds` decimal(14,6) DEFAULT NULL,
  `odds_timestamp` varchar(32) DEFAULT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_provider_health`
--

CREATE TABLE `sports_provider_health` (
  `id` bigint(20) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `status` varchar(32) NOT NULL,
  `response_ms` int(11) DEFAULT NULL,
  `error_rate` decimal(8,5) DEFAULT NULL,
  `rate_limit_remaining` int(11) DEFAULT NULL,
  `last_success_at` varchar(32) DEFAULT NULL,
  `last_failure_at` varchar(32) DEFAULT NULL,
  `last_fixture_sync_at` varchar(32) DEFAULT NULL,
  `last_odds_sync_at` varchar(32) DEFAULT NULL,
  `last_result_sync_at` varchar(32) DEFAULT NULL,
  `data_freshness_seconds` int(11) DEFAULT NULL,
  `records_received` int(11) NOT NULL DEFAULT 0,
  `invalid_records` int(11) NOT NULL DEFAULT 0,
  `missing_fields` longtext DEFAULT NULL,
  `observed_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_results`
--

CREATE TABLE `sports_results` (
  `id` bigint(20) NOT NULL,
  `match_id` bigint(20) NOT NULL,
  `provider_id` int(11) NOT NULL,
  `home_score` int(11) DEFAULT NULL,
  `away_score` int(11) DEFAULT NULL,
  `status` varchar(24) NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `source_timestamp` varchar(32) NOT NULL,
  `verified_at` varchar(32) DEFAULT NULL,
  `payload` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_sync_runs`
--

CREATE TABLE `sports_sync_runs` (
  `id` varchar(36) NOT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `job_type` varchar(48) NOT NULL,
  `status` varchar(24) NOT NULL,
  `started_at` varchar(32) NOT NULL,
  `ended_at` varchar(32) DEFAULT NULL,
  `records_processed` int(11) NOT NULL DEFAULT 0,
  `records_created` int(11) NOT NULL DEFAULT 0,
  `records_updated` int(11) NOT NULL DEFAULT 0,
  `errors` longtext DEFAULT NULL,
  `execution_key` varchar(128) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_tickets`
--

CREATE TABLE `sports_tickets` (
  `id` varchar(36) NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `model_version_id` int(11) DEFAULT NULL,
  `configuration_version` varchar(64) NOT NULL,
  `total_odds` decimal(14,6) DEFAULT NULL,
  `selection_count` int(11) NOT NULL,
  `combined_probability` decimal(10,8) DEFAULT NULL,
  `confidence` decimal(10,8) DEFAULT NULL,
  `risk` varchar(16) NOT NULL,
  `correlation` varchar(16) NOT NULL,
  `data_quality_score` int(11) DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `approval_status` varchar(32) NOT NULL,
  `settlement_status` varchar(32) NOT NULL,
  `reason` text DEFAULT NULL,
  `stake` decimal(12,2) DEFAULT NULL,
  `pnl` decimal(14,4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sports_ticket_selections`
--

CREATE TABLE `sports_ticket_selections` (
  `id` bigint(20) NOT NULL,
  `ticket_id` varchar(36) NOT NULL,
  `prediction_id` varchar(36) NOT NULL,
  `match_id` bigint(20) NOT NULL,
  `market` varchar(96) NOT NULL,
  `selection` varchar(160) NOT NULL,
  `odds` decimal(14,6) NOT NULL,
  `odds_timestamp` varchar(32) NOT NULL,
  `model_probability` decimal(10,8) DEFAULT NULL,
  `calibrated_probability` decimal(10,8) DEFAULT NULL,
  `expected_value` decimal(12,8) DEFAULT NULL,
  `risk` varchar(16) NOT NULL,
  `result` varchar(24) DEFAULT NULL,
  `status` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `strategies`
--

CREATE TABLE `strategies` (
  `strategy_id` varchar(60) NOT NULL,
  `version` varchar(20) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `market_classes` longtext NOT NULL,
  `timeframes` longtext NOT NULL,
  `params` longtext NOT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'builtin',
  `lifecycle` varchar(20) NOT NULL DEFAULT 'DRAFT',
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL,
  `lifecycle_history` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `strategies`
--

INSERT INTO `strategies` (`strategy_id`, `version`, `name`, `description`, `market_classes`, `timeframes`, `params`, `source`, `lifecycle`, `created_at`, `updated_at`, `lifecycle_history`) VALUES
('breakout', '1.0.0', 'Breakout (range + volume confirmation)', 'Trades close-confirmed range breaks only when volume confirms the move.', '[\"forex\",\"crypto\",\"stock\",\"etf\",\"futures\",\"indices\"]', '[\"15m\",\"1h\",\"4h\",\"1d\"]', '{\"lookback\":20,\"volumeMultiplier\":1.2,\"stopAtr\":2,\"targetR\":3}', 'builtin', 'DRAFT', '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z', '[{\"from\":null,\"to\":\"DRAFT\",\"at\":\"2026-08-24T00:00:00Z\",\"reason\":\"registered\"}]'),
('mean-reversion', '1.0.0', 'Mean Reversion (Bollinger + RSI)', 'Buys lower-band pierces with oversold RSI in a non-trending regime; exits at the mean or stop.', '[\"forex\",\"crypto\",\"stock\",\"etf\",\"indices\"]', '[\"15m\",\"1h\",\"4h\",\"1d\"]', '{\"period\":20,\"std\":2,\"rsiMin\":30,\"rsiMax\":70,\"stopAtr\":1.5,\"targetR\":2}', 'builtin', 'DRAFT', '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z', '[{\"from\":null,\"to\":\"DRAFT\",\"at\":\"2026-08-24T00:00:00Z\",\"reason\":\"registered\"}]'),
('momentum', '1.0.0', 'Momentum (ROC + MACD)', 'Trades strong rate-of-change with a confirming MACD histogram and exits on momentum flip.', '[\"forex\",\"crypto\",\"stock\",\"etf\",\"futures\",\"indices\"]', '[\"15m\",\"1h\",\"4h\",\"1d\"]', '{\"rocPeriod\":12,\"minRoc\":0.005,\"stopAtr\":2,\"targetR\":2}', 'builtin', 'DRAFT', '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z', '[{\"from\":null,\"to\":\"DRAFT\",\"at\":\"2026-08-24T00:00:00Z\",\"reason\":\"registered\"}]'),
('trend-following', '1.0.0', 'Trend Following (EMA cross + ADX)', 'Long when EMA20 crosses above EMA50 with ADX >= threshold; exit on opposite cross. Stops at ATR multiple, targets at R multiple.', '[\"forex\",\"crypto\",\"stock\",\"etf\",\"commodity\",\"futures\",\"indices\"]', '[\"5m\",\"15m\",\"1h\",\"4h\",\"1d\"]', '{\"fast\":20,\"slow\":50,\"adxMin\":25,\"stopAtr\":2,\"targetR\":3}', 'builtin', 'DRAFT', '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z', '[{\"from\":null,\"to\":\"DRAFT\",\"at\":\"2026-08-24T00:00:00Z\",\"reason\":\"registered\"}]');

-- --------------------------------------------------------

--
-- Table structure for table `study_sessions`
--

CREATE TABLE `study_sessions` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `activity` varchar(24) NOT NULL,
  `day` varchar(10) NOT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_executions`
--

CREATE TABLE `trade_executions` (
  `id` varchar(40) NOT NULL,
  `proposal_id` varchar(40) NOT NULL,
  `broker` varchar(40) NOT NULL,
  `broker_order_id` varchar(64) DEFAULT NULL,
  `automated` tinyint(1) NOT NULL DEFAULT 0,
  `submitted_at` varchar(32) NOT NULL,
  `status` varchar(24) NOT NULL,
  `result` longtext NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trade_proposals`
--

CREATE TABLE `trade_proposals` (
  `id` varchar(40) NOT NULL,
  `created_at` varchar(32) NOT NULL,
  `actor` varchar(80) NOT NULL DEFAULT 'user',
  `broker` varchar(40) NOT NULL,
  `symbol` varchar(32) NOT NULL,
  `market_class` varchar(20) NOT NULL,
  `side` varchar(4) NOT NULL,
  `order_type` varchar(10) NOT NULL,
  `volume` decimal(18,6) NOT NULL,
  `price` decimal(18,8) DEFAULT NULL,
  `stop_loss` decimal(18,8) NOT NULL,
  `take_profit` decimal(18,8) DEFAULT NULL,
  `strategy_id` varchar(60) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `status` varchar(24) NOT NULL,
  `intent` longtext NOT NULL,
  `checks` longtext NOT NULL,
  `risk_decision` longtext DEFAULT NULL,
  `decision_by` varchar(80) DEFAULT NULL,
  `decided_at` varchar(32) DEFAULT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `display_name` varchar(120) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL,
  `last_login_at` varchar(32) DEFAULT NULL,
  `username` varchar(64) DEFAULT NULL,
  `user_uid` char(6) DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `display_name`, `active`, `created_at`, `updated_at`, `last_login_at`, `username`, `user_uid`, `profile_image`, `phone`, `address`) VALUES
(1, 'admin@example.com', '$2y$10$ReRvxrMNbiyRYPW3uMMmN.wrc3aq7nc6UkilEw5a20hFXkUBuuDGa', 'Platform Administrator', 1, '2026-08-24T00:00:00Z', '2026-08-24T00:00:00Z', NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_language_profiles`
--

CREATE TABLE `user_language_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `level` varchar(10) NOT NULL DEFAULT 'Beginner',
  `goal` varchar(300) DEFAULT NULL,
  `explanation_language` varchar(8) NOT NULL DEFAULT 'en',
  `status` varchar(16) NOT NULL DEFAULT 'ACTIVE',
  `daily_minutes` int(11) NOT NULL DEFAULT 20,
  `created_at` varchar(32) NOT NULL,
  `updated_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_vocabulary`
--

CREATE TABLE `user_vocabulary` (
  `id` int(11) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vocabulary_id` int(11) NOT NULL,
  `stage` int(11) NOT NULL DEFAULT 0,
  `familiarity` decimal(4,3) NOT NULL DEFAULT 0.000,
  `next_review_at` varchar(32) NOT NULL,
  `review_count` int(11) NOT NULL DEFAULT 0,
  `lapse_count` int(11) NOT NULL DEFAULT 0,
  `last_result` varchar(8) DEFAULT NULL,
  `last_reviewed_at` varchar(32) DEFAULT NULL,
  `added_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vocabulary`
--

CREATE TABLE `vocabulary` (
  `id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `word` varchar(120) NOT NULL,
  `translation` varchar(160) NOT NULL,
  `pronunciation` varchar(160) DEFAULT NULL,
  `example_sentence` varchar(300) DEFAULT NULL,
  `category` varchar(24) NOT NULL,
  `level` varchar(4) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vocabulary`
--

INSERT INTO `vocabulary` (`id`, `language_code`, `word`, `translation`, `pronunciation`, `example_sentence`, `category`, `level`, `active`) VALUES
(1, 'nl', 'hallo', 'hello', NULL, 'Hallo, ik heet Anna.', 'greetings', 'A1', 1),
(2, 'nl', 'goedemorgen', 'good morning', NULL, NULL, 'greetings', 'A1', 1),
(3, 'nl', 'dank je wel', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(4, 'nl', 'tot ziens', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(5, 'nl', 'ik', 'I', NULL, NULL, 'people', 'A1', 1),
(6, 'nl', 'vijf', 'five', NULL, NULL, 'numbers', 'A1', 1),
(7, 'nl', 'een', 'one', NULL, NULL, 'numbers', 'A1', 1),
(8, 'nl', 'het huis', 'the house', NULL, NULL, 'places', 'A1', 1),
(9, 'nl', 'de stad', 'the city', NULL, NULL, 'places', 'A2', 1),
(10, 'nl', 'de afspraak', 'the appointment', NULL, NULL, 'everyday', 'A2', 1),
(11, 'es', 'hola', 'hello', NULL, NULL, 'greetings', 'A1', 1),
(12, 'es', 'buenos días', 'good morning', NULL, NULL, 'greetings', 'A1', 1),
(13, 'es', 'gracias', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(14, 'es', 'adiós', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(15, 'es', 'yo', 'I', NULL, NULL, 'people', 'A1', 1),
(16, 'es', 'seis', 'six', NULL, NULL, 'numbers', 'A1', 1),
(17, 'es', 'siete', 'seven', NULL, NULL, 'numbers', 'A1', 1),
(18, 'es', 'ocho', 'eight', NULL, NULL, 'numbers', 'A1', 1),
(19, 'es', 'el pueblo', 'the town / village', NULL, NULL, 'places', 'A2', 1),
(20, 'es', 'la cita', 'the appointment', NULL, NULL, 'everyday', 'A2', 1),
(21, 'it', 'buongiorno', 'good morning / hello', NULL, NULL, 'greetings', 'A1', 1),
(22, 'it', 'grazie', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(23, 'it', 'arrivederci', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(24, 'it', 'io', 'I', NULL, NULL, 'people', 'A1', 1),
(25, 'it', 'sì', 'yes', NULL, NULL, 'basics', 'A1', 1),
(26, 'it', 'no', 'no', NULL, NULL, 'basics', 'A1', 1),
(27, 'it', 'tre', 'three', NULL, NULL, 'numbers', 'A1', 1),
(28, 'it', 'cinque', 'five', NULL, NULL, 'numbers', 'A1', 1),
(29, 'it', 'la città', 'the city', NULL, NULL, 'places', 'A2', 1),
(30, 'it', 'l\'appuntamento', 'the appointment', NULL, NULL, 'everyday', 'A2', 1),
(31, 'fr', 'bonjour', 'hello / good day', NULL, NULL, 'greetings', 'A1', 1),
(32, 'fr', 'merci', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(33, 'fr', 'au revoir', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(34, 'fr', 'oui', 'yes', NULL, NULL, 'basics', 'A1', 1),
(35, 'fr', 'non', 'no', NULL, NULL, 'basics', 'A1', 1),
(36, 'fr', 'nous', 'we', NULL, NULL, 'people', 'A1', 1),
(37, 'fr', 'quatre', 'four', NULL, NULL, 'numbers', 'A1', 1),
(38, 'fr', 'deux', 'two', NULL, NULL, 'numbers', 'A1', 1),
(39, 'fr', 'la ville', 'the city', NULL, NULL, 'places', 'A2', 1),
(40, 'fr', 'un rendez-vous', 'an appointment', NULL, NULL, 'everyday', 'A2', 1),
(41, 'de', 'hallo', 'hello', NULL, NULL, 'greetings', 'A1', 1),
(42, 'de', 'guten Morgen', 'good morning', NULL, NULL, 'greetings', 'A1', 1),
(43, 'de', 'danke', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(44, 'de', 'tschüss', 'bye', NULL, NULL, 'courtesy', 'A1', 1),
(45, 'de', 'ich', 'I', NULL, NULL, 'people', 'A1', 1),
(46, 'de', 'ja', 'yes', NULL, NULL, 'basics', 'A1', 1),
(47, 'de', 'nein', 'no', NULL, NULL, 'basics', 'A1', 1),
(48, 'de', 'drei', 'three', NULL, NULL, 'numbers', 'A1', 1),
(49, 'de', 'die Stadt', 'the city', NULL, NULL, 'places', 'A2', 1),
(50, 'de', 'der Termin', 'the appointment', NULL, NULL, 'everyday', 'A2', 1),
(51, 'en', 'hello', 'hello', NULL, NULL, 'greetings', 'A1', 1),
(52, 'en', 'thank you', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(53, 'en', 'goodbye', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(54, 'en', 'I', 'I', NULL, NULL, 'people', 'A1', 1),
(55, 'en', 'yes', 'yes', NULL, NULL, 'basics', 'A1', 1),
(56, 'en', 'no', 'no', NULL, NULL, 'basics', 'A1', 1),
(57, 'en', 'three', 'three', NULL, NULL, 'numbers', 'A1', 1),
(58, 'en', 'seven', 'seven', NULL, NULL, 'numbers', 'A1', 1),
(59, 'en', 'the city', 'the city', NULL, NULL, 'places', 'A1', 1),
(60, 'en', 'an appointment', 'an appointment', NULL, NULL, 'everyday', 'A2', 1),
(61, 'pt', 'bom dia', 'good morning', NULL, NULL, 'greetings', 'A1', 1),
(62, 'pt', 'obrigado', 'thank you (m. speaker)', NULL, NULL, 'courtesy', 'A1', 1),
(63, 'pt', 'adeus', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(64, 'pt', 'eu', 'I', NULL, NULL, 'people', 'A1', 1),
(65, 'pt', 'sim', 'yes', NULL, NULL, 'basics', 'A1', 1),
(66, 'pt', 'não', 'no', NULL, NULL, 'basics', 'A1', 1),
(67, 'pt', 'dois', 'two', NULL, NULL, 'numbers', 'A1', 1),
(68, 'pt', 'seis', 'six', NULL, NULL, 'numbers', 'A1', 1),
(69, 'pt', 'a cidade', 'the city', NULL, NULL, 'places', 'A2', 1),
(70, 'pt', 'o compromisso', 'the appointment', NULL, NULL, 'everyday', 'A2', 1),
(71, 'ar', 'مرحبا', 'hello', 'marhaban', NULL, 'greetings', 'A1', 1),
(72, 'ar', 'شكرا', 'thank you', 'shukran', NULL, 'courtesy', 'A1', 1),
(73, 'ar', 'مع السلامة', 'goodbye', 'ma\'a as-salama', NULL, 'courtesy', 'A1', 1),
(74, 'ar', 'اسمي', 'my name', 'ismī', 'اسمي أحمد', 'people', 'A1', 1),
(75, 'ar', 'واحد', 'one', 'wāḥid', NULL, 'numbers', 'A1', 1),
(76, 'ar', 'اثنان', 'two', 'ithnān', NULL, 'numbers', 'A1', 1),
(77, 'ar', 'ثلاثة', 'three', 'thalātha', NULL, 'numbers', 'A1', 1),
(78, 'ar', 'بيت', 'house', 'bayt', NULL, 'places', 'A1', 1),
(79, 'ar', 'ماء', 'water', 'mā\'', NULL, 'food-drink', 'A1', 1),
(80, 'ar', 'يوم', 'day', 'yawm', NULL, 'time', 'A1', 1),
(81, 'zh', '你好', 'hello', 'nǐ hǎo', NULL, 'greetings', 'A1', 1),
(82, 'zh', '谢谢', 'thank you', 'xièxie', NULL, 'courtesy', 'A1', 1),
(83, 'zh', '再见', 'goodbye', 'zàijiàn', NULL, 'courtesy', 'A1', 1),
(84, 'zh', '请', 'please', 'qǐng', NULL, 'courtesy', 'A1', 1),
(85, 'zh', '一', 'one', 'yī', NULL, 'numbers', 'A1', 1),
(86, 'zh', '二', 'two', 'èr', NULL, 'numbers', 'A1', 1),
(87, 'zh', '三', 'three', 'sān', NULL, 'numbers', 'A1', 1),
(88, 'zh', '家', 'home / family', 'jiā', NULL, 'places', 'A1', 1),
(89, 'zh', '水', 'water', 'shuǐ', NULL, 'food-drink', 'A1', 1),
(90, 'zh', '天', 'day / sky', 'tiān', NULL, 'time', 'A1', 1),
(91, 'ja', 'こんにちは', 'hello (daytime)', 'konnichiwa', NULL, 'greetings', 'A1', 1),
(92, 'ja', 'ありがとう', 'thank you', 'arigatō', NULL, 'courtesy', 'A1', 1),
(93, 'ja', 'さようなら', 'goodbye', 'sayōnara', NULL, 'courtesy', 'A1', 1),
(94, 'ja', 'はい', 'yes', 'hai', NULL, 'basics', 'A1', 1),
(95, 'ja', 'いいえ', 'no', 'iie', NULL, 'basics', 'A1', 1),
(96, 'ja', '一', 'one', 'ichi', NULL, 'numbers', 'A1', 1),
(97, 'ja', '二', 'two', 'ni', NULL, 'numbers', 'A1', 1),
(98, 'ja', '三', 'three', 'san', NULL, 'numbers', 'A1', 1),
(99, 'ja', '家', 'house / home', 'ie', NULL, 'places', 'A1', 1),
(100, 'ja', '水', 'water', 'mizu', NULL, 'food-drink', 'A1', 1),
(101, 'ko', '안녕하세요', 'hello', 'annyeonghaseyo', NULL, 'greetings', 'A1', 1),
(102, 'ko', '감사합니다', 'thank you', 'gamsahamnida', NULL, 'courtesy', 'A1', 1),
(103, 'ko', '네', 'yes', 'ne', NULL, 'basics', 'A1', 1),
(104, 'ko', '아니요', 'no', 'aniyo', NULL, 'basics', 'A1', 1),
(105, 'ko', '하나', 'one', 'hana', NULL, 'numbers', 'A1', 1),
(106, 'ko', '둘', 'two', 'dul', NULL, 'numbers', 'A1', 1),
(107, 'ko', '셋', 'three', 'set', NULL, 'numbers', 'A1', 1),
(108, 'ko', '집', 'house / home', 'jip', NULL, 'places', 'A1', 1),
(109, 'ko', '물', 'water', 'mul', NULL, 'food-drink', 'A1', 1),
(110, 'ko', '날', 'day', 'nal', NULL, 'time', 'A1', 1),
(111, 'ru', 'привет', 'hi', 'privet', NULL, 'greetings', 'A1', 1),
(112, 'ru', 'спасибо', 'thank you', 'spasibo', NULL, 'courtesy', 'A1', 1),
(113, 'ru', 'до свидания', 'goodbye', 'do svidaniya', NULL, 'courtesy', 'A1', 1),
(114, 'ru', 'пожалуйста', 'please / you are welcome', 'pozhaluysta', NULL, 'courtesy', 'A1', 1),
(115, 'ru', 'один', 'one', 'odin', NULL, 'numbers', 'A1', 1),
(116, 'ru', 'два', 'two', 'dva', NULL, 'numbers', 'A1', 1),
(117, 'ru', 'три', 'three', 'tri', NULL, 'numbers', 'A1', 1),
(118, 'ru', 'дом', 'house / home', 'dom', NULL, 'places', 'A1', 1),
(119, 'ru', 'вода', 'water', 'voda', NULL, 'food-drink', 'A1', 1),
(120, 'ru', 'день', 'day', 'den\'', NULL, 'time', 'A1', 1),
(121, 'hi', 'नमस्ते', 'hello / greetings', 'namaste', NULL, 'greetings', 'A1', 1),
(122, 'hi', 'धन्यवाद', 'thank you', 'dhanyavaad', NULL, 'courtesy', 'A1', 1),
(123, 'hi', 'अलविदा', 'goodbye', 'alvida', NULL, 'courtesy', 'A1', 1),
(124, 'hi', 'नाम', 'name', 'naam', 'मेरा नाम राम है', 'people', 'A1', 1),
(125, 'hi', 'एक', 'one', 'ek', NULL, 'numbers', 'A1', 1),
(126, 'hi', 'दो', 'two', 'do', NULL, 'numbers', 'A1', 1),
(127, 'hi', 'तीन', 'three', 'teen', NULL, 'numbers', 'A1', 1),
(128, 'hi', 'घर', 'house / home', 'ghar', NULL, 'places', 'A1', 1),
(129, 'hi', 'पानी', 'water', 'paani', NULL, 'food-drink', 'A1', 1),
(130, 'hi', 'दिन', 'day', 'din', NULL, 'time', 'A1', 1),
(131, 'tr', 'merhaba', 'hello', NULL, NULL, 'greetings', 'A1', 1),
(132, 'tr', 'teşekkürler', 'thanks', NULL, NULL, 'courtesy', 'A1', 1),
(133, 'tr', 'hoşça kal', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(134, 'tr', 'lütfen', 'please', NULL, NULL, 'courtesy', 'A1', 1),
(135, 'tr', 'bir', 'one', NULL, NULL, 'numbers', 'A1', 1),
(136, 'tr', 'iki', 'two', NULL, NULL, 'numbers', 'A1', 1),
(137, 'tr', 'üç', 'three', NULL, NULL, 'numbers', 'A1', 1),
(138, 'tr', 'ev', 'house / home', NULL, NULL, 'places', 'A1', 1),
(139, 'tr', 'su', 'water', NULL, NULL, 'food-drink', 'A1', 1),
(140, 'tr', 'gün', 'day', NULL, NULL, 'time', 'A1', 1),
(141, 'sw', 'habari', 'hello (greeting)', NULL, NULL, 'greetings', 'A1', 1),
(142, 'sw', 'asante', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(143, 'sw', 'kwaheri', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(144, 'sw', 'karibu', 'welcome', NULL, NULL, 'courtesy', 'A1', 1),
(145, 'sw', 'moja', 'one', NULL, NULL, 'numbers', 'A1', 1),
(146, 'sw', 'mbili', 'two', NULL, NULL, 'numbers', 'A1', 1),
(147, 'sw', 'tatu', 'three', NULL, NULL, 'numbers', 'A1', 1),
(148, 'sw', 'jina', 'name', NULL, 'Jina langu ni Amina.', 'people', 'A1', 1),
(149, 'sw', 'nyumba', 'house', NULL, NULL, 'places', 'A1', 1),
(150, 'sw', 'maji', 'water', NULL, NULL, 'food-drink', 'A1', 1),
(151, 'yo', 'báwo ni?', 'how are you?', NULL, NULL, 'greetings', 'A1', 1),
(152, 'yo', 'ẹ ṣe', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(153, 'yo', 'ọ dàbọ̀', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(154, 'yo', 'ọ̀kan', 'one', NULL, NULL, 'numbers', 'A1', 1),
(155, 'yo', 'èjì', 'two', NULL, NULL, 'numbers', 'A1', 1),
(156, 'yo', 'ẹ̀ta', 'three', NULL, NULL, 'numbers', 'A1', 1),
(157, 'yo', 'ilé', 'house / home', NULL, NULL, 'places', 'A1', 1),
(158, 'yo', 'omí', 'water', NULL, NULL, 'food-drink', 'A1', 1),
(159, 'yo', 'owó', 'money', NULL, NULL, 'everyday', 'A1', 1),
(160, 'yo', 'ọjọ́', 'day', NULL, NULL, 'time', 'A1', 1),
(161, 'ig', 'ndewo', 'hello', NULL, NULL, 'greetings', 'A1', 1),
(162, 'ig', 'daalụ', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(163, 'ig', 'ka ọ dị', 'goodbye (for now)', NULL, NULL, 'courtesy', 'A1', 1),
(164, 'ig', 'otu', 'one', NULL, NULL, 'numbers', 'A1', 1),
(165, 'ig', 'abụọ', 'two', NULL, NULL, 'numbers', 'A1', 1),
(166, 'ig', 'atọ', 'three', NULL, NULL, 'numbers', 'A1', 1),
(167, 'ig', 'ụlọ', 'house', NULL, NULL, 'places', 'A1', 1),
(168, 'ig', 'mmiri', 'water', NULL, NULL, 'food-drink', 'A1', 1),
(169, 'ig', 'ego', 'money', NULL, NULL, 'everyday', 'A1', 1),
(170, 'ig', 'ụbọchị', 'day', NULL, NULL, 'time', 'A1', 1),
(171, 'ha', 'sannu', 'hello', NULL, NULL, 'greetings', 'A1', 1),
(172, 'ha', 'na gode', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(173, 'ha', 'sai anjima', 'see you tomorrow (bye)', NULL, NULL, 'courtesy', 'A1', 1),
(174, 'ha', 'daya', 'one', NULL, NULL, 'numbers', 'A1', 1),
(175, 'ha', 'biyu', 'two', NULL, NULL, 'numbers', 'A1', 1),
(176, 'ha', 'uku', 'three', NULL, NULL, 'numbers', 'A1', 1),
(177, 'ha', 'gida', 'house / home', NULL, NULL, 'places', 'A1', 1),
(178, 'ha', 'ruwa', 'water', NULL, NULL, 'food-drink', 'A1', 1),
(179, 'ha', 'kudi', 'money', NULL, NULL, 'everyday', 'A1', 1),
(180, 'ha', 'rana', 'day / sun', NULL, NULL, 'time', 'A1', 1),
(181, 'af', 'goeie dag', 'good day / hello', NULL, NULL, 'greetings', 'A1', 1),
(182, 'af', 'dankie', 'thank you', NULL, NULL, 'courtesy', 'A1', 1),
(183, 'af', 'totsiens', 'goodbye', NULL, NULL, 'courtesy', 'A1', 1),
(184, 'af', 'asseblief', 'please', NULL, NULL, 'courtesy', 'A1', 1),
(185, 'af', 'een', 'one', NULL, NULL, 'numbers', 'A1', 1),
(186, 'af', 'twee', 'two', NULL, NULL, 'numbers', 'A1', 1),
(187, 'af', 'drie', 'three', NULL, NULL, 'numbers', 'A1', 1),
(188, 'af', 'huis', 'house', NULL, NULL, 'places', 'A1', 1),
(189, 'af', 'water', 'water', NULL, NULL, 'food-drink', 'A1', 1),
(190, 'af', 'stad', 'city', NULL, 'My naam is Pieter.', 'places', 'A1', 1),
(191, 'zu', 'sawubona', 'hello (one person)', NULL, NULL, 'greetings', 'A1', 1),
(192, 'zu', 'ngiyabonga', 'I thank you', NULL, NULL, 'courtesy', 'A1', 1),
(193, 'zu', 'sala kahle', 'goodbye (to one staying)', NULL, NULL, 'courtesy', 'A1', 1),
(194, 'zu', 'kunye', 'one', NULL, NULL, 'numbers', 'A1', 1),
(195, 'zu', 'kubili', 'two', NULL, NULL, 'numbers', 'A1', 1),
(196, 'zu', 'kuthathu', 'three', NULL, NULL, 'numbers', 'A1', 1),
(197, 'zu', 'indlu', 'house', NULL, NULL, 'places', 'A1', 1),
(198, 'zu', 'amanzi', 'water', NULL, NULL, 'food-drink', 'A1', 1),
(199, 'zu', 'imali', 'money', NULL, NULL, 'everyday', 'A1', 1),
(200, 'zu', 'usuku', 'day', NULL, NULL, 'time', 'A1', 1);

-- --------------------------------------------------------

--
-- Table structure for table `writing_attempts`
--

CREATE TABLE `writing_attempts` (
  `id` varchar(36) NOT NULL,
  `profile_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `task_code` varchar(40) NOT NULL,
  `original_text` mediumtext NOT NULL,
  `feedback` longtext NOT NULL,
  `score_pct` decimal(5,2) DEFAULT NULL,
  `created_at` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ai_learning_recommendations`
--
ALTER TABLE `ai_learning_recommendations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reco_profile` (`profile_id`,`created_at`),
  ADD KEY `fk_learning_recommendations_user` (`user_id`),
  ADD KEY `fk_learning_recommendations_language` (`language_code`);

--
-- Indexes for table `analysis_runs`
--
ALTER TABLE `analysis_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_analysis_completed` (`completed_at`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `auth_events`
--
ALTER TABLE `auth_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auth_events_user` (`user_id`,`at`);

--
-- Indexes for table `backtests`
--
ALTER TABLE `backtests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_backtests_strategy` (`strategy_id`,`created_at`);

--
-- Indexes for table `ci_sessions`
--
ALTER TABLE `ci_sessions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `collections`
--
ALTER TABLE `collections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_collection` (`organization_id`,`name`);

--
-- Indexes for table `collection_leads`
--
ALTER TABLE `collection_leads`
  ADD PRIMARY KEY (`collection_id`,`lead_id`),
  ADD KEY `fk_collection_leads_lead` (`lead_id`);

--
-- Indexes for table `contact_messages`
--
ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cm_created` (`created_at`),
  ADD KEY `idx_cm_status` (`status`,`created_at`),
  ADD KEY `idx_cm_email` (`sender_email`),
  ADD KEY `idx_cm_uid` (`uid`),
  ADD KEY `idx_cm_assigned` (`assigned_to`,`status`),
  ADD KEY `idx_cm_unread` (`is_read`,`created_at`);

--
-- Indexes for table `contact_message_replies`
--
ALTER TABLE `contact_message_replies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cmr_message` (`message_id`,`sent_at`);

--
-- Indexes for table `conversation_sessions`
--
ALTER TABLE `conversation_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_conv_profile` (`profile_id`,`started_at`),
  ADD KEY `fk_conversation_user` (`user_id`),
  ADD KEY `fk_conversation_language` (`language_code`);

--
-- Indexes for table `daily_learning_plans`
--
ALTER TABLE `daily_learning_plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_daily_plan` (`profile_id`,`day`),
  ADD KEY `fk_daily_plans_user` (`user_id`),
  ADD KEY `fk_daily_plans_language` (`language_code`);

--
-- Indexes for table `duplicate_candidates`
--
ALTER TABLE `duplicate_candidates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_duplicate_candidates_org` (`organization_id`),
  ADD KEY `fk_duplicate_candidates_a` (`lead_a_id`),
  ADD KEY `fk_duplicate_candidates_b` (`lead_b_id`);

--
-- Indexes for table `duplicate_resolutions`
--
ALTER TABLE `duplicate_resolutions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_duplicate_resolutions_candidate` (`candidate_id`),
  ADD KEY `fk_duplicate_resolutions_org` (`organization_id`),
  ADD KEY `fk_duplicate_resolutions_user` (`resolver_id`);

--
-- Indexes for table `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_et_code` (`code`),
  ADD KEY `idx_et_category` (`category`,`is_active`);

--
-- Indexes for table `export_history`
--
ALTER TABLE `export_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_export_history_org` (`organization_id`),
  ADD KEY `fk_export_history_user` (`user_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_journal_symbol` (`symbol`,`execution_time`),
  ADD KEY `idx_journal_strategy` (`strategy`,`execution_time`),
  ADD KEY `idx_journal_confidence` (`ai_confidence`),
  ADD KEY `fk_journal_backtest` (`backtest_id`),
  ADD KEY `fk_journal_paper_position` (`paper_position_id`);

--
-- Indexes for table `languages`
--
ALTER TABLE `languages`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `language_assessments`
--
ALTER TABLE `language_assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_assessments_profile` (`profile_id`,`started_at`),
  ADD KEY `fk_language_assessments_user` (`user_id`),
  ADD KEY `fk_language_assessments_language` (`language_code`);

--
-- Indexes for table `language_progress`
--
ALTER TABLE `language_progress`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_progress` (`profile_id`,`skill`,`source`),
  ADD KEY `idx_progress_user` (`user_id`),
  ADD KEY `fk_language_progress_language` (`language_code`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lead_source` (`organization_id`,`source`,`source_id`),
  ADD KEY `idx_leads_org_status` (`organization_id`,`status`),
  ADD KEY `idx_leads_owner` (`organization_id`,`owner_id`),
  ADD KEY `idx_leads_created` (`organization_id`,`created_at`),
  ADD KEY `fk_leads_owner` (`owner_id`);

--
-- Indexes for table `lead_activities`
--
ALTER TABLE `lead_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activity_lead` (`organization_id`,`lead_id`,`created_at`),
  ADD KEY `fk_lead_activities_lead` (`lead_id`),
  ADD KEY `fk_lead_activities_actor` (`actor_id`);

--
-- Indexes for table `lead_notes`
--
ALTER TABLE `lead_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notes_lead` (`organization_id`,`lead_id`),
  ADD KEY `fk_lead_notes_lead` (`lead_id`),
  ADD KEY `fk_lead_notes_author` (`author_id`);

--
-- Indexes for table `lead_organizations`
--
ALTER TABLE `lead_organizations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `lead_organization_members`
--
ALTER TABLE `lead_organization_members`
  ADD PRIMARY KEY (`organization_id`,`user_id`),
  ADD KEY `idx_lead_org_members_user` (`user_id`);

--
-- Indexes for table `lead_outreach`
--
ALTER TABLE `lead_outreach`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_outreach_lead` (`organization_id`,`lead_id`,`created_at`);

--
-- Indexes for table `learning_modules`
--
ALTER TABLE `learning_modules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_modules_path` (`path_id`,`sequence`),
  ADD KEY `idx_modules_profile` (`profile_id`),
  ADD KEY `fk_learning_modules_language` (`language_code`);

--
-- Indexes for table `learning_paths`
--
ALTER TABLE `learning_paths`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_paths_profile` (`profile_id`),
  ADD KEY `fk_learning_paths_language` (`language_code`);

--
-- Indexes for table `lesson_attempts`
--
ALTER TABLE `lesson_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attempts_profile` (`profile_id`,`created_at`),
  ADD KEY `fk_lesson_attempts_user` (`user_id`),
  ADD KEY `fk_lesson_attempts_language` (`language_code`);

--
-- Indexes for table `listening_attempts`
--
ALTER TABLE `listening_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_listening_profile` (`profile_id`,`created_at`),
  ADD KEY `fk_listening_user` (`user_id`),
  ADD KEY `fk_listening_language` (`language_code`);

--
-- Indexes for table `lotteries`
--
ALTER TABLE `lotteries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `lottery_ai_decisions`
--
ALTER TABLE `lottery_ai_decisions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lottery_ai_decisions_comb` (`combination_id`),
  ADD KEY `fk_lottery_ai_lottery` (`lottery_code`);

--
-- Indexes for table `lottery_backtests`
--
ALTER TABLE `lottery_backtests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lottery_backtests_strategy` (`strategy`,`created_at`),
  ADD KEY `fk_lottery_backtests_lottery` (`lottery_code`);

--
-- Indexes for table `lottery_combinations`
--
ALTER TABLE `lottery_combinations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lottery_combinations_code` (`lottery_code`,`created_at`);

--
-- Indexes for table `lottery_data_sources`
--
ALTER TABLE `lottery_data_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_code` (`provider_code`);

--
-- Indexes for table `lottery_draws`
--
ALTER TABLE `lottery_draws`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lottery_draws` (`lottery_code`,`external_id`),
  ADD KEY `idx_lottery_draws_date` (`lottery_code`,`draw_date`),
  ADD KEY `fk_lottery_draws_provider` (`provider_id`);

--
-- Indexes for table `lottery_draw_numbers`
--
ALTER TABLE `lottery_draw_numbers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lottery_draw_numbers_draw` (`draw_id`,`kind`,`position`);

--
-- Indexes for table `lottery_model_versions`
--
ALTER TABLE `lottery_model_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lottery_model_versions` (`model_name`,`model_version`);

--
-- Indexes for table `lottery_provider_health`
--
ALTER TABLE `lottery_provider_health`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lottery_provider_health` (`provider_id`,`observed_at`);

--
-- Indexes for table `lottery_rules`
--
ALTER TABLE `lottery_rules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lottery_rules` (`lottery_code`,`version`);

--
-- Indexes for table `lottery_sync_runs`
--
ALTER TABLE `lottery_sync_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `execution_key` (`execution_key`),
  ADD KEY `idx_lottery_sync_runs_job` (`job_type`,`started_at`),
  ADD KEY `fk_lottery_sync_provider` (`provider_id`);

--
-- Indexes for table `lottery_tickets`
--
ALTER TABLE `lottery_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lottery_tickets_user` (`user_id`,`status`),
  ADD KEY `fk_lottery_tickets_lottery` (`lottery_code`);

--
-- Indexes for table `lottery_ticket_lines`
--
ALTER TABLE `lottery_ticket_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lottery_ticket_lines_ticket` (`ticket_id`,`position`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_unread` (`user_id`,`read_at`,`created_at`);

--
-- Indexes for table `paper_accounts`
--
ALTER TABLE `paper_accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `paper_deployments`
--
ALTER TABLE `paper_deployments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_paper_deployments_account` (`account_id`),
  ADD KEY `fk_paper_deployments_strategy` (`strategy_id`,`strategy_version`);

--
-- Indexes for table `paper_orders`
--
ALTER TABLE `paper_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orders_account` (`account_id`,`status`);

--
-- Indexes for table `paper_positions`
--
ALTER TABLE `paper_positions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_positions_account` (`account_id`,`status`);

--
-- Indexes for table `paper_trades`
--
ALTER TABLE `paper_trades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_paper_trades_account` (`account_id`),
  ADD KEY `fk_paper_trades_order` (`order_id`),
  ADD KEY `fk_paper_trades_position` (`position_id`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `platform_state`
--
ALTER TABLE `platform_state`
  ADD PRIMARY KEY (`k`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `idx_rp_permission` (`permission_id`);

--
-- Indexes for table `search_history`
--
ALTER TABLE `search_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_history_org` (`organization_id`,`created_at`),
  ADD KEY `fk_search_history_user` (`user_id`);

--
-- Indexes for table `speaking_attempts`
--
ALTER TABLE `speaking_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_speaking_profile` (`profile_id`,`created_at`),
  ADD KEY `fk_speaking_user` (`user_id`),
  ADD KEY `fk_speaking_language` (`language_code`);

--
-- Indexes for table `sports_backtests`
--
ALTER TABLE `sports_backtests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_backtests_created` (`created_at`);

--
-- Indexes for table `sports_calibrations`
--
ALTER TABLE `sports_calibrations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_calibrations_model` (`model_version_id`,`status`,`created_at`);

--
-- Indexes for table `sports_configurations`
--
ALTER TABLE `sports_configurations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `version` (`version`),
  ADD KEY `idx_sports_config_created` (`created_at`);

--
-- Indexes for table `sports_daily_tickets`
--
ALTER TABLE `sports_daily_tickets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `date` (`date`),
  ADD KEY `fk_sports_daily_ticket` (`ticket_id`),
  ADD KEY `fk_sports_daily_config` (`configuration_version`),
  ADD KEY `fk_sports_daily_run` (`run_id`);

--
-- Indexes for table `sports_data_quality_assessments`
--
ALTER TABLE `sports_data_quality_assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_quality_match` (`match_id`,`assessed_at`);

--
-- Indexes for table `sports_data_sources`
--
ALTER TABLE `sports_data_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `provider_code` (`provider_code`);

--
-- Indexes for table `sports_job_runs`
--
ALTER TABLE `sports_job_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `execution_key` (`execution_key`),
  ADD KEY `idx_sports_job_runs_type` (`job_type`,`started_at`);

--
-- Indexes for table `sports_matches`
--
ALTER TABLE `sports_matches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sports_match_provider_external` (`provider_id`,`external_id`),
  ADD KEY `idx_sports_matches_kickoff` (`kickoff_at`),
  ADD KEY `idx_sports_matches_status` (`status`),
  ADD KEY `idx_sports_matches_provider_kickoff` (`provider_id`,`kickoff_at`);

--
-- Indexes for table `sports_model_metrics`
--
ALTER TABLE `sports_model_metrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_model_metrics` (`model_version_id`,`window_days`,`computed_at`);

--
-- Indexes for table `sports_model_versions`
--
ALTER TABLE `sports_model_versions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sports_model_version` (`model_name`,`model_version`);

--
-- Indexes for table `sports_odds`
--
ALTER TABLE `sports_odds`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_odds_match_market` (`match_id`,`market`,`observed_at`),
  ADD KEY `idx_sports_odds_provider` (`provider_id`,`observed_at`);

--
-- Indexes for table `sports_performance_snapshots`
--
ALTER TABLE `sports_performance_snapshots`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sports_perf_snapshot` (`as_of`,`window`);

--
-- Indexes for table `sports_predictions`
--
ALTER TABLE `sports_predictions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_predictions_match` (`match_id`,`created_at`),
  ADD KEY `idx_sports_predictions_model` (`model_version_id`,`created_at`),
  ADD KEY `idx_sports_predictions_market` (`market`,`created_at`),
  ADD KEY `idx_sports_predictions_created` (`created_at`);

--
-- Indexes for table `sports_provider_health`
--
ALTER TABLE `sports_provider_health`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_provider_health` (`provider_id`,`observed_at`),
  ADD KEY `idx_sports_health_provider` (`provider_id`,`observed_at`);

--
-- Indexes for table `sports_results`
--
ALTER TABLE `sports_results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sports_result_provider_match` (`provider_id`,`match_id`),
  ADD KEY `idx_sports_results_match` (`match_id`,`verified`);

--
-- Indexes for table `sports_sync_runs`
--
ALTER TABLE `sports_sync_runs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `execution_key` (`execution_key`),
  ADD KEY `idx_sports_sync_runs_job` (`job_type`,`started_at`),
  ADD KEY `fk_sports_sync_provider` (`provider_id`);

--
-- Indexes for table `sports_tickets`
--
ALTER TABLE `sports_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_tickets_status` (`status`,`created_at`),
  ADD KEY `fk_sports_tickets_model` (`model_version_id`);

--
-- Indexes for table `sports_ticket_selections`
--
ALTER TABLE `sports_ticket_selections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sports_ticket_selections_ticket` (`ticket_id`),
  ADD KEY `idx_sports_ticket_selections_prediction` (`prediction_id`),
  ADD KEY `idx_sports_selections_market` (`market`,`selection`),
  ADD KEY `idx_sports_selections_match` (`match_id`);

--
-- Indexes for table `strategies`
--
ALTER TABLE `strategies`
  ADD PRIMARY KEY (`strategy_id`,`version`);

--
-- Indexes for table `study_sessions`
--
ALTER TABLE `study_sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sessions_profile_day` (`profile_id`,`day`),
  ADD KEY `fk_study_sessions_user` (`user_id`),
  ADD KEY `fk_study_sessions_language` (`language_code`);

--
-- Indexes for table `trade_executions`
--
ALTER TABLE `trade_executions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_executions_proposal` (`proposal_id`);

--
-- Indexes for table `trade_proposals`
--
ALTER TABLE `trade_proposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_proposals_status` (`status`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_user_uid` (`user_uid`),
  ADD KEY `idx_users_active` (`active`);

--
-- Indexes for table `user_language_profiles`
--
ALTER TABLE `user_language_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_profile_user_language` (`user_id`,`language_code`),
  ADD KEY `fk_profiles_language` (`language_code`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `idx_ur_role` (`role_id`);

--
-- Indexes for table `user_vocabulary`
--
ALTER TABLE `user_vocabulary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_vocabulary` (`profile_id`,`vocabulary_id`),
  ADD KEY `idx_user_vocabulary_due` (`profile_id`,`next_review_at`),
  ADD KEY `fk_user_vocabulary_user` (`user_id`),
  ADD KEY `fk_user_vocabulary_word` (`vocabulary_id`);

--
-- Indexes for table `vocabulary`
--
ALTER TABLE `vocabulary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vocabulary_word` (`language_code`,`word`);

--
-- Indexes for table `writing_attempts`
--
ALTER TABLE `writing_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_writing_profile` (`profile_id`,`created_at`),
  ADD KEY `fk_writing_user` (`user_id`),
  ADD KEY `fk_writing_language` (`language_code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `auth_events`
--
ALTER TABLE `auth_events`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_messages`
--
ALTER TABLE `contact_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contact_message_replies`
--
ALTER TABLE `contact_message_replies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `language_progress`
--
ALTER TABLE `language_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lotteries`
--
ALTER TABLE `lotteries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `lottery_ai_decisions`
--
ALTER TABLE `lottery_ai_decisions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lottery_backtests`
--
ALTER TABLE `lottery_backtests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lottery_combinations`
--
ALTER TABLE `lottery_combinations`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lottery_data_sources`
--
ALTER TABLE `lottery_data_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `lottery_draws`
--
ALTER TABLE `lottery_draws`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lottery_draw_numbers`
--
ALTER TABLE `lottery_draw_numbers`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lottery_model_versions`
--
ALTER TABLE `lottery_model_versions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `lottery_provider_health`
--
ALTER TABLE `lottery_provider_health`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lottery_rules`
--
ALTER TABLE `lottery_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `lottery_tickets`
--
ALTER TABLE `lottery_tickets`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lottery_ticket_lines`
--
ALTER TABLE `lottery_ticket_lines`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paper_accounts`
--
ALTER TABLE `paper_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paper_deployments`
--
ALTER TABLE `paper_deployments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paper_orders`
--
ALTER TABLE `paper_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paper_positions`
--
ALTER TABLE `paper_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `paper_trades`
--
ALTER TABLE `paper_trades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sports_calibrations`
--
ALTER TABLE `sports_calibrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_configurations`
--
ALTER TABLE `sports_configurations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sports_daily_tickets`
--
ALTER TABLE `sports_daily_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_data_quality_assessments`
--
ALTER TABLE `sports_data_quality_assessments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_data_sources`
--
ALTER TABLE `sports_data_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sports_matches`
--
ALTER TABLE `sports_matches`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_model_metrics`
--
ALTER TABLE `sports_model_metrics`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_model_versions`
--
ALTER TABLE `sports_model_versions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_odds`
--
ALTER TABLE `sports_odds`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_performance_snapshots`
--
ALTER TABLE `sports_performance_snapshots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_provider_health`
--
ALTER TABLE `sports_provider_health`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_results`
--
ALTER TABLE `sports_results`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sports_ticket_selections`
--
ALTER TABLE `sports_ticket_selections`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_language_profiles`
--
ALTER TABLE `user_language_profiles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_vocabulary`
--
ALTER TABLE `user_vocabulary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vocabulary`
--
ALTER TABLE `vocabulary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=201;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ai_learning_recommendations`
--
ALTER TABLE `ai_learning_recommendations`
  ADD CONSTRAINT `fk_learning_recommendations_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_learning_recommendations_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_learning_recommendations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `auth_events`
--
ALTER TABLE `auth_events`
  ADD CONSTRAINT `fk_auth_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `collections`
--
ALTER TABLE `collections`
  ADD CONSTRAINT `fk_collections_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`);

--
-- Constraints for table `collection_leads`
--
ALTER TABLE `collection_leads`
  ADD CONSTRAINT `fk_collection_leads_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`),
  ADD CONSTRAINT `fk_collection_leads_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`);

--
-- Constraints for table `conversation_sessions`
--
ALTER TABLE `conversation_sessions`
  ADD CONSTRAINT `fk_conversation_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_conversation_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_conversation_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `daily_learning_plans`
--
ALTER TABLE `daily_learning_plans`
  ADD CONSTRAINT `fk_daily_plans_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_daily_plans_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_daily_plans_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `duplicate_candidates`
--
ALTER TABLE `duplicate_candidates`
  ADD CONSTRAINT `fk_duplicate_candidates_a` FOREIGN KEY (`lead_a_id`) REFERENCES `leads` (`id`),
  ADD CONSTRAINT `fk_duplicate_candidates_b` FOREIGN KEY (`lead_b_id`) REFERENCES `leads` (`id`),
  ADD CONSTRAINT `fk_duplicate_candidates_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`);

--
-- Constraints for table `duplicate_resolutions`
--
ALTER TABLE `duplicate_resolutions`
  ADD CONSTRAINT `fk_duplicate_resolutions_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `duplicate_candidates` (`id`),
  ADD CONSTRAINT `fk_duplicate_resolutions_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`),
  ADD CONSTRAINT `fk_duplicate_resolutions_user` FOREIGN KEY (`resolver_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `export_history`
--
ALTER TABLE `export_history`
  ADD CONSTRAINT `fk_export_history_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`),
  ADD CONSTRAINT `fk_export_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `fk_journal_backtest` FOREIGN KEY (`backtest_id`) REFERENCES `backtests` (`id`),
  ADD CONSTRAINT `fk_journal_paper_position` FOREIGN KEY (`paper_position_id`) REFERENCES `paper_positions` (`id`);

--
-- Constraints for table `language_assessments`
--
ALTER TABLE `language_assessments`
  ADD CONSTRAINT `fk_language_assessments_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_language_assessments_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_language_assessments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `language_progress`
--
ALTER TABLE `language_progress`
  ADD CONSTRAINT `fk_language_progress_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_language_progress_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_language_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `fk_leads_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`),
  ADD CONSTRAINT `fk_leads_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `lead_activities`
--
ALTER TABLE `lead_activities`
  ADD CONSTRAINT `fk_lead_activities_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_lead_activities_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`),
  ADD CONSTRAINT `fk_lead_activities_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`);

--
-- Constraints for table `lead_notes`
--
ALTER TABLE `lead_notes`
  ADD CONSTRAINT `fk_lead_notes_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_lead_notes_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`),
  ADD CONSTRAINT `fk_lead_notes_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`);

--
-- Constraints for table `lead_organization_members`
--
ALTER TABLE `lead_organization_members`
  ADD CONSTRAINT `fk_lead_members_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`),
  ADD CONSTRAINT `fk_lead_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `learning_modules`
--
ALTER TABLE `learning_modules`
  ADD CONSTRAINT `fk_learning_modules_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_learning_modules_path` FOREIGN KEY (`path_id`) REFERENCES `learning_paths` (`id`),
  ADD CONSTRAINT `fk_learning_modules_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`);

--
-- Constraints for table `learning_paths`
--
ALTER TABLE `learning_paths`
  ADD CONSTRAINT `fk_learning_paths_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_learning_paths_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`);

--
-- Constraints for table `lesson_attempts`
--
ALTER TABLE `lesson_attempts`
  ADD CONSTRAINT `fk_lesson_attempts_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_lesson_attempts_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_lesson_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `listening_attempts`
--
ALTER TABLE `listening_attempts`
  ADD CONSTRAINT `fk_listening_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_listening_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_listening_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `lottery_ai_decisions`
--
ALTER TABLE `lottery_ai_decisions`
  ADD CONSTRAINT `fk_lottery_ai_combination` FOREIGN KEY (`combination_id`) REFERENCES `lottery_combinations` (`id`),
  ADD CONSTRAINT `fk_lottery_ai_lottery` FOREIGN KEY (`lottery_code`) REFERENCES `lotteries` (`code`);

--
-- Constraints for table `lottery_backtests`
--
ALTER TABLE `lottery_backtests`
  ADD CONSTRAINT `fk_lottery_backtests_lottery` FOREIGN KEY (`lottery_code`) REFERENCES `lotteries` (`code`);

--
-- Constraints for table `lottery_combinations`
--
ALTER TABLE `lottery_combinations`
  ADD CONSTRAINT `fk_lottery_combinations_lottery` FOREIGN KEY (`lottery_code`) REFERENCES `lotteries` (`code`);

--
-- Constraints for table `lottery_draws`
--
ALTER TABLE `lottery_draws`
  ADD CONSTRAINT `fk_lottery_draws_lottery` FOREIGN KEY (`lottery_code`) REFERENCES `lotteries` (`code`),
  ADD CONSTRAINT `fk_lottery_draws_provider` FOREIGN KEY (`provider_id`) REFERENCES `lottery_data_sources` (`id`);

--
-- Constraints for table `lottery_draw_numbers`
--
ALTER TABLE `lottery_draw_numbers`
  ADD CONSTRAINT `fk_lottery_draw_numbers_draw` FOREIGN KEY (`draw_id`) REFERENCES `lottery_draws` (`id`);

--
-- Constraints for table `lottery_provider_health`
--
ALTER TABLE `lottery_provider_health`
  ADD CONSTRAINT `fk_lottery_health_provider` FOREIGN KEY (`provider_id`) REFERENCES `lottery_data_sources` (`id`);

--
-- Constraints for table `lottery_rules`
--
ALTER TABLE `lottery_rules`
  ADD CONSTRAINT `fk_lottery_rules_lottery` FOREIGN KEY (`lottery_code`) REFERENCES `lotteries` (`code`);

--
-- Constraints for table `lottery_sync_runs`
--
ALTER TABLE `lottery_sync_runs`
  ADD CONSTRAINT `fk_lottery_sync_provider` FOREIGN KEY (`provider_id`) REFERENCES `lottery_data_sources` (`id`);

--
-- Constraints for table `lottery_tickets`
--
ALTER TABLE `lottery_tickets`
  ADD CONSTRAINT `fk_lottery_tickets_lottery` FOREIGN KEY (`lottery_code`) REFERENCES `lotteries` (`code`),
  ADD CONSTRAINT `fk_lottery_tickets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `lottery_ticket_lines`
--
ALTER TABLE `lottery_ticket_lines`
  ADD CONSTRAINT `fk_lottery_ticket_lines_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `lottery_tickets` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `paper_deployments`
--
ALTER TABLE `paper_deployments`
  ADD CONSTRAINT `fk_paper_deployments_account` FOREIGN KEY (`account_id`) REFERENCES `paper_accounts` (`id`),
  ADD CONSTRAINT `fk_paper_deployments_strategy` FOREIGN KEY (`strategy_id`,`strategy_version`) REFERENCES `strategies` (`strategy_id`, `version`);

--
-- Constraints for table `paper_orders`
--
ALTER TABLE `paper_orders`
  ADD CONSTRAINT `fk_paper_orders_account` FOREIGN KEY (`account_id`) REFERENCES `paper_accounts` (`id`);

--
-- Constraints for table `paper_positions`
--
ALTER TABLE `paper_positions`
  ADD CONSTRAINT `fk_paper_positions_account` FOREIGN KEY (`account_id`) REFERENCES `paper_accounts` (`id`);

--
-- Constraints for table `paper_trades`
--
ALTER TABLE `paper_trades`
  ADD CONSTRAINT `fk_paper_trades_account` FOREIGN KEY (`account_id`) REFERENCES `paper_accounts` (`id`),
  ADD CONSTRAINT `fk_paper_trades_order` FOREIGN KEY (`order_id`) REFERENCES `paper_orders` (`id`),
  ADD CONSTRAINT `fk_paper_trades_position` FOREIGN KEY (`position_id`) REFERENCES `paper_positions` (`id`);

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`),
  ADD CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`);

--
-- Constraints for table `search_history`
--
ALTER TABLE `search_history`
  ADD CONSTRAINT `fk_search_history_org` FOREIGN KEY (`organization_id`) REFERENCES `lead_organizations` (`id`),
  ADD CONSTRAINT `fk_search_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `speaking_attempts`
--
ALTER TABLE `speaking_attempts`
  ADD CONSTRAINT `fk_speaking_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_speaking_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_speaking_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `sports_calibrations`
--
ALTER TABLE `sports_calibrations`
  ADD CONSTRAINT `fk_sports_calibration_model` FOREIGN KEY (`model_version_id`) REFERENCES `sports_model_versions` (`id`);

--
-- Constraints for table `sports_daily_tickets`
--
ALTER TABLE `sports_daily_tickets`
  ADD CONSTRAINT `fk_sports_daily_config` FOREIGN KEY (`configuration_version`) REFERENCES `sports_configurations` (`version`),
  ADD CONSTRAINT `fk_sports_daily_run` FOREIGN KEY (`run_id`) REFERENCES `sports_job_runs` (`id`),
  ADD CONSTRAINT `fk_sports_daily_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `sports_tickets` (`id`);

--
-- Constraints for table `sports_data_quality_assessments`
--
ALTER TABLE `sports_data_quality_assessments`
  ADD CONSTRAINT `fk_sports_quality_match` FOREIGN KEY (`match_id`) REFERENCES `sports_matches` (`id`);

--
-- Constraints for table `sports_matches`
--
ALTER TABLE `sports_matches`
  ADD CONSTRAINT `fk_sports_matches_provider` FOREIGN KEY (`provider_id`) REFERENCES `sports_data_sources` (`id`);

--
-- Constraints for table `sports_model_metrics`
--
ALTER TABLE `sports_model_metrics`
  ADD CONSTRAINT `fk_sports_metrics_model` FOREIGN KEY (`model_version_id`) REFERENCES `sports_model_versions` (`id`);

--
-- Constraints for table `sports_odds`
--
ALTER TABLE `sports_odds`
  ADD CONSTRAINT `fk_sports_odds_match` FOREIGN KEY (`match_id`) REFERENCES `sports_matches` (`id`),
  ADD CONSTRAINT `fk_sports_odds_provider` FOREIGN KEY (`provider_id`) REFERENCES `sports_data_sources` (`id`);

--
-- Constraints for table `sports_predictions`
--
ALTER TABLE `sports_predictions`
  ADD CONSTRAINT `fk_sports_predictions_match` FOREIGN KEY (`match_id`) REFERENCES `sports_matches` (`id`),
  ADD CONSTRAINT `fk_sports_predictions_model` FOREIGN KEY (`model_version_id`) REFERENCES `sports_model_versions` (`id`);

--
-- Constraints for table `sports_provider_health`
--
ALTER TABLE `sports_provider_health`
  ADD CONSTRAINT `fk_sports_health_provider` FOREIGN KEY (`provider_id`) REFERENCES `sports_data_sources` (`id`);

--
-- Constraints for table `sports_results`
--
ALTER TABLE `sports_results`
  ADD CONSTRAINT `fk_sports_results_match` FOREIGN KEY (`match_id`) REFERENCES `sports_matches` (`id`),
  ADD CONSTRAINT `fk_sports_results_provider` FOREIGN KEY (`provider_id`) REFERENCES `sports_data_sources` (`id`);

--
-- Constraints for table `sports_sync_runs`
--
ALTER TABLE `sports_sync_runs`
  ADD CONSTRAINT `fk_sports_sync_provider` FOREIGN KEY (`provider_id`) REFERENCES `sports_data_sources` (`id`);

--
-- Constraints for table `sports_tickets`
--
ALTER TABLE `sports_tickets`
  ADD CONSTRAINT `fk_sports_tickets_model` FOREIGN KEY (`model_version_id`) REFERENCES `sports_model_versions` (`id`);

--
-- Constraints for table `sports_ticket_selections`
--
ALTER TABLE `sports_ticket_selections`
  ADD CONSTRAINT `fk_sports_selection_match` FOREIGN KEY (`match_id`) REFERENCES `sports_matches` (`id`),
  ADD CONSTRAINT `fk_sports_selection_prediction` FOREIGN KEY (`prediction_id`) REFERENCES `sports_predictions` (`id`),
  ADD CONSTRAINT `fk_sports_selection_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `sports_tickets` (`id`);

--
-- Constraints for table `study_sessions`
--
ALTER TABLE `study_sessions`
  ADD CONSTRAINT `fk_study_sessions_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_study_sessions_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_study_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `trade_executions`
--
ALTER TABLE `trade_executions`
  ADD CONSTRAINT `fk_trade_executions_proposal` FOREIGN KEY (`proposal_id`) REFERENCES `trade_proposals` (`id`);

--
-- Constraints for table `user_language_profiles`
--
ALTER TABLE `user_language_profiles`
  ADD CONSTRAINT `fk_profiles_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_vocabulary`
--
ALTER TABLE `user_vocabulary`
  ADD CONSTRAINT `fk_user_vocabulary_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_user_vocabulary_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_user_vocabulary_word` FOREIGN KEY (`vocabulary_id`) REFERENCES `vocabulary` (`id`);

--
-- Constraints for table `vocabulary`
--
ALTER TABLE `vocabulary`
  ADD CONSTRAINT `fk_vocabulary_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`);

--
-- Constraints for table `writing_attempts`
--
ALTER TABLE `writing_attempts`
  ADD CONSTRAINT `fk_writing_language` FOREIGN KEY (`language_code`) REFERENCES `languages` (`code`),
  ADD CONSTRAINT `fk_writing_profile` FOREIGN KEY (`profile_id`) REFERENCES `user_language_profiles` (`id`),
  ADD CONSTRAINT `fk_writing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
