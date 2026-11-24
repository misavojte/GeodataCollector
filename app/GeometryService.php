<?php
declare(strict_types=1);

/**
 * Geometry conversion and validation.
 *  - On the wire (with the browser) we speak GeoJSON.
 *  - SQL uses WKT (ST_GeomFromText / ST_AsText) – the most portable option
 *    between MySQL 8 and MariaDB. On disk the geometry is stored as WKB.
 *  - Coordinates are WGS-84 (lon, lat), i.e. X = longitude, Y = latitude, SRID 0.
 */
final class GeometryService
{
    /** Maps the internal type to the GeoJSON type. */
    private const GEOJSON_TYPE = [
        'point'   => 'Point',
        'line'    => 'LineString',
        'polygon' => 'Polygon',
    ];

    /** Abuse safeguard – maximum number of points in a single geometry. */
    private const MAX_POINTS = 50000;

    // ----------------------------------------------------- GeoJSON -> WKT ----

    public function geoJsonToWkt(array $geometry, string $geometryType): string
    {
        $expected = self::GEOJSON_TYPE[$geometryType] ?? null;
        if ($expected === null) {
            $this->fail('geom_type_unknown', 'Unknown geometry type: ' . $geometryType, ['type' => $geometryType]);
        }

        $type = is_string($geometry['type'] ?? null) ? $geometry['type'] : '';
        if (strcasecmp($type, $expected) !== 0) {
            $this->fail('geom_type_mismatch', "Geometry type \"{$type}\" does not match the expected \"{$expected}\".", ['type' => $type, 'expected' => $expected]);
        }

        $coords = $geometry['coordinates'] ?? null;
        if (!is_array($coords)) {
            $this->fail('geom_coords_missing', 'Geometry coordinates are missing.');
        }

        switch ($geometryType) {
            case 'point':
                return 'POINT(' . $this->formatPair($this->pair($coords)) . ')';

            case 'line':
                $pts = $this->lineString($coords);
                return 'LINESTRING(' . $this->joinPairs($pts) . ')';

            case 'polygon':
                $rings = $this->polygonRings($coords);
                $parts = [];
                foreach ($rings as $ring) {
                    $parts[] = '(' . $this->joinPairs($ring) . ')';
                }
                return 'POLYGON(' . implode(',', $parts) . ')';
        }

        $this->fail('geom_type_unsupported', 'Unsupported geometry type.'); // unreachable
    }

    // ----------------------------------------------------- WKT -> GeoJSON ----

    public function wktToGeoJson(string $wkt): array
    {
        $wkt = trim($wkt);

        if (preg_match('/^POINT\s*\(([^()]*)\)$/i', $wkt, $m)) {
            return ['type' => 'Point', 'coordinates' => $this->parsePair($m[1])];
        }
        if (preg_match('/^LINESTRING\s*\((.*)\)$/is', $wkt, $m)) {
            return ['type' => 'LineString', 'coordinates' => $this->parseList($m[1])];
        }
        if (preg_match('/^POLYGON\s*\((.*)\)$/is', $wkt, $m)) {
            $rings = [];
            if (preg_match_all('/\(([^()]*)\)/', $m[1], $rm)) {
                foreach ($rm[1] as $ringStr) {
                    $rings[] = $this->parseList($ringStr);
                }
            }
            return ['type' => 'Polygon', 'coordinates' => $rings];
        }

        $this->fail('geom_wkt_unreadable', 'Failed to read the geometry from the database (WKT).');
    }

    // --------------------------------------------------------- helpers -------

    /** @return array{0:float,1:float} */
    private function pair($value): array
    {
        if (!is_array($value) || count($value) < 2 || !isset($value[0], $value[1])) {
            $this->fail('geom_pair_invalid', 'A coordinate must be a [longitude, latitude] pair.');
        }
        $lon = $value[0];
        $lat = $value[1];
        if (!is_numeric($lon) || !is_numeric($lat)) {
            $this->fail('geom_coords_not_numeric', 'Coordinates must be numbers.');
        }
        $lon = (float) $lon;
        $lat = (float) $lat;
        if ($lon < -180.0 || $lon > 180.0 || $lat < -90.0 || $lat > 90.0) {
            $this->fail('geom_coords_out_of_range', 'Coordinates out of valid range (longitude ±180, latitude ±90).');
        }
        return [$lon, $lat];
    }

    /** @return array<int, array{0:float,1:float}> */
    private function lineString($coords): array
    {
        if (!is_array($coords) || count($coords) < 2) {
            $this->fail('geom_line_too_short', 'A line needs at least two points.');
        }
        $pts = [];
        foreach ($coords as $c) {
            $pts[] = $this->pair($c);
        }
        $this->assertPointCount(count($pts));
        return $pts;
    }

    /** @return array<int, array<int, array{0:float,1:float}>> */
    private function polygonRings($coords): array
    {
        if (!is_array($coords) || count($coords) < 1) {
            $this->fail('geom_polygon_no_ring', 'A polygon needs at least one ring.');
        }
        $total = 0;
        $rings = [];
        foreach ($coords as $ring) {
            if (!is_array($ring)) {
                $this->fail('geom_ring_invalid', 'Invalid polygon ring.');
            }
            $pts = [];
            foreach ($ring as $c) {
                $pts[] = $this->pair($c);
            }
            if (count($pts) < 3) {
                $this->fail('geom_ring_too_short', 'A polygon ring needs at least three points.');
            }
            // Close the ring if the first and last points are not identical.
            $first = $pts[0];
            $last = $pts[count($pts) - 1];
            if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
                $pts[] = $first;
            }
            $total += count($pts);
            $this->assertPointCount($total);
            $rings[] = $pts;
        }
        return $rings;
    }

    private function assertPointCount(int $count): void
    {
        if ($count > self::MAX_POINTS) {
            $this->fail('geom_too_many_points', 'The geometry contains too many points.');
        }
    }

    /** @param array<int, array{0:float,1:float}> $pairs */
    private function joinPairs(array $pairs): string
    {
        return implode(',', array_map([$this, 'formatPair'], $pairs));
    }

    /** @param array{0:float,1:float} $pair */
    private function formatPair(array $pair): string
    {
        return $this->formatNum($pair[0]) . ' ' . $this->formatNum($pair[1]);
    }

    private function formatNum(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.8f', $n), '0'), '.');
        return ($s === '' || $s === '-0') ? '0' : $s;
    }

    /** Turns a WKT string "lon lat" into [lon, lat]. */
    private function parsePair(string $s): array
    {
        $parts = preg_split('/\s+/', trim($s));
        if ($parts === false || count($parts) < 2) {
            $this->fail('geom_wkt_pair_invalid', 'Invalid coordinate pair in WKT.');
        }
        return [(float) $parts[0], (float) $parts[1]];
    }

    /** Turns a WKT list "lon lat, lon lat" into an array of pairs. */
    private function parseList(string $s): array
    {
        $out = [];
        foreach (explode(',', trim($s)) as $part) {
            if (trim($part) === '') {
                continue;
            }
            $out[] = $this->parsePair($part);
        }
        return $out;
    }

    private function fail(string $code, string $message, array $params = []): void
    {
        throw new HttpException($message, 422, $code, [], $params);
    }
}
