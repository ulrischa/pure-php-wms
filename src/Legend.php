<?php

declare(strict_types=1);

namespace PureWms;

final class Legend
{
    public static function render(Config $config, Request $request, array $layer, array $style): array
    {
        if ($request->get('FORMAT') !== 'image/png') { throw new WmsException('Supported format: image/png.', 'InvalidFormat'); }
        $width = $request->integer('WIDTH', 64, 1024, 260);
        $rowHeight = $request->integer('HEIGHT', 16, 128, 40);
        $scale = null;
        if (isset($request->parameters['SCALE'])) {
            $value = $request->get('SCALE');
            if (!is_numeric($value) || !is_finite((float)$value) || $value <= 0 || $value > 1e12) { throw new WmsException('SCALE must be a positive denominator.'); }
            $scale = (float)$value;
        }
        $entries = StyleRules::entries($style);
        $ruleName = $request->get('RULE', '');
        if ($ruleName !== '' && !in_array($ruleName, array_column($entries, 'name'), true)) { throw new WmsException('Unknown legend RULE.', 'StyleNotDefined'); }
        $cache = new Cache($config);
        $lock = $cache->acquire();
        try {
            $budget = new Budget($config->server);
            $present = null;
            if (isset($request->parameters['BBOX'])) {
                $parameters = array_replace($request->parameters, ['REQUEST' => 'GetMap', 'LAYERS' => $request->get('LAYER'), 'STYLES' => '',
                    'WIDTH' => $request->get('SRCWIDTH', '256'), 'HEIGHT' => $request->get('SRCHEIGHT', '256')]);
                $map = Request::fromQuery(http_build_query($parameters))->map($config->server['max_side'], $config->server['max_pixels'], $config->crs);
                $context = new Renderer($config, $map, $budget);
                $scale ??= $context->scale;
                $present = [];
                if (LayerPlan::visible($style, $scale)) {
                    $dataset = new Dataset($config->root . '/data/' . LayerPlan::dataset($layer, $scale) . '.sqlite');
                    foreach ($dataset->query($context->geographicBounds(), $config->server['max_features']) as $feature) {
                        $budget->check();
                        $matching = StyleRules::matching($style, $feature['properties'], $scale);
                        if (!$matching) { continue; }
                        $geometry = $context->screenGeometry($feature['geometry']);
                        foreach ($matching as $rule) {
                            foreach ($rule['symbols'] as $symbol) {
                                $padding = in_array($geometry['type'], ['Point','MultiPoint'], true) ? ($symbol['size'] ?? 12) / 2 : ($symbol['stroke_width'] ?? 1) / 2;
                                $labelValue = $feature['properties'][$symbol['label_field'] ?? ''] ?? null;
                                $hasLabel = is_scalar($labelValue) && (string)$labelValue !== '';
                                if (($symbol['_text_only'] ?? false) && !$hasLabel) { continue; }
                                // Labels can be displaced into the viewport from the padded map query.
                                // Keep these candidates conservatively; final collision placement is not rerun.
                                if ($context->applies($geometry, $symbol) && ($hasLabel || Geometry::intersectsBox($geometry, [-$padding,-$padding,$map['width']+$padding,$map['height']+$padding]))) { $present[$rule['id']] = true; break; }
                            }
                        }
                    }
                }
                unset($context, $dataset);
            }
            $entries = array_values(array_filter($entries, static fn ($rule) => ($ruleName === '' || $rule['name'] === $ruleName)
                && ($scale === null || ($scale >= $rule['min'] && $scale < $rule['max'])) && ($present === null || isset($present[$rule['id']]))));
            $height = max(1, count($entries)) * $rowHeight;
            if ($height > $config->server['max_side'] || $height * $width > $config->server['max_pixels']) { throw new WmsException('Legend exceeds image limits. Select RULE or smaller dimensions.'); }
            $map = ['width' => $width, 'height' => $height, 'background' => '#ffffff00', 'bbox' => [0,0,$width,$height], 'crs' => 'CRS:84'];
            $renderer = new Renderer($config, $map, $budget);
            foreach ($entries as $i => $rule) {
                $y = (int)($rowHeight * ($i + 0.5));
                foreach ($rule['symbols'] as $symbol) {
                    $symbol['size'] = min($rowHeight - 6, 26, $symbol['size'] ?? 16);
                    $half = min(10, ($rowHeight - 6) / 2);
                    $geometry = match ($symbol['_geometry'] ?? $symbol['legend_geometry'] ?? 'point') {
                        'polygon' => ['type' => 'Polygon', 'coordinates' => [[[8,$y-$half],[38,$y-$half],[38,$y+$half],[8,$y+$half],[8,$y-$half]]]],
                        'line' => ['type' => 'LineString', 'coordinates' => [[8,$y+3],[20,$y-3],[38,$y+2]]],
                        default => ['type' => 'Point', 'coordinates' => [23,$y]],
                    };
                    if ($symbol['_text_only'] ?? false) {
                        imagettftext($renderer->canvas->image, min(12, $symbol['font_size'] ?? 11), 0, 8, $y + 4,
                            $renderer->canvas->color($symbol['label_color'] ?? '#000000'), $config->resource($symbol['font'] ?? 'DejaVuSans.ttf', 'ttf'), 'Ab');
                    } else { $renderer->draw($geometry, $symbol); }
                }
                imagettftext($renderer->canvas->image, 10, 0, 48, $y + 4, $renderer->canvas->color('#233c42'), $config->resource('DejaVuSans.ttf', 'ttf'), mb_substr($rule['title'], 0, max(1, (int)(($width - 48) / 7))));
            }
            $budget->check();
            return ['image/png', $renderer->canvas->png(), 0];
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
}
