-- =============================================================================
--  Default data for a new installation
-- -----------------------------------------------------------------------------
--  * The system "Name" field. Its key `nazev` is fixed: the app uses it as the
--    record title (lists, history, export). The label can be renamed in the app.
--  * Public basemaps: OpenStreetMap (default, worldwide) and two optional
--    Czech national layers from ČÚZK (the Czech Office for Surveying, Mapping and
--    Cadastre). Edit, reorder or delete them in the app (Manage layers).
--
--  All other attribute fields are defined by the administrator in the app
--  (Manage metadata) – no code or schema changes needed.
--
--  Safe to run repeatedly (INSERT IGNORE on unique keys).
--  Import:  mysql -u <user> -p <database> < db/seed.sql   (or: php tools/install.php)
--  Accounts are NOT created here (passwords must be hashed) – use tools/install.php.
-- =============================================================================

SET NAMES utf8mb4;

INSERT IGNORE INTO field_definitions
    (field_key, label, help_text, data_type, options, is_required, validation, applies_to, default_value, sort_order, is_active)
VALUES
    ('nazev', 'Name', 'Name of the feature (shown in lists and history).', 'text',
        NULL, 1, JSON_OBJECT('maxLength', 200), NULL, NULL, 0, 1);

INSERT IGNORE INTO map_layers
    (layer_key, title, type, url, capabilities_url, wms_layers, subdomains, format, version, projection,
     wmts_config, attribution, min_zoom, max_zoom, sort_order)
VALUES
    ('osm', 'OpenStreetMap', 'xyz',
        'https://tile.openstreetmap.org/{z}/{x}/{y}.png', NULL, NULL, NULL, NULL, NULL, NULL,
        NULL, '© OpenStreetMap contributors', 0, 19, 1),

    ('cuzk_zm', 'Czech Base Map (ČÚZK)', 'wmts',
        'https://ags.cuzk.gov.cz/arcgis1/rest/services/ZTM_WM/MapServer/WMTS/tile/1.0.0/ZTM_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
        'https://ags.cuzk.gov.cz/arcgis1/rest/services/ZTM_WM/MapServer/WMTS/1.0.0/WMTSCapabilities.xml',
        NULL, NULL, 'image/jpgpng', NULL, 'EPSG:3857',
        '{"requestEncoding":"REST","layer":"ZTM_WM","matrixSet":"GoogleMapsCompatible","style":"default","format":"image/jpgpng","tileGrid":{"origin":[-20037508.34278925,20037508.34278925],"tileSize":256,"matrixIds":["0","1","2","3","4","5","6","7","8","9","10","11","12","13","14","15","16","17","18"],"resolutions":[156543.03392804097,78271.51696402048,39135.758482010235,19567.87924100512,9783.93962050256,4891.96981025128,2445.98490512564,1222.9924525628198,611.4962262814101,305.74811314070485,152.8740565703525,76.43702828517624,38.218514142588134,19.109257071294063,9.554628535647032,4.777314267823516,2.388657133911758,1.194328566955879,0.5971642834779395]}}',
        '© ČÚZK', 0, 18, 2),

    ('cuzk_ortofoto', 'Czech Orthophoto (ČÚZK)', 'wmts',
        'https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/WMTS/tile/1.0.0/ORTOFOTO_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
        'https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/WMTS/1.0.0/WMTSCapabilities.xml',
        NULL, NULL, 'image/jpgpng', NULL, 'EPSG:3857',
        '{"requestEncoding":"REST","layer":"ORTOFOTO_WM","matrixSet":"GoogleMapsCompatible","style":"default","format":"image/jpgpng","tileGrid":{"origin":[-20037508.34278925,20037508.34278925],"tileSize":256,"matrixIds":["0","1","2","3","4","5","6","7","8","9","10","11","12","13","14","15","16","17","18"],"resolutions":[156543.03392804097,78271.51696402048,39135.758482010235,19567.87924100512,9783.93962050256,4891.96981025128,2445.98490512564,1222.9924525628198,611.4962262814101,305.74811314070485,152.8740565703525,76.43702828517624,38.218514142588134,19.109257071294063,9.554628535647032,4.777314267823516,2.388657133911758,1.194328566955879,0.5971642834779395]}}',
        '© ČÚZK', 0, 18, 3);
