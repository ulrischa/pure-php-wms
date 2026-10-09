<?php

declare(strict_types=1);

namespace PureWms;

final class Canvas
{
    public readonly \GdImage $image;
    private array $colors = [];

    public function __construct(public readonly int $width, public readonly int $height, string $background)
    {
        $this->image = imagecreatetruecolor($width, $height);
        imagealphablending($this->image, false);
        imagesavealpha($this->image, true);
        imagefill($this->image, 0, 0, $this->color($background));
        imagealphablending($this->image, true);
    }

    public function color(string $hex): int
    {
        if (!preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/iD', $hex)) {
            throw new \RuntimeException('Invalid style color: ' . $hex);
        }
        if (!isset($this->colors[$hex])) {
            $this->colors[$hex] = imagecolorallocatealpha($this->image, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)), 127 - (int)round(hexdec(substr($hex, 7, 2) ?: 'ff') * 127 / 255));
        }

        return $this->colors[$hex];
    }

    public function pixel(int $x, int $y): array
    {
        $p = imagecolorsforindex($this->image, imagecolorat($this->image, $x, $y));

        return [$p['red'],$p['green'],$p['blue'],(int)round((127 - $p['alpha']) * 255 / 127)];
    }

    public function polygon(array $rings, string $fill): void
    {
        // Even-odd scan conversion fills holes without erasing underlying layers.
        $edges = [];
        $min = $this->height;
        $max = -1;
        foreach ($rings as $ring) {
            $count = count($ring);
            for ($i = 0;$i < $count;++$i) {
                $a = $ring[$i];
                $b = $ring[($i + 1) % $count];
                if ($a[1] === $b[1]) {
                    continue;
                }
                $edges[] = [$a,$b];
                $min = min($min, $a[1], $b[1]);
                $max = max($max, $a[1], $b[1]);
            }
        }
        $color = $this->color($fill);
        for ($y = max(0, (int)floor($min));$y <= min($this->height - 1, (int)ceil($max));++$y) {
            $scan = $y + 0.5;
            $crossings = [];
            foreach ($edges as [$a,$b]) {
                if (($a[1] <= $scan && $b[1] > $scan) || ($b[1] <= $scan && $a[1] > $scan)) {
                    $crossings[] = $a[0] + ($scan - $a[1]) * ($b[0] - $a[0]) / ($b[1] - $a[1]);
                }
            }
            sort($crossings, SORT_NUMERIC);
            for ($i = 0;$i + 1 < count($crossings);$i += 2) {
                $left = max(0, (int)ceil($crossings[$i] - 0.5));
                $right = min($this->width - 1, (int)floor($crossings[$i + 1] - 0.5));
                if ($left <= $right) {
                    imageline($this->image, $left, $y, $right, $y, $color);
                }
            }
        }
    }

    public function line(array $points, string $color, int $width = 1, bool $closed = false): void
    {
        if ($closed && $points) {
            $points[] = $points[0];
        }
        imagesetthickness($this->image, $width);
        for ($i = 1;$i < count($points);++$i) {
            $segment = $this->clip($points[$i - 1], $points[$i], $width);
            if ($segment !== null) {
                imageline($this->image, (int)round($segment[0][0]), (int)round($segment[0][1]), (int)round($segment[1][0]), (int)round($segment[1][1]), $this->color($color));
            }
        }
        imagesetthickness($this->image, 1);
    }

    private function clip(array $a, array $b, int $padding): ?array
    {
        // Liang-Barsky clipping also avoids overflowing GD's integer coordinates.
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        $lo = 0.0;
        $hi = 1.0;
        $p = [-$dx,$dx,-$dy,$dy];
        $q = [$a[0] + $padding,$this->width + $padding - $a[0],$a[1] + $padding,$this->height + $padding - $a[1]];
        for ($i = 0;$i < 4;++$i) {
            if (abs($p[$i]) < 1e-20) {
                if ($q[$i] < 0) {
                    return null;
                } continue;
            }
            $r = $q[$i] / $p[$i];
            if ($p[$i] < 0) {
                $lo = max($lo, $r);
            } else {
                $hi = min($hi, $r);
            }
            if ($lo > $hi) {
                return null;
            }
        }

        return [[$a[0] + $lo * $dx,$a[1] + $lo * $dy],[$a[0] + $hi * $dx,$a[1] + $hi * $dy]];
    }

    public function png(): string
    {
        ob_start();
        imagepng($this->image, null, 6);

        return (string)ob_get_clean();
    }
}
