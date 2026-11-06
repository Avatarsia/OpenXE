-- Upgrade 1.2.0 -> 1.3.0
-- Neue Spalte customer_quote_amount: vom Kunden im WP-Frontend freigegebener
-- KVA-Preis, wird inbound per Push geliefert und im Repair-Panel angezeigt.
--
-- Idempotenz: Spalte nur anlegen, wenn sie fehlt (information_schema-Check
-- plus PREPARE), auf frischen Installationen ist die Spalte bereits in
-- 001_create_tables.sql enthalten und dieses File ein No-Op.
--
-- Hinweis: keine Semikolons in Kommentaren, executeSqlFile() splittet naiv
-- am Semikolon und wuerde sonst ein leeres Statement absetzen.

SET @customer_quote_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'ticket_repair_details'
    AND COLUMN_NAME = 'customer_quote_amount'
);

SET @customer_quote_alter := IF(
  @customer_quote_col = 0,
  'ALTER TABLE `ticket_repair_details` ADD COLUMN `customer_quote_amount` DECIMAL(10,2) DEFAULT NULL AFTER `actual_cost`',
  'SELECT 1'
);

PREPARE stmt FROM @customer_quote_alter;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
