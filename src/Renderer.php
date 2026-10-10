<?php

declare(strict_types=1);

namespace PureWms;

final class Renderer
{
    public readonly Canvas $canvas;
    private Projection $projection;
    private array $labels = [];
    private array $occupied = [];
    private array $icons = [];
    public readonly float $scale;

    public function __construct(private readonly Config $config, public readonly array $map, private readonly Budget $budget)
    {
        $this->canvas = new Canvas($map['width'], $map['height'], $map['background']);
        $this->projection = new Projection($config->crs);
        $this->scale = LayerPlan::scale($map, $config->crs);
    }

    public function geographicBounds(int $padding = 192): array
    {
        $b = $this->map['bbox'];
        $dx = ($b[2] - $b[0]) / $this->map['width'] * $padding;
        $dy = ($b[3] - $b[1]) / $this->map['height'] * $padding;
        $bbox = [$b[0] - $dx,$b[1] - $dy,$b[2] + $dx,$b[3] + $dy];
        return $this->toGeographicBounds($bbox);
    }

    public function queryBounds(float $x, float $y, int $radius): array
    {
        $b = $this->map['bbox'];
        $dx = ($b[2] - $b[0]) / $this->map['width'];
        $dy = ($b[3] - $b[1]) / $this->map['height'];
        return $this->toGeographicBounds([
            $b[0] + ($x - $radius) * $dx, $b[3] - ($y + $radius) * $dy,
            $b[0] + ($x + $radius) * $dx, $b[3] - ($y - $radius) * $dy,
        ]);
    }

    private function toGeographicBounds(array $bbox): array
    {
        if (in_array($this->map['crs'], ['EPSG:4326','CRS:84'], true)) {
            return [max(-180, $bbox[0]),max(-85.05112878, $bbox[1]),min(180, $bbox[2]),min(85.05112878, $bbox[3])];
        }
        $domain = $this->config->crs->get($this->map['crs'])['bounds'];
        $bbox = [max($domain[0], $bbox[0]),max($domain[1], $bbox[1]),min($domain[2], $bbox[2]),min($domain[3], $bbox[3])];
        if ($this->map['crs'] === 'EPSG:3857') {
            // Avoid longitude normalization at the world edge wrapping an envelope.
            $limit = 20037508.342789244 - 0.001;
            $bbox = [max(-$limit, $bbox[0]),max(-$limit, $bbox[1]),min($limit, $bbox[2]),min($limit, $bbox[3])];
        }
        $result = $this->projection->envelope($bbox, $this->map['crs'], 'EPSG:4326');

        // Conservative margin for finite edge sampling in regional projections.
        return [$result[0] - 0.01,$result[1] - 0.01,$result[2] + 0.01,$result[3] + 0.01];
    }

    public function screenGeometry(array $geometry): array
    {
        $vertices = 0;
        $b = $this->map['bbox'];
        $result = Geometry::map($geometry, function (array $point) use ($b): array {
            [$x,$y] = $this->projection->point($point, 'EPSG:4326', $this->map['crs']);

            return [($x - $b[0]) / ($b[2] - $b[0]) * $this->map['width'],($b[3] - $y) / ($b[3] - $b[1]) * $this->map['height']];
        }, $vertices);
        $this->budget->check(1, $vertices);

        return $result;
    }

    public function symbolizer(array $style, array $properties): array
    {
        $this->budget->check();
        foreach ($style['rules'] ?? [] as $rule) {
            if (array_key_exists($rule['field'], $properties) && is_scalar($properties[$rule['field']]) && (string)$properties[$rule['field']] === (string)$rule['equals']) {
                $style = array_replace($style, $rule['symbolizer']);
                break;
            }
        }

        return $style;
    }

    /** JSON styles use first-match overrides; SLD applies all matching rules. */
    public function symbolizers(array $style, array $properties): array
    {
        $result = [];
        foreach (StyleRules::matching($style, $properties, $this->scale) as $rule) {
            array_push($result, ...$rule['symbols']);
        }
        return $result;
    }

    public function applies(array $geometry, array $style): bool
    {
        $kind = $style['_geometry'] ?? 'any';
        if ($kind === 'any') {
            return true;
        }
        if ($geometry['type'] === 'GeometryCollection') {
            foreach ($geometry['geometries'] as $child) {
                if ($this->applies($child, $style)) {
                    return true;
                }
            }
            return false;
        }
        return match ($kind) {
            'point' => in_array($geometry['type'], ['Point','MultiPoint'], true),
            'line' => in_array($geometry['type'], ['LineString','MultiLineString','Polygon','MultiPolygon'], true),
            'polygon' => in_array($geometry['type'], ['Polygon','MultiPolygon'], true),
            default => false,
        };
    }

    /** Geometry hit test that respects outline-only and transparent symbolizers. */
    public function hitDistance(array $geometry, array $style, array $point): float
    {
        if (!$this->applies($geometry, $style)) {
            return INF;
        }
        if ($geometry['type'] === 'GeometryCollection') {
            $distance = INF;
            foreach ($geometry['geometries'] as $child) {
                $distance = min($distance, $this->hitDistance($child, $style, $point));
            }
            return $distance;
        }
        $opaque = static fn (string $color): bool => strlen($color) !== 9 || substr($color, 7) !== '00';
        if ($style['_text_only'] ?? false) {
            return $opaque($style['label_color'] ?? '#000000') ? Geometry::distanceTo($geometry, $point) : INF;
        }
        $stroke = ($style['stroke_width'] ?? 1) > 0 && $opaque($style['stroke'] ?? '#000000');
        $fill = $opaque($style['fill'] ?? '#808080');
        if (in_array($geometry['type'], ['Polygon','MultiPolygon'], true) && !$fill) {
            if (!$stroke) {
                return INF;
            }
            $rings = $geometry['type'] === 'Polygon' ? $geometry['coordinates'] : array_merge(...$geometry['coordinates']);
            return Geometry::distanceTo(['type' => 'MultiLineString', 'coordinates' => $rings], $point);
        }
        if (in_array($geometry['type'], ['LineString','MultiLineString'], true) && !$stroke) {
            return INF;
        }
        if (in_array($geometry['type'], ['Point','MultiPoint'], true) && !isset($style['icon']) && !$fill && !$stroke) {
            return INF;
        }
        return Geometry::distanceTo($geometry, $point);
    }

    public function visible(array $style): bool
    {
        return $this->scale >= ($style['min_scale'] ?? 0) && $this->scale < ($style['max_scale'] ?? INF);
    }

    public function feature(array $feature, array $base): void
    {
        $styles = $this->symbolizers($base, $feature['properties']);
        if (!$styles) {
            return;
        }
        $geometry = $this->screenGeometry($feature['geometry']);
        foreach ($styles as $style) {
            $this->draw($geometry, $style);
            $field = $style['label_field'] ?? '';
            if ($field !== '' && isset($feature['properties'][$field]) && is_scalar($feature['properties'][$field]) && count($this->labels) < 1500) {
                $text = preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string)$feature['properties'][$field]);
                $text = mb_substr($text, 0, 80, 'UTF-8');
                $anchor = $this->anchor($geometry);
                if ($text !== '' && $anchor !== null) {
                    if ($style['label_follow_line'] ?? false) {
                        $style['label_angle'] = $this->lineAngle($geometry, $anchor);
                    }
                    $priority = $feature['properties'][$style['label_priority_field'] ?? ''] ?? 0;
                    $style['_priority'] = is_numeric($priority) && is_finite((float)$priority) ? (float)$priority : 0;
                    $this->labels[] = [$anchor,$text,$style];
                }
            }
        }
    }

    public function draw(array $geometry, array $style): void
    {
        if (($style['_text_only'] ?? false) || !$this->applies($geometry, $style)) {
            return;
        }
        $this->budget->check();
        $type = $geometry['type'];
        $c = $geometry['coordinates'] ?? [];
        switch ($type) {
            case 'GeometryCollection': foreach ($geometry['geometries'] as $child) {
                $this->draw($child, $style);
            } break;
            case 'Point': $this->symbol($c, $style);
                break;
            case 'MultiPoint': foreach ($c as $p) {
                $this->symbol($p, $style);
            } break;
            case 'LineString': if (($style['stroke_width'] ?? 1) > 0) {
                $this->canvas->line($c, $style['stroke'] ?? '#253c4a', $style['stroke_width'] ?? 1, false, $style['stroke_dasharray'] ?? []);
            } break;
            case 'MultiLineString': foreach ($c as $line) {
                $this->draw(['type' => 'LineString','coordinates' => $line], $style);
            } break;
            case 'Polygon':
                $edges = array_sum(array_map('count', $c));
                $this->budget->check(0, 0, $edges * $this->map['height']);
                $this->canvas->polygon($c, $style['fill'] ?? '#4ea88980');
                if (($style['stroke_width'] ?? 1) > 0) {
                    foreach ($c as $ring) {
                        $this->canvas->line($ring, $style['stroke'] ?? '#285b4d', $style['stroke_width'] ?? 1, true, $style['stroke_dasharray'] ?? []);
                    }
                }
                break;
            case 'MultiPolygon': foreach ($c as $polygon) {
                $this->draw(['type' => 'Polygon','coordinates' => $polygon], $style);
            } break;
        }
    }

    private function symbol(array $point, array $style): void
    {
        $size = $style['size'] ?? 12;
        $radius = $size / 2;
        if ($point[0] < -$size || $point[1] < -$size || $point[0] > $this->map['width'] + $size || $point[1] > $this->map['height'] + $size) {
            return;
        }
        $x = (int)round($point[0]);
        $y = (int)round($point[1]);
        if (isset($style['icon'])) {
            $name = $style['icon'];
            if (!isset($this->icons[$name])) {
                $path = $this->config->resource($name, 'png');
                $info = getimagesize($path);
                if (!$info || $info[0] > 512 || $info[1] > 512 || $info[2] !== IMAGETYPE_PNG || filesize($path) > 1048576) {
                    throw new \RuntimeException('PNG icons must be <=512×512 and <=1 MiB.');
                }
                $this->icons[$name] = imagecreatefrompng($path);
            }
            $icon = $this->icons[$name];
            imagecopyresampled($this->canvas->image, $icon, (int)round($x - $radius), (int)round($y - $radius), 0, 0, $size, $size, imagesx($icon), imagesy($icon));

            return;
        }
        $fill = $style['fill'] ?? '#e7893cff';
        $stroke = $style['stroke'] ?? '#533516ff';
        $width = $style['stroke_width'] ?? 1;
        $symbol = $style['symbol'] ?? 'circle';
        if ($symbol === 'circle') {
            imagefilledellipse($this->canvas->image, $x, $y, $size, $size, $this->canvas->color($fill));
            if ($width > 0) {
                imagesetthickness($this->canvas->image, $width);
                imageellipse($this->canvas->image, $x, $y, $size, $size, $this->canvas->color($stroke));
                imagesetthickness($this->canvas->image, 1);
            }

            return;
        }
        if ($symbol === 'cross') {
            $this->canvas->line([[$x - $radius,$y],[$x + $radius,$y]], $stroke, max(1, $width));
            $this->canvas->line([[$x,$y - $radius],[$x,$y + $radius]], $stroke, max(1, $width));

            return;
        }
        $ring = match ($symbol) {
            'triangle' => [[$x,$y - $radius],[$x + $radius,$y + $radius],[$x - $radius,$y + $radius]],
            'diamond' => [[$x,$y - $radius],[$x + $radius,$y],[$x,$y + $radius],[$x - $radius,$y]],
            default => [[$x - $radius,$y - $radius],[$x + $radius,$y - $radius],[$x + $radius,$y + $radius],[$x - $radius,$y + $radius]],
        };
        $this->canvas->polygon([$ring], $fill);
        if ($width > 0) {
            $this->canvas->line($ring, $stroke, $width, true);
        }
    }

    private function anchor(array $geometry): ?array
    {
        $type = $geometry['type'];
        $c = $geometry['coordinates'] ?? [];
        if ($type === 'GeometryCollection') {
            foreach ($geometry['geometries'] as $child) {
                $anchor = $this->anchor($child);
                if ($anchor !== null) {
                    return $anchor;
                }
            }

            return null;
        }
        if ($type === 'Point') {
            return $c;
        }
        if ($type === 'MultiPoint') {
            return $c[0] ?? null;
        }
        if ($type === 'MultiLineString' || $type === 'MultiPolygon') {
            $best = null;
            $largest = -1;
            foreach ($c as $part) {
                $child = ['type' => $type === 'MultiPolygon' ? 'Polygon' : 'LineString','coordinates' => $part];
                $bbox = Geometry::bbox($child);
                if ($bbox === null) {
                    continue;
                }
                $area = ($bbox[2] - $bbox[0]) * ($bbox[3] - $bbox[1]);
                if ($area > $largest) {
                    $candidate = $this->anchor($child);
                    if ($candidate !== null) {
                        $best = $candidate;
                        $largest = $area;
                    }
                }
            }

            return $best;
        }
        if ($type === 'LineString') {
            $total = 0;
            for ($i = 1;$i < count($c);++$i) {
                $total += hypot($c[$i][0] - $c[$i - 1][0], $c[$i][1] - $c[$i - 1][1]);
            }
            $remaining = $total / 2;
            for ($i = 1;$i < count($c);++$i) {
                $length = hypot($c[$i][0] - $c[$i - 1][0], $c[$i][1] - $c[$i - 1][1]);
                if ($length > 0 && $remaining <= $length) {
                    $t = $remaining / $length;

                    return [$c[$i - 1][0] + $t * ($c[$i][0] - $c[$i - 1][0]),$c[$i - 1][1] + $t * ($c[$i][1] - $c[$i - 1][1])];
                } $remaining -= $length;
            }

            return $c[0] ?? null;
        }
        if ($type === 'Polygon') {
            $bbox = Geometry::bbox($geometry);
            if ($bbox === null) {
                return null;
            }
            $best = null;
            $length = 0;
            // Search interior spans to avoid putting labels in holes or outside concave polygons.
            for ($step = 1;$step < 10;++$step) {
                $y = max(0, $bbox[1]) + (min($this->map['height'], $bbox[3]) - max(0, $bbox[1])) * $step / 10;
                $crossings = [];
                foreach ($c as $ring) {
                    for ($i = 0,$j = count($ring) - 1;$i < count($ring);$j = $i++) {
                        $a = $ring[$i];
                        $b = $ring[$j];
                        if (($a[1] > $y) !== ($b[1] > $y)) {
                            $crossings[] = $a[0] + ($y - $a[1]) * ($b[0] - $a[0]) / ($b[1] - $a[1]);
                        }
                    }
                }
                sort($crossings, SORT_NUMERIC);
                for ($i = 0;$i + 1 < count($crossings);$i += 2) {
                    $left = max(0, $crossings[$i]);
                    $right = min($this->map['width'], $crossings[$i + 1]);
                    if ($right - $left > $length) {
                        $length = $right - $left;
                        $best = [($left + $right) / 2,$y];
                    }
                }
            }

            return $best;
        }

        return null;
    }

    private function lineAngle(array $geometry, array $anchor): float
    {
        $lines = match ($geometry['type']) {
            'LineString' => [$geometry['coordinates']],
            'MultiLineString' => $geometry['coordinates'],
            default => [],
        };
        $best = INF; $angle = 0;
        foreach ($lines as $line) {
            for ($i = 1; $i < count($line); ++$i) {
                $distance = Geometry::distanceTo(['type' => 'LineString', 'coordinates' => [$line[$i-1],$line[$i]]], $anchor);
                if ($distance < $best) {
                    $best = $distance;
                    $angle = rad2deg(atan2($line[$i-1][1] - $line[$i][1], $line[$i][0] - $line[$i-1][0]));
                    if ($angle > 90) { $angle -= 180; }
                    if ($angle < -90) { $angle += 180; }
                }
            }
        }
        return $angle;
    }

    public function labels(): void
    {
        usort($this->labels, static fn ($a, $b) => ($b[2]['_priority'] ?? 0) <=> ($a[2]['_priority'] ?? 0));
        $placed = [];
        foreach ($this->labels as [$anchor,$text,$style]) {
            foreach ($placed[$text] ?? [] as $previous) {
                if (hypot($anchor[0] - $previous[0], $anchor[1] - $previous[1]) < ($style['label_min_distance'] ?? 0)) { continue 2; }
            }
            $this->budget->check();
            $font = $this->config->resource($style['font'] ?? 'DejaVuSans.ttf', 'ttf');
            $size = $style['font_size'] ?? 11;
            $angle = $style['label_angle'] ?? 0;
            $bbox = imagettfbbox($size, $angle, $font, $text);
            if ($bbox === false) {
                throw new \RuntimeException('FreeType font measurement failed.');
            }
            $minx = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $maxx = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $miny = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
            $maxy = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
            $w = $maxx - $minx;
            $h = $maxy - $miny;
            $halo = $style['halo'] ?? 1;
            $offsets = [[8,-$h / 2],[-$w - 8,-$h / 2],[-$w / 2,-$h - 8]];
            if (isset($style['label_anchor'])) {
                $offset = $style['label_offset'] ?? [0,0];
                $offsets = [[-$w * $style['label_anchor'][0] + $offset[0], -$h * (1 - $style['label_anchor'][1]) - $offset[1]]];
            }
            foreach ($offsets as [$dx,$dy]) {
                $left = (int)round($anchor[0] + $dx);
                $top = (int)round($anchor[1] + $dy);
                $rect = [$left - $halo - 2,$top - $halo - 2,$left + $w + $halo + 2,$top + $h + $halo + 2];
                if ($rect[0] < 0 || $rect[1] < 0 || $rect[2] > $this->map['width'] || $rect[3] > $this->map['height'] || !$this->reserve($rect)) {
                    continue;
                }
                $x = $left - $minx;
                $y = $top - $miny;
                for ($ox = -$halo;$ox <= $halo;++$ox) {
                    for ($oy = -$halo;$oy <= $halo;++$oy) {
                        if ($ox || $oy) {
                            imagettftext($this->canvas->image, $size, $angle, $x + $ox, $y + $oy, $this->canvas->color($style['halo_color'] ?? '#ffffffff'), $font, $text);
                        }
                    }
                }
                imagettftext($this->canvas->image, $size, $angle, $x, $y, $this->canvas->color($style['label_color'] ?? '#19352fff'), $font, $text);
                $placed[$text][] = $anchor;
                break;
            }
        }
    }

    private function reserve(array $rectangle): bool
    {
        $cells = [];
        for ($x = (int)floor($rectangle[0] / 64);$x <= (int)floor($rectangle[2] / 64);++$x) {
            for ($y = (int)floor($rectangle[1] / 64);$y <= (int)floor($rectangle[3] / 64);++$y) {
                $key = "$x:$y";
                $cells[] = $key;
                foreach ($this->occupied[$key] ?? [] as $other) {
                    if ($rectangle[0] < $other[2] && $rectangle[2] > $other[0] && $rectangle[1] < $other[3] && $rectangle[3] > $other[1]) {
                        return false;
                    }
                }
            }
        }
        foreach ($cells as $key) {
            $this->occupied[$key][] = $rectangle;
        }

        return true;
    }
}
