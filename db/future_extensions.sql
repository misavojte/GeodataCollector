-- =============================================================================
--  DRAFT OF FUTURE EXTENSIONS – DO NOT APPLY YET
-- -----------------------------------------------------------------------------
--  This file is NOT part of a regular deployment. It demonstrates that the data
--  model is designed so that a future "work assignment over bounding boxes"
--  module and administration can be added purely additively (no changes to
--  existing tables).
--
--  When the time comes, just create these tables and add the Assignments module.
-- =============================================================================

-- Defined work areas (rectangles / polygons of interest).
CREATE TABLE IF NOT EXISTS work_areas (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name        VARCHAR(190)    NOT NULL,
    description TEXT            NULL,
    area        GEOMETRY        NOT NULL,        -- boundary polygon (SRID 0, lon/lat)
    created_by  INT UNSIGNED    NOT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- WARNING when implementing: NO SPATIAL INDEX! On hosting with READ UNCOMMITTED
    -- (which cannot be reliably switched) the R-tree throws error 1207 – the same
    -- reason the index was dropped on features (db/migrate_drop_spatial_index.sql).
    -- There will be a few dozen areas → row-by-row MBRContains is perfectly fine.
    CONSTRAINT fk_work_areas_user FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assignment of a work area to a specific user.
CREATE TABLE IF NOT EXISTS assignments (
    id            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    work_area_id  INT UNSIGNED   NOT NULL,
    assigned_to   INT UNSIGNED   NOT NULL,
    assigned_by   INT UNSIGNED   NOT NULL,
    status        ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
    note          TEXT           NULL,
    due_date      DATE           NULL,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assign_user (assigned_to, status),
    CONSTRAINT fk_assign_area FOREIGN KEY (work_area_id) REFERENCES work_areas (id),
    CONSTRAINT fk_assign_to   FOREIGN KEY (assigned_to)  REFERENCES users (id),
    CONSTRAINT fk_assign_by   FOREIGN KEY (assigned_by)  REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Example of a future query "how many features are in the assigned area":
--   SELECT COUNT(*) FROM features f
--   JOIN work_areas w ON MBRContains(w.area, f.geom)
--   WHERE w.id = :area_id AND f.deleted_at IS NULL;
