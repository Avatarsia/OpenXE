-- Upgrade 1.0.0 -> 1.1.0
-- Bringt bestehende Installationen auf den Stand von 002_seed_status_config.sql.
-- Fresh-Installs fuehren nur 001 + 002 aus, dieses File ist dort ein No-Op.
--
-- Idempotenz: INSERT IGNORE fuer neue Zeilen (UNIQUE KEY auf `slug`),
-- UPDATEs sind auf genau das alte Seed-Wertepaar eingegrenzt, damit
-- Admin-Anpassungen an diesen Zeilen nicht ueberschrieben werden.

INSERT IGNORE INTO `ticket_status_config`
  (`slug`, `label_de`, `category`, `sort_order`, `wp_status_mapping`, `next_status_slug`, `notify_customer`, `is_terminal`)
VALUES
  ('kv_abgelehnt', 'Kostenvoranschlag abgelehnt', 'repair', 115, 'quote_declined', 'versendet', 1, 0),
  ('re_abgelehnt', 'RE: Angebot abgelehnt', 'reverse_engineering', 315, 'quote_declined', 'versendet', 1, 0),
  ('ind_abgelehnt', 'Individualisierung: Angebot abgelehnt', 'individualization', 415, 'quote_declined', 'versendet', 1, 0);

-- Neu: OpenXE-Arbeitsstatus meldet 'in_repair' an WP (Slug existiert dort ab Plugin-Update).
UPDATE `ticket_status_config`
  SET `wp_status_mapping` = 'in_repair'
  WHERE `slug` = 'in_reparatur' AND `wp_status_mapping` IS NULL;

-- Geplante Wartung ist keine Diagnose -> kein WP-Echo.
UPDATE `ticket_status_config`
  SET `wp_status_mapping` = NULL
  WHERE `slug` = 'wartung_geplant' AND `wp_status_mapping` = 'in_diagnosis';

-- Laufende Wartung ist Arbeit am Geraet, nicht 'approved'.
UPDATE `ticket_status_config`
  SET `wp_status_mapping` = 'in_repair'
  WHERE `slug` = 'wartung_laeuft' AND `wp_status_mapping` = 'approved';

UPDATE `ticket_status_config`
  SET `wp_status_mapping` = 'in_repair'
  WHERE `slug` = 're_umsetzung' AND `wp_status_mapping` IS NULL;

UPDATE `ticket_status_config`
  SET `wp_status_mapping` = 'in_repair'
  WHERE `slug` = 'ind_fertigung' AND `wp_status_mapping` IS NULL;

-- 'Warten auf Kunde' hat keinen WP-Status -> Mail ohne Frontend-Aenderung waere inkonsistent.
UPDATE `ticket_status_config`
  SET `notify_customer` = 0
  WHERE `slug` = 'warten_kd' AND `notify_customer` = 1;

-- Freigabe-Status melden wie 'freigegeben' (repair) an den Kunden.
UPDATE `ticket_status_config`
  SET `notify_customer` = 1
  WHERE `slug` IN ('re_freigabe', 'ind_freigabe') AND `notify_customer` = 0;
