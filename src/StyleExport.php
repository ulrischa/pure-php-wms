<?php

declare(strict_types=1);

namespace PureWms;

/** Export published styles as SLD 1.0 XML, including native JSON styles. */
final class StyleExport
{
    public static function build(Config $config, array $service, Request $request): string
    {
        if ($request->get('SLD_VERSION', '1.0.0') !== '1.0.0') {
            throw new WmsException('GetStyles exports SLD_VERSION=1.0.0.');
        }
        $names = explode(',', $request->get('LAYERS'));
        if (count($names) > 16 || in_array('', $names, true)) { throw new WmsException('Select 1–16 layers.'); }
        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'StyledLayerDescriptor', 'http://www.opengis.net/sld');
        $xml->writeAttribute('version', '1.0.0');
        $xml->writeAttribute('xmlns:ogc', 'http://www.opengis.net/ogc');
        $xml->writeAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');
        foreach (array_unique($names) as $name) {
            $layer = $service['layers'][$name] ?? throw new WmsException('Unknown layer.', 'LayerNotDefined');
            $xml->startElement('NamedLayer');
            $xml->writeElement('Name', $name);
            foreach ($layer['styles'] as $i => $id) {
                $style = $config->style($id);
                $xml->startElement('UserStyle');
                $xml->writeElement('Name', $id);
                $xml->writeElement('Title', $style['title'] ?? $id);
                $xml->writeElement('IsDefault', $i === 0 ? '1' : '0');
                $group = null;
                foreach (StyleRules::entries($style) as $rule) {
                    if ($group !== $rule['group']) {
                        if ($group !== null) { $xml->endElement(); }
                        $xml->startElement('FeatureTypeStyle');
                        $group = $rule['group'];
                    }
                    $xml->startElement('Rule');
                    $xml->writeElement('Name', $rule['name']);
                    $xml->writeElement('Title', $rule['title']);
                    $filter = isset($style['_sld_groups']) ? $rule['filter'] : self::nativeFilter($style, (int)$rule['id']);
                    if ($filter !== null) {
                        $xml->startElement('ogc:Filter');
                        self::filter($xml, $filter);
                        $xml->endElement();
                    } elseif ($rule['else']) { $xml->writeElement('ElseFilter'); }
                    $xml->writeElement('MinScaleDenominator', (string)$rule['min']);
                    $xml->writeElement('MaxScaleDenominator', (string)$rule['max']);
                    foreach ($rule['symbols'] as $symbol) { self::symbol($xml, $symbol); }
                    $xml->endElement();
                }
                if ($group !== null) { $xml->endElement(); }
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endDocument();
        $result = $xml->outputMemory();
        if (strlen($result) > 1048576) { throw new WmsException('Style export exceeds 1 MiB. Select fewer layers.'); }
        return $result;
    }

    private static function nativeFilter(array $style, int $index): ?array
    {
        $terms = [];
        foreach ($style['rules'] ?? [] as $i => $rule) {
            $equal = ['op' => 'PropertyIsEqualTo', 'field' => $rule['field'], 'value' => (string)$rule['equals']];
            if ($index === $i + 1) { $terms[] = $equal; break; }
            $terms[] = ['op' => 'Not', 'args' => [$equal]];
        }
        return count($terms) > 1 ? ['op' => 'And', 'args' => $terms] : ($terms[0] ?? null);
    }

    private static function filter(\XMLWriter $xml, array $filter): void
    {
        if ($filter['op'] === 'Filter') { self::filter($xml, $filter['args'][0]); return; }
        $xml->startElement('ogc:' . $filter['op']);
        if (isset($filter['like'])) {
            foreach ($filter['like'] as $key => $value) { $xml->writeAttribute($key, $value); }
        }
        if (isset($filter['args'])) {
            foreach ($filter['args'] as $child) { self::filter($xml, $child); }
        } else {
            $xml->writeElement('ogc:PropertyName', $filter['field']);
            if ($filter['op'] !== 'PropertyIsNull') {
                $xml->startElement('ogc:Literal');
                $xml->text((string)$filter['value']); $xml->endElement();
            }
        }
        $xml->endElement();
    }

    private static function paint(\XMLWriter $xml, string $element, string $key, string $color, ?array $style = null): void
    {
        $xml->startElement($element);
        self::parameter($xml, $key, substr($color, 0, 7));
        if (strlen($color) === 9) { self::parameter($xml, $key . '-opacity', (string)(hexdec(substr($color, 7)) / 255)); }
        if ($style !== null) {
            self::parameter($xml, 'stroke-width', (string)($style['stroke_width'] ?? 1));
            if (isset($style['stroke_dasharray'])) { self::parameter($xml, 'stroke-dasharray', implode(' ', $style['stroke_dasharray'])); }
        }
        $xml->endElement();
    }

    private static function parameter(\XMLWriter $xml, string $name, string $value): void
    {
        $xml->startElement('CssParameter'); $xml->writeAttribute('name', $name); $xml->text($value); $xml->endElement();
    }

    private static function symbol(\XMLWriter $xml, array $style): void
    {
        if ($style['_text_only'] ?? false) { self::text($xml, $style); return; }
        $kinds = isset($style['_geometry']) ? [$style['_geometry']] : ['point','line','polygon'];
        foreach ($kinds as $kind) {
            $xml->startElement(ucfirst($kind) . 'Symbolizer');
            if ($kind === 'point') {
                $xml->startElement('Graphic');
                if (isset($style['icon'])) {
                    $xml->startElement('ExternalGraphic');
                    $xml->startElement('OnlineResource'); $xml->writeAttribute('xlink:type', 'simple'); $xml->writeAttribute('xlink:href', $style['icon']); $xml->endElement();
                    $xml->writeElement('Format', 'image/png'); $xml->endElement();
                } else {
                    $xml->startElement('Mark'); $xml->writeElement('WellKnownName', $style['symbol'] ?? 'circle');
                    self::paint($xml, 'Fill', 'fill', $style['fill'] ?? '#e7893cff');
                    self::paint($xml, 'Stroke', 'stroke', $style['stroke'] ?? '#533516ff', $style);
                    $xml->endElement();
                }
                $xml->writeElement('Size', (string)($style['size'] ?? 12));
                $xml->endElement();
            } else {
                if ($kind === 'polygon') { self::paint($xml, 'Fill', 'fill', $style['fill'] ?? '#4ea88980'); }
                self::paint($xml, 'Stroke', 'stroke', $style['stroke'] ?? ($kind === 'line' ? '#253c4a' : '#285b4d'), $style);
            }
            $xml->endElement();
        }
        if (isset($style['label_field'])) { self::text($xml, $style); }
    }

    private static function text(\XMLWriter $xml, array $style): void
    {
        $xml->startElement('TextSymbolizer');
        $xml->startElement('Label'); $xml->writeElement('ogc:PropertyName', $style['label_field']); $xml->endElement();
        $xml->startElement('Font');
        self::parameter($xml, 'font-family', $style['font'] ?? 'DejaVuSans.ttf');
        self::parameter($xml, 'font-size', (string)(($style['font_size'] ?? 11) / 0.75));
        $xml->endElement();
        if (($style['label_follow_line'] ?? false) || isset($style['label_anchor']) || isset($style['label_angle'])) {
            $xml->startElement('LabelPlacement');
            if ($style['label_follow_line'] ?? false) { $xml->writeElement('LinePlacement'); }
            else {
                $xml->startElement('PointPlacement');
                $anchor = $style['label_anchor'] ?? [0.5,0.5]; $offset = $style['label_offset'] ?? [0,0];
                $xml->startElement('AnchorPoint'); $xml->writeElement('AnchorPointX', (string)$anchor[0]); $xml->writeElement('AnchorPointY', (string)$anchor[1]); $xml->endElement();
                $xml->startElement('Displacement'); $xml->writeElement('DisplacementX', (string)$offset[0]); $xml->writeElement('DisplacementY', (string)$offset[1]); $xml->endElement();
                $xml->writeElement('Rotation', (string)-($style['label_angle'] ?? 0)); $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->startElement('Halo'); $xml->writeElement('Radius', (string)($style['halo'] ?? 1));
        self::paint($xml, 'Fill', 'fill', $style['halo_color'] ?? '#ffffffff'); $xml->endElement();
        self::paint($xml, 'Fill', 'fill', $style['label_color'] ?? '#19352fff');
        $xml->endElement();
    }
}
