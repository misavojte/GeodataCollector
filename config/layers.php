<?php
/**
 * Map layer registry (data-driven configuration).
 * ---------------------------------------------------------------------------
 * Adding / changing a layer = editing this array. The frontend loads the list via
 * GET /api/layers and builds the control panel itself (base layer switcher + overlays
 * with an opacity slider).
 *
 * Types:
 *   kind: 'base'    – base layer (always exactly one active, switcher)
 *         'overlay' – overlay layer (toggleable, with opacity)
 *   type: 'wms'     – WMS service (Leaflet L.tileLayer.wms)
 *         'xyz'     – tile service {z}/{x}/{y}
 *
 * Private (authenticated) WMS:
 *   set 'proxy' => true and fill in 'proxy_auth'. Tiles are then routed through
 *   our server (api.php → wms-proxy), so the credentials NEVER leave the
 *   server and never reach the browser. See app/controllers/LayerController.php.
 *
 * Note: please verify the ČÚZK (Czech cadastral office) service URLs against the current geoportal offering
 *       (https://geoportal.cuzk.cz) – they may change over time. OpenStreetMap is
 *       included as a reliable fallback base layer.
 */

return [

    // ----------------------------------------------------------- BASE LAYERS --

    [
        'id'              => 'cuzk_zm',
        'title'           => 'Czech Base Map (ČÚZK)',
        'kind'            => 'base',
        'type'            => 'wmts',
        'url'             => 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ZTM_WM/MapServer/WMTS/tile/1.0.0/ZTM_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
        'capabilities_url' => 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ZTM_WM/MapServer/WMTS/1.0.0/WMTSCapabilities.xml',
        'format'          => 'image/jpgpng',
        'projection'      => 'EPSG:3857',
        'attribution'     => '© ČÚZK',
        'default_visible' => true,   // default base layer
        'min_zoom'        => 0,
        'max_zoom'        => 18,
        'wmts'            => [
            'url'             => 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ZTM_WM/MapServer/WMTS/tile/1.0.0/ZTM_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
            'requestEncoding' => 'REST',
            'layer'           => 'ZTM_WM',
            'matrixSet'       => 'GoogleMapsCompatible',
            'style'           => 'default',
            'format'          => 'image/jpgpng',
            'projection'      => 'EPSG:3857',
            'tileGrid'        => [
                'origin'      => [-20037508.34278925, 20037508.34278925],
                'tileSize'    => 256,
                'matrixIds'   => ['0','1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16','17','18'],
                'resolutions' => [
                    156543.03392804097, 78271.51696402048, 39135.758482010235, 19567.87924100512,
                    9783.93962050256, 4891.96981025128, 2445.98490512564, 1222.9924525628198,
                    611.4962262814101, 305.74811314070485, 152.8740565703525, 76.43702828517624,
                    38.218514142588134, 19.109257071294063, 9.554628535647032, 4.777314267823516,
                    2.388657133911758, 1.194328566955879, 0.5971642834779395
                ],
            ],
        ],
    ],

    [
        'id'              => 'cuzk_ortofoto',
        'title'           => 'Czech Orthophoto (ČÚZK)',
        'kind'            => 'base',
        'type'            => 'wmts',
        'url'             => 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/WMTS/tile/1.0.0/ORTOFOTO_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
        'capabilities_url' => 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/WMTS/1.0.0/WMTSCapabilities.xml',
        'format'          => 'image/jpgpng',
        'projection'      => 'EPSG:3857',
        'attribution'     => '© ČÚZK',
        'default_visible' => false,
        'min_zoom'        => 0,
        'max_zoom'        => 18,
        'wmts'            => [
            'url'             => 'https://ags.cuzk.gov.cz/arcgis1/rest/services/ORTOFOTO_WM/MapServer/WMTS/tile/1.0.0/ORTOFOTO_WM/{Style}/{TileMatrixSet}/{TileMatrix}/{TileRow}/{TileCol}',
            'requestEncoding' => 'REST',
            'layer'           => 'ORTOFOTO_WM',
            'matrixSet'       => 'GoogleMapsCompatible',
            'style'           => 'default',
            'format'          => 'image/jpgpng',
            'projection'      => 'EPSG:3857',
            'tileGrid'        => [
                'origin'      => [-20037508.34278925, 20037508.34278925],
                'tileSize'    => 256,
                'matrixIds'   => ['0','1','2','3','4','5','6','7','8','9','10','11','12','13','14','15','16','17','18'],
                'resolutions' => [
                    156543.03392804097, 78271.51696402048, 39135.758482010235, 19567.87924100512,
                    9783.93962050256, 4891.96981025128, 2445.98490512564, 1222.9924525628198,
                    611.4962262814101, 305.74811314070485, 152.8740565703525, 76.43702828517624,
                    38.218514142588134, 19.109257071294063, 9.554628535647032, 4.777314267823516,
                    2.388657133911758, 1.194328566955879, 0.5971642834779395
                ],
            ],
        ],
    ],

    [
        'id'              => 'osm',
        'title'           => 'OpenStreetMap',
        'kind'            => 'base',
        'type'            => 'xyz',
        'url_template'    => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution'     => '© OpenStreetMap contributors',
        'default_visible' => false,
        'max_zoom'        => 19,
    ],

    // -------------------------------------------------------------- OVERLAYS --

    /*
     * EXAMPLE of a private historical scan as WMS through the proxy.
     * Uncomment and fill in real values. Tiles will go through our server,
     * 'proxy_auth' stays on the server.
     *
    [
        'id'              => 'histmap_1842',
        'title'           => 'Historical map (1842 scan)',
        'kind'            => 'overlay',
        'type'            => 'wms',
        'url'             => 'https://private-server.example/geoserver/wms',
        'layers'          => 'workspace:scan_1842',
        'format'          => 'image/png',
        'transparent'     => true,
        'version'         => '1.3.0',
        'attribution'     => 'Scan: internal source',
        'default_visible' => false,
        'default_opacity' => 70,            // %
        'proxy'           => true,          // route tiles through our server
        'proxy_auth'      => [
            'type' => 'basic',              // HTTP Basic
            'user' => 'username',
            'pass' => 'password',
            // optional extra parameters appended to the upstream URL:
            'extra_query' => [],            // e.g. ['token' => 'xyz']
        ],
    ],
    */

    // Example of a public overlay without the proxy (usable as-is):
    // [
    //     'id'              => 'military_survey_2',
    //     'title'           => 'Second Military Survey',
    //     'kind'            => 'overlay',
    //     'type'            => 'wms',
    //     'url'             => 'https://wms.example/wms',
    //     'layers'          => 'survey_2',
    //     'format'          => 'image/png',
    //     'transparent'     => true,
    //     'version'         => '1.3.0',
    //     'default_visible' => false,
    //     'default_opacity' => 60,
    // ],

];
