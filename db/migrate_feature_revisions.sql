-- =============================================================================
--  Migration: feature_revisions table (version history + reverting changes)
-- -----------------------------------------------------------------------------
--  Run on an EXISTING database (fresh installs get everything from schema.sql).
--  Idempotent (CREATE TABLE IF NOT EXISTS).
--
--      mysql -u <user> -p <database> < db/migrate_feature_revisions.sql
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS feature_revisions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feature_id    BIGINT UNSIGNED NOT NULL,
    action        ENUM('create','update','delete','restore') NOT NULL,
    geometry_type ENUM('point','line','polygon') NOT NULL,
    geom          GEOMETRY        NOT NULL,
    attributes    JSON            NOT NULL,
    changed_by    INT UNSIGNED    NULL,
    changed_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_frev_feature (feature_id, id),
    KEY idx_frev_changed (changed_at),
    CONSTRAINT fk_frev_changed_by FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
