CREATE TABLE /*_*/cowlender_event (
  cwe_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cwe_title VARBINARY(1020) NOT NULL,
  cwe_description MEDIUMBLOB NOT NULL,
  cwe_location VARBINARY(2000) NOT NULL,
  cwe_start_utc BINARY(14) DEFAULT NULL,
  cwe_end_utc BINARY(14) DEFAULT NULL,
  cwe_start_date BINARY(8) DEFAULT NULL,
  cwe_end_date BINARY(8) DEFAULT NULL,
  cwe_timezone VARBINARY(64) NOT NULL,
  cwe_all_day TINYINT UNSIGNED NOT NULL DEFAULT 0,
  cwe_status VARBINARY(16) NOT NULL,
  cwe_category VARBINARY(64) DEFAULT NULL,
  cwe_external_url BLOB DEFAULT NULL,
  cwe_recurrence_rule BLOB DEFAULT NULL,
  cwe_created_by BIGINT UNSIGNED NOT NULL,
  cwe_created_by_name VARBINARY(255) NOT NULL,
  cwe_created_at BINARY(14) NOT NULL,
  cwe_updated_by BIGINT UNSIGNED NOT NULL,
  cwe_updated_by_name VARBINARY(255) NOT NULL,
  cwe_updated_at BINARY(14) NOT NULL,
  cwe_version INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (cwe_id)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/cowlender_event_timed_range
  ON /*_*/cowlender_event (cwe_all_day, cwe_start_utc, cwe_end_utc);

CREATE INDEX /*i*/cowlender_event_all_day_range
  ON /*_*/cowlender_event (cwe_all_day, cwe_start_date, cwe_end_date);

CREATE INDEX /*i*/cowlender_event_creator
  ON /*_*/cowlender_event (cwe_created_by, cwe_id);

CREATE INDEX /*i*/cowlender_event_category
  ON /*_*/cowlender_event (cwe_category, cwe_start_utc);
