-- =============================================================================
--  Migration: attachments (photos and documents) on records
-- -----------------------------------------------------------------------------
--  1. New dynamic field types 'photos' and 'files'.
--  2. Table attachments – metadata of uploaded files. Files are stored on disk
--     in storage/uploads/ (protected by .htaccess, served by PHP after login).
--     features.attributes only holds a list [{id,name,size}] under the field key,
--     so version history (revisions) works unchanged. Files are never deleted.
-- =============================================================================

SET NAMES utf8mb4;

ALTER TABLE field_definitions MODIFY COLUMN data_type
    ENUM('text','textarea','number','integer','boolean','date','select','multiselect','hierarchy','photos','files')
    NOT NULL DEFAULT 'text';

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
