-- =============================================================================
--  Geodata Collector
--  Database schema
-- -----------------------------------------------------------------------------
--  Compatible with MySQL 8.0+ and MariaDB 10.2.7+.
--
--  Notes on spatial data:
--   * Geometry is stored in the native GEOMETRY type (on disk in WKB format).
--   * We use SRID 0 (Cartesian) and store WGS-84 coordinates (lon, lat)
--     numerically as X = longitude, Y = latitude. SRID 0 behaves identically
--     in MySQL and MariaDB and has no axis-order issues like SRID 4326.
--   * All SQL input/output goes through WKT (ST_GeomFromText / ST_AsText),
--     which is the most portable. GeoJSON <-> WKT conversion happens in PHP.
--   * DELIBERATELY NO SPATIAL INDEX: the R-tree requires predicate locks, which
--     InnoDB refuses under READ UNCOMMITTED (SQLSTATE 25000 / 1207) – and shared hosting
--     gives sessions exactly READ UNCOMMITTED and cannot even keep an explicit SET SESSION
--     (the proxy swaps connections mid-request). Row-by-row MBRIntersects takes
--     a few ms at realistic volumes (hundreds to thousands of records).
--     Details: db/migrate_drop_spatial_index.sql.
--
--  Import (phpMyAdmin → Import, or CLI):
--      mysql -u <user> -p <database> < db/schema.sql
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -----------------------------------------------------------------------------
--  Users
--  The role is in the model from the start for the future admin module (no migration).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    username       VARCHAR(64)       NOT NULL,
    email          VARCHAR(190)      NULL,
    password_hash  VARCHAR(255)      NOT NULL,
    display_name   VARCHAR(120)      NOT NULL,
    role           ENUM('collector','admin') NOT NULL DEFAULT 'collector',
    is_active      TINYINT(1)        NOT NULL DEFAULT 1,
    created_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at  TIMESTAMP         NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  Login attempt limiting (simple brute-force protection)
--  Old entries are pruned by the application (Auth::pruneLoginAttempts).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)     NOT NULL,
    ip_address    VARCHAR(45)     NOT NULL,   -- textual IP (IPv4 and IPv6)
    success       TINYINT(1)      NOT NULL DEFAULT 0,
    attempted_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attempts_user_time (username, attempted_at),
    KEY idx_attempts_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  Dynamic field definitions (metadata registry)
--  Both the forms and the server-side attribute validation are generated from this table.
--  Changing the field set = INSERT/UPDATE here, WITHOUT a schema migration of the features table.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS field_definitions (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    field_key     VARCHAR(64)   NOT NULL,                     -- key in the JSON attributes (a-z, 0-9, _)
    label         VARCHAR(190)  NOT NULL,                     -- field label (display text)
    help_text     VARCHAR(500)  NULL,                         -- help text shown below the field
    data_type     ENUM('text','textarea','number','integer','boolean','date','select','multiselect','hierarchy','photos','files')
                                NOT NULL DEFAULT 'text',
    options       JSON          NULL,                         -- for select/multiselect: [{"value":"x","label":"X"}, ...]
    is_required   TINYINT(1)    NOT NULL DEFAULT 0,
    validation    JSON          NULL,                         -- {"min":..,"max":..,"maxLength":..,"pattern":".."}
    applies_to    JSON          NULL,                         -- restriction to geometry types: ["point","line","polygon"] or NULL = all
    default_value JSON          NULL,                         -- default value (any JSON scalar)
    sort_order    INT           NOT NULL DEFAULT 0,
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_field_key (field_key),
    KEY idx_field_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  Map layers (data-driven registry, managed by the administrator)
--   * A single ordered list – the admin reorders it by drag and drop. The FIRST one
--     is the default basemap when the map loads. No "kind" (base/overlay): any
--     layer can be used in the panel as a basemap or as an overlay.
--   * Default layers are seeded (db/seed.sql / db/migrate_map_layers.sql);
--     the admin can freely edit and delete them – full control.
--   * PUBLIC services only (no credentials). Private WMS still goes through
--     config/layers.php + wms-proxy (credentials stay on the server).
--   * Fallback when the table is empty/missing: config/layers.php (the map is never without a basemap).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS map_layers (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    layer_key        VARCHAR(64)   NOT NULL,                  -- stable id for the frontend (a-z, 0-9, _)
    title            VARCHAR(190)  NOT NULL,
    type             ENUM('wms','xyz','wmts') NOT NULL DEFAULT 'wms',
    url              VARCHAR(1024) NOT NULL,                  -- WMS GetMap / WMTS GetTile URL or XYZ template {z}/{x}/{y}
    capabilities_url VARCHAR(1024) NULL,                      -- GetCapabilities URL from setup (re-edit + server-side re-validation)
    wms_layers       VARCHAR(255)  NULL,                      -- WMS LAYERS parameter / WMTS layer identifier
    subdomains       VARCHAR(16)   NULL,                      -- for XYZ {s}
    format           VARCHAR(40)   NULL,                      -- FORMAT (image/png, image/jpeg)
    version          VARCHAR(10)   NULL,                      -- WMS VERSION (default 1.3.0)
    projection       VARCHAR(32)   NULL,                      -- target EPSG code (WMS/WMTS reprojection); NULL = EPSG:3857
    crs_config       JSON          NULL,                      -- proj4 def + units/axis for registering an unknown CRS (NULL for 3857/4326)
    wmts_config      JSON          NULL,                      -- WMTS source: requestEncoding, layer, matrixSet, style, grid
    attribution      VARCHAR(255)  NULL,
    min_zoom         TINYINT UNSIGNED NULL,                   -- optional zoom range (mainly for XYZ)
    max_zoom         TINYINT UNSIGNED NULL,                   -- above max_zoom OL upscales tiles (oversampling)
    sort_order    INT           NOT NULL DEFAULT 0,           -- order (admin drags); first = default basemap
    parent_id     INT UNSIGNED  NULL,                         -- parent layer (linking groups / area patches)
    created_by    INT UNSIGNED  NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_map_layers_key (layer_key),
    KEY idx_map_layers_order (sort_order),
    KEY idx_map_layers_parent (parent_id),
    CONSTRAINT fk_map_layers_parent FOREIGN KEY (parent_id) REFERENCES map_layers (id) ON DELETE CASCADE,
    CONSTRAINT fk_map_layers_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  Collected geometries (core)
--   * geom    – native GEOMETRY (WKB), SRID 0, lon/lat coordinates (WGS-84)
--   * attributes – dynamic descriptive data per field_definitions (JSON)
--   * status  – prepared for a future approval workflow
--   * deleted_at – soft delete (records are never physically deleted)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS features (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    geometry_type ENUM('point','line','polygon') NOT NULL,
    geom          GEOMETRY        NOT NULL,
    attributes    JSON            NOT NULL,
    status        ENUM('draft','submitted','reviewed') NOT NULL DEFAULT 'submitted',
    created_by    INT UNSIGNED    NOT NULL,
    updated_by    INT UNSIGNED    NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMP       NULL,
    PRIMARY KEY (id),
    -- WARNING: geom has no SPATIAL index, DELIBERATELY (hosting + READ UNCOMMITTED → 1207;
    -- see the notes in the header and db/migrate_drop_spatial_index.sql).
    -- (created_by, deleted_at, id): covers the "mine only" filter (created_by + deleted_at IS NULL) and
    -- its ORDER BY id without a filesort; the leading created_by column also serves as the FK index.
    KEY idx_features_created_deleted (created_by, deleted_at, id),
    KEY idx_features_type (geometry_type),
    KEY idx_features_deleted (deleted_at),
    CONSTRAINT fk_features_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_features_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  Record version history (audit trail + ability to revert changes)
--   * On every change (create/update/delete/restore) a SNAPSHOT of the state
--     (geometry + attributes) is stored. It can be used to revert a record to an
--     older version or to restore an accidentally (soft-)deleted record.
--   * History is never deleted (full audit trail). No FK to features (survives
--     even a physical deletion of the record). geom has no spatial index –
--     queries go by feature_id.
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
--  Attachments (photos and documents). Files live in storage/uploads/, attributes hold only [{id,name,size}].
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    feature_id    BIGINT UNSIGNED NULL,          -- NULL = uploaded, record not saved yet
    field_key     VARCHAR(64)     NOT NULL,
    kind          ENUM('photo','file') NOT NULL,
    storage_path  VARCHAR(64)     NOT NULL,      -- e.g. 2026/11/<32 hex> (no extension)
    ext           VARCHAR(10)     NOT NULL,      -- original file extension
    original_name VARCHAR(255)    NOT NULL,
    mime          VARCHAR(100)    NOT NULL,
    size_bytes    INT UNSIGNED    NOT NULL,
    width         INT UNSIGNED    NULL,
    height        INT UNSIGNED    NULL,
    taken_at      DATETIME        NULL,          -- EXIF capture date
    gps_lat       DOUBLE          NULL,          -- EXIF GPS (stored only)
    gps_lon       DOUBLE          NULL,
    created_by    INT UNSIGNED    NOT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_att_feature (feature_id),
    KEY idx_att_user (created_by, feature_id),
    CONSTRAINT fk_att_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  Audit log (lightweight) – who, what, when. Supports a future admin overview.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED    NULL,
    action      VARCHAR(64)     NOT NULL,    -- e.g. feature.create, feature.update, feature.delete, auth.login
    entity_type VARCHAR(64)     NULL,        -- feature / field / user ...
    entity_id   BIGINT UNSIGNED NULL,
    payload     JSON            NULL,        -- additional data (no sensitive information)
    ip_address  VARCHAR(45)     NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_user (user_id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
--  Ground Control Points (GCP) for georeferencing
--   * Point pair: geom_map (old map) and geom_ref (reference basemap)
--   * geom_map and geom_ref – native GEOMETRY (WKB Point), SRID 0, lon/lat
-- -----------------------------------------------------------------------------
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

