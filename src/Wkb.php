<?php

declare(strict_types=1);

namespace PureWms;

/** Strict decoder for the ordinary OGC simple-feature subset of GeoPackage. */
final class Wkb
{
    private int $offset = 0;
    private int $vertices = 0;

    private function __construct(private readonly string $bytes)
    {
    }

    public static function geopackage(string $blob, int $expectedSrs): ?array
    {
        if (strlen($blob) < 8 || substr($blob, 0, 2) !== 'GP' || ord($blob[2]) !== 0) {
            throw new \RuntimeException('Invalid GeoPackage geometry header.');
        }
        $flags = ord($blob[3]);
        $envelope = ($flags >> 1) & 7;
        if (($flags & 0xe0) !== 0 || $envelope > 4) {
            throw new \RuntimeException('Extended GeoPackage geometry is unsupported.');
        }
        $srs = unpack(($flags & 1) ? 'V' : 'N', substr($blob, 4, 4))[1];
        if ($srs !== $expectedSrs) {
            throw new \RuntimeException('GeoPackage geometry SRS does not match its table.');
        }
        if ($flags & 16) {
            return null;
        }
        $reader = new self($blob);
        $reader->offset = 8 + ([0,32,48,48,64][$envelope]);
        $geometry = $reader->geometry(0);
        if ($reader->offset !== strlen($blob)) {
            throw new \RuntimeException('Trailing GeoPackage geometry data.');
        }

        return $geometry;
    }

    private function take(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->bytes)) {
            throw new \RuntimeException('Truncated WKB.');
        }
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;

        return $value;
    }

    private function count(bool $little): int
    {
        $value = unpack($little ? 'V' : 'N', $this->take(4))[1];
        if ($value > 1000000) {
            throw new \RuntimeException('WKB count limit exceeded.');
        }

        return $value;
    }

    private function geometry(int $depth): array
    {
        if ($depth > 16) {
            throw new \RuntimeException('WKB nesting limit exceeded.');
        }
        $order = ord($this->take(1));
        if ($order > 1) {
            throw new \RuntimeException('Invalid WKB byte order.');
        }
        $little = $order === 1;
        $type = $this->count($little);
        $dimensions = [2,3,3,4][intdiv($type, 1000)] ?? 0;
        if ($type >= 4000) {
            throw new \RuntimeException('Extended WKB is unsupported.');
        }
        $type %= 1000;
        $point = function () use ($little, $dimensions): array {
            if (++$this->vertices > 1000000) {
                throw new \RuntimeException('WKB vertex limit exceeded.');
            }
            $p = array_values(unpack(($little ? 'e' : 'E') . '*', $this->take(8 * $dimensions)));

            return [$p[0],$p[1]];
        };
        $line = function () use ($little, $point): array {
            $count = $this->count($little);
            $points = [];
            for ($i = 0;$i < $count;++$i) {
                $points[] = $point();
            }

            return $points;
        };
        if ($type === 1) {
            return ['type' => 'Point','coordinates' => $point()];
        }
        if ($type === 2) {
            return ['type' => 'LineString','coordinates' => $line()];
        }
        if ($type === 3) {
            $count = $this->count($little);
            $rings = [];
            for ($i = 0;$i < $count;++$i) {
                $rings[] = $line();
            }

            return ['type' => 'Polygon','coordinates' => $rings];
        }
        $names = [4 => 'MultiPoint',5 => 'MultiLineString',6 => 'MultiPolygon',7 => 'GeometryCollection'];
        if (!isset($names[$type])) {
            throw new \RuntimeException('Curves and non-simple-feature geometry are unsupported.');
        }
        $count = $this->count($little);
        $children = [];
        for ($i = 0;$i < $count;++$i) {
            $child = $this->geometry($depth + 1);
            if ($type !== 7 && $child['type'] !== [4 => 'Point',5 => 'LineString',6 => 'Polygon'][$type]) {
                throw new \RuntimeException('Invalid WKB collection member.');
            }
            $children[] = $type === 7 ? $child : $child['coordinates'];
        }

        return ['type' => $names[$type], $type === 7 ? 'geometries' : 'coordinates' => $children];
    }
}
