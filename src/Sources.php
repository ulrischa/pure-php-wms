<?php

declare(strict_types=1);

namespace PureWms;

use PhpMyAdmin\ShapeFile\{ShapeFile, ShapeType};
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

final class Sources
{
    public static function features(string $path, array $source): iterable
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'geojson','json' => self::geojson($path),
            'shp' => self::shapefile($path, $source['encoding'] ?? 'UTF-8'),
            'gpkg' => self::geopackage($path, $source),
            default => throw new \RuntimeException('Supported sources: .shp, .geojson, .gpkg.'),
        };
    }

    private static function geojson(string $path): \Generator
    {
        $type = iterator_to_array(Items::fromFile($path, ['pointer' => '/type']));
        // JsonMachine's scalar pointer returns one value.
        if (!in_array('FeatureCollection', $type, true)) {
            throw new \RuntimeException('GeoJSON root must be a FeatureCollection.');
        }
        foreach (Items::fromFile($path, ['pointer' => '/features','decoder' => new ExtJsonDecoder(true)]) as $feature) {
            if (($feature['type'] ?? '') !== 'Feature') {
                throw new \RuntimeException('Expected GeoJSON Feature.');
            }
            yield $feature;
        }
    }

    private static function shapefile(string $path, string $encoding): \Generator
    {
        if (filesize($path) > 16777216) {
            throw new \RuntimeException('Shapefiles are limited to 16 MiB for the in-memory reader. Split larger files or export GeoJSON.');
        }
        self::checkShp($path);
        $memory = trim(ini_get('memory_limit'));
        if ($memory !== '-1') {
            $unit = strtolower(substr($memory, -1));
            $available = (float)$memory * (['g' => 1073741824,'m' => 1048576,'k' => 1024][$unit] ?? 1);
            if (memory_get_usage(true) + filesize($path) * 100 > $available * 0.8) {
                throw new \RuntimeException('Not enough PHP memory for this SHP import. Import locally with a higher memory_limit or export GeoJSON.');
            }
        }
        $shape = new ShapeFile(ShapeType::Point);
        if (!$shape->loadFromFile(substr($path, 0, -4) . '.*')) {
            throw new \RuntimeException('Shapefile: ' . $shape->lastError);
        }
        $dbf = Dbf::rows(substr($path, 0, -4) . '.dbf', $encoding);
        $dbf->rewind();
        foreach ($shape->records as $record) {
            if (!$dbf->valid()) {
                throw new \RuntimeException('SHP has more records than DBF.');
            }
            if ($record->lastError !== '') {
                throw new \RuntimeException('Malformed SHP record: ' . $record->lastError);
            }
            $properties = $dbf->current();
            $dbf->next();
            if ($properties === null || $record->shapeType === ShapeType::Null) {
                continue;
            }
            $data = $record->shpData;
            $type = $record->shapeType->value % 10;
            $point = static fn ($p) => [(float)$p['x'],(float)$p['y']];
            $geometry = match ($type) {
                1 => ['type' => 'Point','coordinates' => $point($data)],
                8 => ['type' => 'MultiPoint','coordinates' => array_map($point, $data['points'])],
                3 => ['type' => 'MultiLineString','coordinates' => array_map(static fn ($part) => array_map($point, $part['points']), $data['parts'])],
                5 => self::polygon(array_map(static fn ($part) => array_map($point, $part['points']), $data['parts'])),
                default => throw new \RuntimeException('Unsupported shapefile geometry.'),
            };
            yield ['type' => 'Feature','id' => $record->recordNumber,'geometry' => $geometry,'properties' => $properties];
        }
        if ($dbf->valid()) {
            throw new \RuntimeException('DBF has more rows than SHP.');
        }
    }

    private static function polygon(array $rings): array
    {
        // Classify nesting instead of relying on ring orientation in third-party exports.
        $parents = [];
        $areas = [];
        foreach ($rings as $i => $ring) {
            $area = 0;
            for ($j = 1;$j < count($ring);++$j) {
                $area += $ring[$j - 1][0] * $ring[$j][1] - $ring[$j][0] * $ring[$j - 1][1];
            } $areas[$i] = abs($area);
        }
        foreach ($rings as $i => $ring) {
            $parents[$i] = null;
            foreach ($rings as $j => $outer) {
                if ($areas[$j] > $areas[$i] && Geometry::inside($ring[0], [$outer]) && ($parents[$i] === null || $areas[$j] < $areas[$parents[$i]])) {
                    $parents[$i] = $j;
                }
            }
        }
        $polygons = [];
        $depths = [];
        foreach ($rings as $i => $ring) {
            $depth = 0;
            $parent = $parents[$i];
            while ($parent !== null) {
                ++$depth;
                $parent = $parents[$parent];
            } $depths[$i] = $depth;
            if ($depth % 2 === 0) {
                $polygons[$i] = [$ring];
            }
        }
        foreach ($rings as $i => $ring) {
            if ($depths[$i] % 2 === 1) {
                $polygons[$parents[$i]][] = $ring;
            }
        }

        return ['type' => 'MultiPolygon','coordinates' => array_values($polygons)];
    }

    private static function checkShp(string $path): void
    {
        $file = fopen($path, 'rb');
        try {
            $header = fread($file, 100);
            $size = filesize($path);
            if (strlen($header) !== 100 || unpack('N', substr($header, 0, 4))[1] !== 9994 || unpack('N', substr($header, 24, 4))[1] * 2 !== $size) {
                throw new \RuntimeException('Invalid SHP header or length.');
            }
            $records = 0;
            while (ftell($file) < $size) {
                $head = fread($file, 8);
                if (strlen($head) !== 8 || ++$records > 100000) {
                    throw new \RuntimeException('Invalid SHP record count.');
                }
                $length = unpack('N', substr($head, 4, 4))[1] * 2;
                if ($length < 4 || $length > $size - ftell($file)) {
                    throw new \RuntimeException('Truncated SHP record.');
                }
                $raw = fread($file, $length);
                $type = unpack('V', substr($raw, 0, 4))[1];
                if (!in_array($type, [0,1,3,5,8,11,13,15,18,21,23,25,28], true)) {
                    throw new \RuntimeException('Unsupported SHP type.');
                }
                $base = $type % 10;
                if ($base === 1 && $length < ([1 => 20,11 => 36,21 => 28][$type])) {
                    throw new \RuntimeException('Truncated point.');
                }
                if (in_array($base, [3,5,8], true)) {
                    if ($length < ($base === 8 ? 40 : 44)) {
                        throw new \RuntimeException('Truncated SHP geometry.');
                    }
                    $parts = $base === 8 ? 0 : unpack('V', substr($raw, 36, 4))[1];
                    $points = unpack('V', substr($raw, $base === 8 ? 36 : 40, 4))[1];
                    $minimum = ($base === 8 ? 40 : 44) + 4 * $parts + 16 * $points;
                    if ($parts > 1000 || $points > 250000 || ($base !== 8 && (($points > 0 && $parts === 0) || $parts > $points)) || $minimum > $length) {
                        throw new \RuntimeException('SHP geometry limits or length exceeded.');
                    }
                    if ($base !== 8) {
                        $previous = -1;
                        for ($i = 0;$i < $parts;++$i) {
                            $start = unpack('V', substr($raw, 44 + $i * 4, 4))[1];
                            if ($start <= $previous || $start >= $points || ($i === 0 && $start !== 0)) {
                                throw new \RuntimeException('Invalid SHP part index.');
                            } $previous = $start;
                        }
                    }
                    if ($type >= 10 && $type < 20 && $length < $minimum + 16 + 8 * $points) {
                        throw new \RuntimeException('Truncated SHP Z values.');
                    }
                }
            }
        } finally {
            fclose($file);
        }
    }

    private static function geopackage(string $path, array $source): \Generator
    {
        if (!isset($source['table']) || !is_string($source['table'])) {
            throw new \RuntimeException('GeoPackage requires a table setting.');
        }
        $pdo = new \PDO('sqlite:file:' . str_replace('%2F', '/', rawurlencode($path)) . '?mode=ro');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA query_only=ON; PRAGMA trusted_schema=OFF');
        $query = $pdo->prepare('SELECT column_name,srs_id FROM gpkg_geometry_columns WHERE table_name=?');
        $query->execute([$source['table']]);
        $meta = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$meta) {
            throw new \RuntimeException('Not a GeoPackage feature table.');
        }
        if ('EPSG:' . $meta['srs_id'] !== $source['crs']) {
            throw new \RuntimeException('GeoPackage CRS must match configured EPSG code.');
        }
        $quote = static fn ($value) => '"' . str_replace('"', '""', $value) . '"';
        $query = $pdo->query('SELECT * FROM ' . $quote($source['table']));
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            $blob = $row[$meta['column_name']];
            unset($row[$meta['column_name']]);
            if ($blob === null) {
                continue;
            }
            if (strlen($blob) > 16777216) {
                throw new \RuntimeException('GeoPackage geometry too large.');
            }
            $geometry = Wkb::geopackage($blob, (int)$meta['srs_id']);
            if ($geometry !== null) {
                yield ['type' => 'Feature','geometry' => $geometry,'properties' => $row];
            }
        }
    }
}
