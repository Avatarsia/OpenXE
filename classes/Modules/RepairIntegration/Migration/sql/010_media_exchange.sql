CREATE TABLE IF NOT EXISTS repair_media_exchange (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  ticket_id int NOT NULL,
  origin_id varchar(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  file_id int NOT NULL,
  version_id int NOT NULL,
  sha256 char(64) CHARACTER SET ascii NOT NULL,
  uploaded_at_utc varchar(32) NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY origin (ticket_id, origin_id), UNIQUE KEY origin_owner (origin_id), KEY content (ticket_id, sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS repair_exchange_jobs (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  mode varchar(10) NOT NULL,
  state varchar(20) NOT NULL DEFAULT 'running',
  remote_cursor int NOT NULL DEFAULT 0,
  local_cursor int NOT NULL DEFAULT 0,
  phase varchar(10) NOT NULL DEFAULT 'remote',
  last_error text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS repair_exchange_results (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  job_id bigint unsigned NOT NULL,
  request_number varchar(20) NOT NULL,
  ticket_id int DEFAULT NULL,
  state varchar(30) NOT NULL,
  result_json mediumtext NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY request_job (job_id, request_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS repair_exchange_pending (
  ticket_id int NOT NULL,
  attempts int NOT NULL DEFAULT 0,
  last_error text DEFAULT NULL,
  next_retry_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  generation bigint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY(ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS repair_media_failures (
  ticket_id int NOT NULL,
  origin_id varchar(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  direction varchar(12) NOT NULL,
  attempts int NOT NULL DEFAULT 0,
  last_attempt datetime NOT NULL,
  next_retry_at datetime NOT NULL,
  last_error text NOT NULL,
  PRIMARY KEY(ticket_id,origin_id,direction)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO systemconfig (`namespace`,`key`,`value`) VALUES ('repair_integration','sync_instance_id',UUID());
