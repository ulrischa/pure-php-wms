<?php

declare(strict_types=1);

namespace PureWms;

use PhpMyAdmin\ShapeFile\ShapeType;
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
        $dbf = Dbf::rows(substr($path, 0, -4) . '.dbf', $encoding);
        $dbf->rewind();
        foreach (ShpReader::records($path) as $record) {
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
