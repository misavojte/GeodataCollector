-- -----------------------------------------------------------------------------
--  Migration: Zoom range for map layers (min_zoom, max_zoom)
--
--  Allows defining the minimum and maximum zoom level of a layer.
--  For XYZ layers above max_zoom, OpenLayers automatically upscales tiles
--  (oversampling) without sending 404 requests for non-existent levels.
--
--  Idempotent (ADD COLUMN IF NOT EXISTS). MariaDB 10.2+.
--  Apply:  mysql -u <user> -p <db> < db/migrate_layer_zoom_bounds.sql
-- -----------------------------------------------------------------------------

ALTER TABLE map_layers
    ADD COLUMN IF NOT EXISTS min_zoom TINYINT UNSIGNED NULL AFTER attribution,
    ADD COLUMN IF NOT EXISTS max_zoom TINYINT UNSIGNED NULL AFTER min_zoom;
