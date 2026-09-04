CREATE TABLE /*_*/cowlender_event_revision (
  cwr_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cwr_event_id BIGINT UNSIGNED NOT NULL,
  cwr_event_version INT UNSIGNED NOT NULL,
  cwr_action VARBINARY(16) NOT NULL,
  cwr_actor_id BIGINT UNSIGNED NOT NULL,
  cwr_actor_name VARBINARY(255) NOT NULL,
  cwr_changed_at BINARY(14) NOT NULL,
  cwr_snapshot MEDIUMBLOB NOT NULL,
  PRIMARY KEY (cwr_id)
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/cowlender_revision_event_version
  ON /*_*/cowlender_event_revision (cwr_event_id, cwr_event_version);

CREATE INDEX /*i*/cowlender_revision_actor
  ON /*_*/cowlender_event_revision (cwr_actor_id, cwr_changed_at);
