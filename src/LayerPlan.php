<?php

declare(strict_types=1);

namespace PureWms;

/** Resolve visibility and prepared overview sources before opening databases. */
final class LayerPlan
{
    public static function scale(array $map, CrsRegistry $registry): float
    {
        return ($map['bbox'][2] - $map['bbox'][0]) / $map['width'] * $registry->get($map['crs'])['meters_per_unit'] / 0.00028;
    }

    public static function visible(array $style, float $scale): bool
    {
        if (isset($style['_sld_groups'])) {
            foreach ($style['_sld_groups'] as $group) {
                foreach ($group as $rule) {
                    if ($scale >= $rule['min'] && $scale < $rule['max']) {
                        return true;
                    }
                }
            }
            return false;
        }
        foreach (array_merge([$style], array_map(static fn ($rule) => array_replace($style, $rule['symbolizer']), $style['rules'] ?? [])) as $part) {
            if ($scale >= ($part['min_scale'] ?? 0) && $scale < ($part['max_scale'] ?? INF)) {
                return true;
            }
        }
        return false;
    }

    public static function dataset(array $layer, float $scale): string
    {
        $source = $layer['dataset'];
        $threshold = -1;
        foreach ($layer['overviews'] ?? [] as $overview) {
            if ($scale >= $overview['min_scale'] && $overview['min_scale'] > $threshold) {
                $source = $overview['dataset'];
                $threshold = $overview['min_scale'];
            }
        }
        return $source;
    }
}
