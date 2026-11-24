-- -----------------------------------------------------------------------------
--  Migration: WMTS layers + arbitrary CRS (S-JTSK / EPSG:5514 etc.)
--
--  Extends map_layers with the 'wmts' type and with columns that hold the RESULT
--  of the setup (GetCapabilities is parsed only when creating/editing a layer in
--  the admin; it is never called while the map is running – the client builds the
--  OL source solely from this stored data).
--
--    capabilities_url – GetCapabilities URL used during setup (for re-editing and
--                       server-side re-validation of the selection on save)
--    projection       – target EPSG code of the service (e.g. EPSG:5514); NULL = EPSG:3857
--    crs_config       – proj4 definition + units/axis orientation for registering
--                       an unknown CRS in OpenLayers (NULL for built-in 3857/4326)
--    wmts_config      – complete WMTS source configuration (requestEncoding, layer,
--                       matrixSet, style, precomputed grid: origin, resolutions,
--                       matrixIds, tileSize)
--
--  Idempotent (ADD COLUMN IF NOT EXISTS). MariaDB 10.2+.
--  Apply:  mysql -u <user> -p <db> < db/migrate_wmts.sql
-- -----------------------------------------------------------------------------

ALTER TABLE map_layers
    MODIFY COLUMN type ENUM('wms','xyz','wmts') NOT NULL DEFAULT 'wms';

ALTER TABLE map_layers
    ADD COLUMN IF NOT EXISTS capabilities_url VARCHAR(1024) NULL AFTER url,
    ADD COLUMN IF NOT EXISTS projection       VARCHAR(32)   NULL AFTER version,
    ADD COLUMN IF NOT EXISTS crs_config       JSON          NULL AFTER projection,
    ADD COLUMN IF NOT EXISTS wmts_config      JSON          NULL AFTER crs_config;
