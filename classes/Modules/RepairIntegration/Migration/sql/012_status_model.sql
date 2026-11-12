INSERT IGNORE INTO `ticket_status_config`
  (`slug`, `label_de`, `category`, `sort_order`, `wp_status_mapping`, `next_status_slug`, `notify_customer`, `is_terminal`)
VALUES
  ('eingegangen', 'Gerät eingegangen', 'general', 15, 'received', NULL, 1, 0),
  ('storniert', 'Storniert', 'general', 950, 'cancelled', NULL, 1, 1);

UPDATE `ticket_status_config` SET `wp_status_mapping` = 'in_progress' WHERE `slug` IN ('in_diagnose','freigegeben','in_reparatur','wartung_laeuft','re_analyse','re_freigabe','re_umsetzung','ind_planung','ind_freigabe','ind_fertigung');
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'completed' WHERE `slug` IN ('kv_abgelehnt','repariert','versendet','wartung_fertig','re_abgelehnt','re_fertig','ind_abgelehnt','ind_fertig');
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'quote_sent' WHERE `slug` IN ('kv_gesendet','re_angebot','ind_angebot');
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'new' WHERE `slug` IN ('neu');
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'closed' WHERE `slug` IN ('abgeschlossen');
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'received' WHERE `slug` IN ('eingegangen');
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'cancelled' WHERE `slug` IN ('storniert');

UPDATE `ticket_status_config` SET `next_status_slug` = 'eingegangen' WHERE `slug` = 'neu' AND `next_status_slug` = 'offen';
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'in_progress' WHERE `wp_status_mapping` = 'in_diagnosis';
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'in_progress' WHERE `wp_status_mapping` = 'approved';
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'in_progress' WHERE `wp_status_mapping` = 'in_repair';
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'completed' WHERE `wp_status_mapping` = 'quote_declined';
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'completed' WHERE `wp_status_mapping` = 'repaired';
UPDATE `ticket_status_config` SET `wp_status_mapping` = 'completed' WHERE `wp_status_mapping` = 'returned';
