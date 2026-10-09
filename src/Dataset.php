<?php

declare(strict_types=1);

namespace PureWms;

final class Dataset
{
    public const MAX_FEATURE_VERTICES = 20000;
    public const MAX_GEOMETRY_BYTES = 1048576;
    private \PDO $pdo;
    public readonly array $metadata;

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Dataset not imported. Run the import command first.');
        }
        $this->pdo = new \PDO('sqlite:file:' . str_replace('%2F', '/', rawurlencode($path)) . '?mode=ro');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA query_only=ON; PRAGMA trusted_schema=OFF; PRAGMA busy_timeout=1000');
        $this->metadata = json_decode($this->pdo->query('SELECT value FROM metadata')->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        if (($this->metadata['format'] ?? null) !== 1) {
            throw new \RuntimeException('Unsupported dataset format.');
        }
    }

    public function query(array $bbox, int $limit): \Generator
    {
        $query = $this->pdo->prepare('SELECT f.id,CASE WHEN length(f.geometry)<=1048576 THEN f.geometry ELSE NULL END AS geometry,CASE WHEN length(f.properties)<=65536 THEN f.properties ELSE NULL END AS properties FROM spatial s JOIN features f ON f.id=s.id WHERE s.minx<=? AND s.maxx>=? AND s.miny<=? AND s.maxy>=? ORDER BY f.id LIMIT ?');
        foreach ([$bbox[2],$bbox[0],$bbox[3],$bbox[1]] as $i => $value) {
            $query->bindValue($i + 1, (string)$value);
        }
        $query->bindValue(5, $limit + 1, \PDO::PARAM_INT);
        $query->execute();
        $count = 0;
        while ($row = $query->fetch(\PDO::FETCH_ASSOC)) {
            if (++$count > $limit) {
                throw new WmsException('Too many visible features. Zoom in or simplify the dataset.', 'NoApplicableCode', 503);
            }
            if ($row['geometry'] === null || $row['properties'] === null) {
                throw new WmsException('Stored feature exceeds the safe decoding limit. Reimport or simplify the dataset.', 'NoApplicableCode', 503);
            }
            yield ['type' => 'Feature','id' => (int)$row['id'],'geometry' => json_decode($row['geometry'], true, 32, JSON_THROW_ON_ERROR),'properties' => json_decode($row['properties'], true, 32, JSON_THROW_ON_ERROR)];
        }
    }

    public static function import(Config $config, string $id): array
    {
        Config::id($id);
        $source = $config->sources[$id] ?? throw new \RuntimeException('Unknown dataset.');
        $path = $config->sourcePath($source['file']);
        if (!in_array($source['crs'], Projection::OUTPUT_CRS, true)) {
            throw new \RuntimeException('Unsupported source CRS.');
        }
        if (filesize($path) > 268435456) {
            throw new \RuntimeException('Source exceeds 256 MiB import limit.');
        }
        $destination = $config->root . '/data/' . $id . '.sqlite';
        $temporary = $destination . '.tmp-' . bin2hex(random_bytes(8));
        $lock = fopen($config->root . '/data/' . $id . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Dataset import already running.');
        }
        try {
            $pdo = new \PDO('sqlite:' . $temporary);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode=DELETE; CREATE TABLE features(id INTEGER PRIMARY KEY,geometry TEXT NOT NULL,properties TEXT NOT NULL); CREATE VIRTUAL TABLE spatial USING rtree(id,minx,maxx,miny,maxy); CREATE TABLE metadata(value TEXT NOT NULL)');
            $pdo->beginTransaction();
            $insert = $pdo->prepare('INSERT INTO features VALUES(?,?,?)');
            $spatial = $pdo->prepare('INSERT INTO spatial VALUES(?,?,?,?,?)');
            $projection = new Projection();
            $count = 0;
            $total = 0;
            $extent = null;
            foreach (Sources::features($path, $source) as $feature) {
                if (($feature['geometry'] ?? null) === null) {
                    continue;
                }
                $vertices = 0;
                $geometry = Geometry::map($feature['geometry'], static fn ($point) => $projection->point($point, $source['crs'], 'EPSG:4326'), $vertices);
                if ($vertices > self::MAX_FEATURE_VERTICES) {
                    throw new \RuntimeException('Feature exceeds 20000 vertices. Simplify or split it in GIS before publishing.');
                }
                $geometryJson = json_encode($geometry, JSON_THROW_ON_ERROR);
                if (strlen($geometryJson) > self::MAX_GEOMETRY_BYTES) {
                    throw new \RuntimeException('Feature geometry exceeds 1 MiB. Split it before publishing.');
                }
                $bbox = Geometry::bbox($geometry);
                if ($bbox === null) {
                    continue;
                }
                if ($bbox[0] < -180 || $bbox[2] > 180 || $bbox[1] < -85.05112878 || $bbox[3] > 85.05112878 || $bbox[2] - $bbox[0] > 180) {
                    throw new \RuntimeException('Dataset is outside the supported geographic domain or crosses the antimeridian. Reproject/split in GIS.');
                }
                $extent = $extent === null ? $bbox : [min($extent[0], $bbox[0]),min($extent[1], $bbox[1]),max($extent[2], $bbox[2]),max($extent[3], $bbox[3])];
                $total += $vertices;
                if (++$count > 1000000 || $total > 5000000) {
                    throw new \RuntimeException('Import limit exceeded. Split the dataset.');
                }
                $properties = $feature['properties'] ?? [];
                if (!is_array($properties)) {
                    throw new \RuntimeException('Properties must be an object.');
                }
                $json = json_encode($properties, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (strlen($json) > 65536) {
                    throw new \RuntimeException('Feature attributes exceed 64 KiB.');
                }
                $insert->execute([$count,$geometryJson,$json]);
                $spatial->execute([$count,$bbox[0],$bbox[2],$bbox[1],$bbox[3]]);
            }
            if (!$count) {
                throw new \RuntimeException('Dataset contains no non-empty features.');
            }
            $meta = ['format' => 1,'id' => $id,'count' => $count,'vertices' => $total,'bbox' => $extent,'revision' => bin2hex(random_bytes(16)),'imported' => gmdate('c'),'source_crs' => $source['crs']];
            $pdo->prepare('INSERT INTO metadata VALUES(?)')->execute([json_encode($meta, JSON_THROW_ON_ERROR)]);
            $pdo->commit();
            $pdo->exec('PRAGMA optimize');
            $insert = null;
            $spatial = null;
            $pdo = null;
            if (!rename($temporary, $destination)) {
                throw new \RuntimeException('Cannot atomically publish dataset.');
            }

            return $meta;
        } finally {
            if (isset($pdo) && $pdo instanceof \PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo = null;
            foreach ([$temporary,$temporary . '-journal'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
