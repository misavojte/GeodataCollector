-- =============================================================================
--  Migration: map_layers table (map layer management by the administrator)
-- -----------------------------------------------------------------------------
--  A single ordered list of layers; the admin reorders it by drag and drop, and
--  the first one is the default basemap. No "kind" (base/overlay) – any layer can
--  be used as a basemap or an overlay. Only PUBLIC services are stored (no passwords).
--
--  Run on an EXISTING database (fresh installs get everything from schema.sql +
--  seed.sql). Idempotent – safe to run repeatedly.
--
--      mysql -u <user> -p <database> < db/migrate_map_layers.sql
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS map_layers (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    layer_key     VARCHAR(64)   NOT NULL,
    title         VARCHAR(190)  NOT NULL,
    type          ENUM('wms','xyz') NOT NULL DEFAULT 'wms',
    url           VARCHAR(1024) NOT NULL,
    wms_layers    VARCHAR(255)  NULL,
    subdomains    VARCHAR(16)   NULL,
    format        VARCHAR(40)   NULL,
    version       VARCHAR(10)   NULL,
    attribution   VARCHAR(255)  NULL,
    sort_order    INT           NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED  NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_map_layers_key (layer_key),
    KEY idx_map_layers_order (sort_order),
    CONSTRAINT fk_map_layers_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO map_layers
    (layer_key, title, type, url, wms_layers, subdomains, format, version, attribution, sort_order)
VALUES
    ('cuzk_zm', 'Základní mapa ČR (ČÚZK)', 'wms',
        'https://ags.cuzk.gov.cz/arcgis1/services/ZTM/MapServer/WMSServer', '0', NULL, 'image/png', '1.3.0', '© ČÚZK', 1),
    ('cuzk_ortofoto', 'Ortofoto ČR – letecký snímek (ČÚZK)', 'wms',
        'https://ags.cuzk.gov.cz/arcgis1/services/ORTOFOTO/MapServer/WMSServer', '0', NULL, 'image/jpeg', '1.3.0', '© ČÚZK', 2),
    ('osm', 'OpenStreetMap (záloha)', 'xyz',
        'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', NULL, 'abc', NULL, NULL, '© přispěvatelé OpenStreetMap', 3);
