-- =============================================================================
--  Performance index migration (for an EXISTING database).
-- -----------------------------------------------------------------------------
--  A new site does NOT NEED this – schema.sql already contains the target state.
--  Run it only when upgrading an older installation. Idempotent (IF [NOT] EXISTS,
--  MariaDB 10.5+).
--
--  Replaces the single-column idx_features_created_by with a composite
--  (created_by, deleted_at, id): it covers the "mine only" filter and its
--  ordering without a filesort, and the leading created_by column keeps an
--  index for the foreign key.
-- =============================================================================

ALTER TABLE features ADD INDEX IF NOT EXISTS idx_features_created_deleted (created_by, deleted_at, id);
ALTER TABLE features DROP INDEX IF EXISTS idx_features_created_by;
