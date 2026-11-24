-- =============================================================================
-- Migration: Switch the ČÚZK Base Map and Orthophoto from dynamic WMS to the
--            fast pre-rendered WMTS service (GoogleMapsCompatible / EPSG:3857).
-- =============================================================================

UPDATE map_layers SET
    type = 'wmts',
    url = 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ZTM_WM/MapServer/WMTS/tile/1.0.0/ZTM_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
    capabilities_url = 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ZTM_WM/MapServer/WMTS/1.0.0/WMTSCapabilities.xml',
    format = 'image/jpgpng',
    projection = 'EPSG:3857',
    min_zoom = 0,
    max_zoom = 18,
    wmts_config = '{"requestEncoding":"REST","layer":"ZTM_WM","matrixSet":"GoogleMapsCompatible","style":"default","format":"image/jpgpng","tileGrid":{"origin":[-20037508.34278925,20037508.34278925],"tileSize":256,"matrixIds":["0","1","2","3","4","5","6","7","8","9","10","11","12","13","14","15","16","17","18"],"resolutions":[156543.03392804097,78271.51696402048,39135.758482010235,19567.87924100512,9783.93962050256,4891.96981025128,2445.98490512564,1222.9924525628198,611.4962262814101,305.74811314070485,152.8740565703525,76.43702828517624,38.218514142588134,19.109257071294063,9.554628535647032,4.777314267823516,2.388657133911758,1.194328566955879,0.5971642834779395]}}'
WHERE layer_key = 'cuzk_zm';

UPDATE map_layers SET
    type = 'wmts',
    url = 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/WMTS/tile/1.0.0/ORTOFOTO_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
    capabilities_url = 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/WMTS/1.0.0/WMTSCapabilities.xml',
    format = 'image/jpgpng',
    projection = 'EPSG:3857',
    min_zoom = 0,
    max_zoom = 18,
    wmts_config = '{"requestEncoding":"REST","layer":"ORTOFOTO_WM","matrixSet":"GoogleMapsCompatible","style":"default","format":"image/jpgpng","tileGrid":{"origin":[-20037508.34278925,20037508.34278925],"tileSize":256,"matrixIds":["0","1","2","3","4","5","6","7","8","9","10","11","12","13","14","15","16","17","18"],"resolutions":[156543.03392804097,78271.51696402048,39135.758482010235,19567.87924100512,9783.93962050256,4891.96981025128,2445.98490512564,1222.9924525628198,611.4962262814101,305.74811314070485,152.8740565703525,76.43702828517624,38.218514142588134,19.109257071294063,9.554628535647032,4.777314267823516,2.388657133911758,1.194328566955879,0.5971642834779395]}}'
WHERE layer_key = 'cuzk_ortofoto';
