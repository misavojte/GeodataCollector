-- =============================================================================
--  Geodata Collector
--  Migration: Ground Control Points (GCP) for georeferencing
-- -----------------------------------------------------------------------------
--  Creates a separate table for recording reference point pairs linking
--  a historical map to a reference basemap (cadastre, orthophoto).
--  Idempotent script (IF NOT EXISTS).
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gcp_pairs (
    id             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    label          VARCHAR(255)     NOT NULL,                  -- required label / site name
    description    TEXT             NULL,                      -- optional note / description
    geom_map       GEOMETRY         NOT NULL,                  -- point on the historical map (SRID 0, lon/lat)
    geom_ref       GEOMETRY         NOT NULL,                  -- point on the reference basemap (SRID 0, lon/lat)
    created_by     INT UNSIGNED     NOT NULL,
    updated_by     INT UNSIGNED     NULL,
    created_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at     TIMESTAMP        NULL,                      -- soft-delete
    PRIMARY KEY (id),
    KEY idx_gcp_created_deleted (created_by, deleted_at, id),
    CONSTRAINT fk_gcp_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_gcp_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
