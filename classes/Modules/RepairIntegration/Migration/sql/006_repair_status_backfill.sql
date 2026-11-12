-- Durable backfill marker, separate from schema version. Cron resumes it.
INSERT IGNORE INTO systemconfig (`namespace`, `key`, `value`)
VALUES ('repair_integration', 'status_backfill_state', 'pending')
