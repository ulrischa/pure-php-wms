<?php

declare(strict_types=1);

namespace PureWms;

final class Geometry
{
    public static function map(array $geometry, callable $transform, int &$vertices, int $depth = 0): array
    {
        if ($depth > 16) {
            throw new \RuntimeException('Geometry nesting limit exceeded.');
        }
        $type = $geometry['type'] ?? '';
        if ($type === 'GeometryCollection') {
            $children = [];
            foreach ($geometry['geometries'] ?? [] as $child) {
                $children[] = self::map($child, $transform, $vertices, $depth + 1);
            }

            return ['type' => $type,'geometries' => $children];
        }
        $levels = ['Point' => 0,'MultiPoint' => 1,'LineString' => 1,'MultiLineString' => 2,'Polygon' => 2,'MultiPolygon' => 3];
        if (!isset($levels[$type]) || !is_array($geometry['coordinates'] ?? null)) {
            throw new \RuntimeException('Unsupported or invalid geometry: ' . $type);
        }
        $walk = function (array $coordinates, int $level) use (&$walk, $transform, &$vertices): array {
            if ($level > 0) {
                return array_map(fn ($part) => $walk($part, $level - 1), $coordinates);
            }
            if (count($coordinates) < 2 || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1]) || !is_finite((float)$coordinates[0]) || !is_finite((float)$coordinates[1])) {
                throw new \RuntimeException('Invalid coordinate.');
            }
            if (++$vertices > 1000000) {
                throw new \RuntimeException('Geometry vertex budget exceeded.');
            }

            return $transform($coordinates);
        };

        return ['type' => $type,'coordinates' => $walk($geometry['coordinates'], $levels[$type])];
    }

    public static function bbox(array $geometry): ?array
    {
        $bbox = null;
        $vertices = 0;
        self::map($geometry, static function (array $point) use (&$bbox): array {
            if ($bbox === null) {
                $bbox = [$point[0],$point[1],$point[0],$point[1]];
            } else {
                $bbox = [min($bbox[0], $point[0]),min($bbox[1], $point[1]),max($bbox[2], $point[0]),max($bbox[3], $point[1])];
            }

            return $point;
        }, $vertices);

        return $bbox;
    }

    public static function inside(array $point, array $rings): bool
    {
        $inside = false;
        foreach ($rings as $ring) {
            for ($i = 0,$j = count($ring) - 1; $i < count($ring); $j = $i++) {
                [$xi,$yi] = $ring[$i];
                [$xj,$yj] = $ring[$j];
                if (($yi > $point[1]) !== ($yj > $point[1]) && $point[0] < ($xj - $xi) * ($point[1] - $yi) / ($yj - $yi) + $xi) {
                    $inside = !$inside;
                }
            }
        }

        return $inside;
    }

    public static function distance(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $length = $dx * $dx + $dy * $dy;
        $t = $length > 0 ? max(0, min(1, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / $length)) : 0;

        return hypot($p[0] - $a[0] - $t * $dx, $p[1] - $a[1] - $t * $dy);
    }

    public static function hit(array $geometry, array $point, float $tolerance): bool
    {
        return self::distanceTo($geometry, $point) <= $tolerance;
    }

    public static function distanceTo(array $geometry, array $point): float
    {
        $type = $geometry['type'];
        $c = $geometry['coordinates'] ?? [];
        if ($type === 'Point') {
            return hypot($c[0] - $point[0], $c[1] - $point[1]);
        }
        if ($type === 'Polygon' && self::inside($point, $c)) {
            return 0.0;
        }
        $minimum = INF;
        if (in_array($type, ['GeometryCollection', 'MultiPoint', 'MultiPolygon'], true)) {
            $children = $type === 'GeometryCollection' ? $geometry['geometries'] : array_map(
                static fn (array $coordinates): array => ['type' => $type === 'MultiPoint' ? 'Point' : 'Polygon', 'coordinates' => $coordinates], $c
            );
            foreach ($children as $child) {
                $minimum = min($minimum, self::distanceTo($child, $point));
            }
            return $minimum;
        }
        $lines = $type === 'LineString' ? [$c] : $c;
        foreach ($lines as $line) {
            for ($i = 1; $i < count($line); ++$i) {
                $minimum = min($minimum, self::distance($point, $line[$i - 1], $line[$i]));
            }
        }
        return $minimum;
    }
}
