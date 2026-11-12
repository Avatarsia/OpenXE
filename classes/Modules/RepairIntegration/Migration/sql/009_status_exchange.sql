CREATE TABLE IF NOT EXISTS repair_status_exchange (
  ticket_id INT NOT NULL PRIMARY KEY,
  native_status VARCHAR(50) NOT NULL,
  request_number VARCHAR(255) NOT NULL,
  status VARCHAR(50) NOT NULL,
  event_id CHAR(36) NOT NULL,
  origin VARCHAR(10) NOT NULL,
  changed_at_utc DATETIME(6) NOT NULL,
  revision BIGINT UNSIGNED NOT NULL,
  history_id INT NOT NULL DEFAULT 0,
  UNIQUE KEY event_id (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS repair_status_exchange_events (
  event_id CHAR(36) NOT NULL PRIMARY KEY,
  ticket_id INT NOT NULL,
  payload TEXT NOT NULL,
  KEY ticket_id (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE repair_sync_queue MODIFY action VARCHAR(50) NOT NULL;
INSERT IGNORE INTO systemconfig (`namespace`, `key`, `value`) VALUES ('repair_integration', 'sync_v2_enabled', '0');
