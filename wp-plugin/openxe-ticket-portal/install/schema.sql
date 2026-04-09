-- OpenXE Ticket Portal: required database tables
--
-- Run this once against your OpenXE database before activating the
-- ticket portal feature. The OpenXE Ticket::Install() method does NOT
-- create these tables automatically.
--
-- Source: extracted from Avatarsia/OpenXE branch `ticketwebsite`,
-- database/struktur.sql, ranges around line 15316.

-- ----------------------------------------------------------------------
-- Customer access tokens (PLZ/email verification, magic links, session)
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_portal_access` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `scope` varchar(32) NOT NULL,
  `verifier_type` varchar(32) DEFAULT NULL,
  `verifier_hash` varchar(255) DEFAULT NULL,
  `verifier_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `last_access_at` datetime DEFAULT NULL,
  `last_access_ip` varchar(64) DEFAULT NULL,
  `last_access_ua` varchar(255) DEFAULT NULL,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `last_failed_at` datetime DEFAULT NULL,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `token_hash` (`token_hash`),
  KEY `scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------
-- Portal-side messages (mirrored to ticket_nachricht where applicable)
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_portal_message` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `author_type` varchar(16) NOT NULL,
  `author_id` int(11) DEFAULT NULL,
  `text` text NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `source` varchar(32) NOT NULL DEFAULT 'portal',
  `mirrored_message_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `is_public` (`is_public`),
  KEY `mirrored_message_id` (`mirrored_message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------
-- Customer-facing status (decoupled from internal ticket.status)
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_customer_status` (
  `ticket_id` int(11) NOT NULL,
  `status_key` varchar(64) NOT NULL,
  `status_label` varchar(255) NOT NULL,
  `updated_at` datetime NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------
-- Status change audit log
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_status_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `status_from` varchar(64) DEFAULT NULL,
  `status_to` varchar(64) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime NOT NULL,
  `note_public` text DEFAULT NULL,
  `note_internal` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------
-- Offer accept/reject confirmations including DOI flow
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_offer_confirmation` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `angebot_id` int(11) NOT NULL,
  `action` varchar(16) NOT NULL,
  `comment` text DEFAULT NULL,
  `agb_version` varchar(64) DEFAULT NULL,
  `agb_accepted_at` datetime DEFAULT NULL,
  `doi_token_hash` varchar(255) DEFAULT NULL,
  `doi_requested_at` datetime DEFAULT NULL,
  `doi_confirmed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `created_by_type` varchar(16) NOT NULL,
  `created_by_id` int(11) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `angebot_id` (`angebot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- ----------------------------------------------------------------------
-- Per-customer notification opt-in/out for status events
-- ----------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ticket_notification_pref` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `status_key` varchar(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
