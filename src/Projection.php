<?php

declare(strict_types=1);

namespace PureWms;

use proj4php\{Proj4php, Proj, Point};

final class Projection
{
    public const OUTPUT_CRS = ['EPSG:4326', 'CRS:84', 'EPSG:3857', 'EPSG:25832', 'EPSG:25833'];
    private Proj4php $engine;
    private array $projections = [];

    public function __construct()
    {
        $this->engine = new Proj4php();
        foreach ([32,33] as $zone) {
            $this->engine->addDef('EPSG:258' . $zone, "+proj=utm +zone=$zone +ellps=GRS80 +towgs84=0,0,0 +units=m +no_defs");
        }
    }

    public function point(array $point, string $source, string $target): array
    {
        $source = $source === 'CRS:84' ? 'EPSG:4326' : $source;
        $target = $target === 'CRS:84' ? 'EPSG:4326' : $target;
        foreach ([$source,$target] as $crs) {
            if (!in_array($crs, self::OUTPUT_CRS, true)) {
                throw new \RuntimeException('Unsupported coordinate system: ' . $crs);
            }
        }
        $x = (float)$point[0];
        $y = (float)$point[1];
        if ($source !== $target) {
            $this->projections[$source] ??= new Proj($source, $this->engine);
            $this->projections[$target] ??= new Proj($target, $this->engine);
            if ($source === 'EPSG:4326' && $target === 'EPSG:3857') {
                $y = max(-85.0511287798, min(85.0511287798, $y));
            }
            $result = $this->engine->transform($this->projections[$target], new Point($x, $y, $this->projections[$source]));
            $x = $result->x;
            $y = $result->y;
        }
        if (!is_finite($x) || !is_finite($y)) {
            throw new \RuntimeException('Projection produced a non-finite coordinate.');
        }

        return [$x,$y];
    }

    public function envelope(array $bbox, string $source, string $target): array
    {
        $points = [];
        // Sample all edges: transformed rectangle edges need not stay straight.
        for ($i = 0; $i <= 32; ++$i) {
            $x = $bbox[0] + ($bbox[2] - $bbox[0]) * $i / 32;
            $y = $bbox[1] + ($bbox[3] - $bbox[1]) * $i / 32;
            foreach ([[$x,$bbox[1]],[$x,$bbox[3]],[$bbox[0],$y],[$bbox[2],$y]] as $point) {
                $points[] = $this->point($point, $source, $target);
            }
        }

        return [min(array_column($points, 0)),min(array_column($points, 1)),max(array_column($points, 0)),max(array_column($points, 1))];
    }
}
