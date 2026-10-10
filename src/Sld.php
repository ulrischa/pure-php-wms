<?php

declare(strict_types=1);

namespace PureWms;

/** Strict, bounded SLD 1.0 / SE 1.1 vector styling profile. No XML or URL fetching. */
final class Sld
{
    private const SLD = 'http://www.opengis.net/sld';
    private const SE = 'http://www.opengis.net/se';
    private const OGC = 'http://www.opengis.net/ogc';

    public static function style(string $xml, string $layerName, string $styleName = ''): array
    {
        if (strlen($xml) > 131072 || str_contains($xml, "\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            throw new WmsException('SLD exceeds 128 KiB or contains forbidden declarations.', 'InvalidParameterValue');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->doctype !== null || $document->getElementsByTagName('*')->length > 2048) {
            throw new WmsException('Invalid or excessively complex SLD.');
        }
        $root = $document->documentElement;
        if ($root->localName !== 'StyledLayerDescriptor' || $root->namespaceURI !== self::SLD || !in_array($root->getAttribute('version'), ['1.0.0','1.1.0'], true)) {
            throw new WmsException('Expected StyledLayerDescriptor version 1.0.0 or 1.1.0.');
        }
        self::children($root, ['Name','Title','Abstract','NamedLayer']);
        $selected = null;
        foreach (self::all($root, 'NamedLayer') as $layer) {
            self::children($layer, ['Name','NamedStyle','UserStyle']);
            if (self::value($layer, 'Name') === $layerName) {
                if ($selected !== null) {
                    throw new WmsException('Duplicate SLD NamedLayer.');
                }
                $selected = $layer;
            }
        }
        if ($selected === null) {
            throw new WmsException('SLD does not define the requested layer.', 'LayerNotDefined');
        }
        $styles = array_merge(self::all($selected, 'NamedStyle'), self::all($selected, 'UserStyle'));
        $chosen = null;
        foreach ($styles as $style) {
            $name = self::value($style, 'Name', '');
            if (($styleName !== '' && $name === $styleName) || ($styleName === '' && (count($styles) === 1 || self::value($style, 'IsDefault', '0') === '1' || self::value($style, 'IsDefault', '0') === 'true'))) {
                if ($chosen !== null) {
                    throw new WmsException('Ambiguous SLD style.', 'StyleNotDefined');
                }
                $chosen = $style;
            }
        }
        if ($chosen === null) {
            throw new WmsException('Select an SLD style by name or mark one IsDefault.', 'StyleNotDefined');
        }
        if ($chosen->localName === 'NamedStyle') {
            self::children($chosen, ['Name']);
            return ['_named_style' => self::value($chosen, 'Name')];
        }
        self::children($chosen, ['Name','Title','Abstract','Description','IsDefault','FeatureTypeStyle']);
        $groups = [];
        $ruleCount = 0;
        foreach (self::all($chosen, 'FeatureTypeStyle') as $group) {
            self::children($group, ['Name','Title','Abstract','Description','Rule']);
            $rules = [];
            foreach (self::all($group, 'Rule') as $rule) {
                if (++$ruleCount > 64) {
                    throw new WmsException('At most 64 SLD rules.');
                }
                self::children($rule, ['Name','Title','Abstract','Description','Filter','ElseFilter','MinScaleDenominator','MaxScaleDenominator','PointSymbolizer','LineSymbolizer','PolygonSymbolizer','TextSymbolizer']);
                $filter = self::one($rule, 'Filter');
                $else = self::one($rule, 'ElseFilter') !== null;
                if ($filter !== null && $else) {
                    throw new WmsException('SLD rule cannot combine Filter and ElseFilter.');
                }
                $symbols = [];
                foreach (self::children($rule) as $child) {
                    if (str_ends_with($child->localName, 'Symbolizer')) {
                        $symbols[] = self::symbolizer($child);
                    }
                }
                if (!$symbols || count($symbols) > 8) {
                    throw new WmsException('SLD rules require 1–8 symbolizers.');
                }
                $min = self::number(self::value($rule, 'MinScaleDenominator', '0'), 0, 1e12);
                $max = self::number(self::value($rule, 'MaxScaleDenominator', '1000000000000'), 0, 1e12);
                if ($min >= $max) {
                    throw new WmsException('SLD scale range must have positive extent.');
                }
                $rules[] = ['name' => self::value($rule, 'Name', 'rule_' . ($ruleCount - 1)), 'filter' => $filter ? self::filter($filter) : null, 'else' => $else, 'min' => $min, 'max' => $max, 'symbols' => $symbols, 'title' => self::value($rule, 'Title', self::value($rule, 'Name', 'Rule'))];
            }
            if (!$rules) {
                throw new WmsException('SLD FeatureTypeStyle requires rules.');
            }
            $groups[] = $rules;
        }
        if (!$groups) {
            throw new WmsException('SLD UserStyle requires FeatureTypeStyle.');
        }
        return ['title' => self::value($chosen, 'Title', self::value($chosen, 'Name', 'SLD style')), '_sld_groups' => $groups];
    }

    private static function symbolizer(\DOMElement $node): array
    {
        $kind = $node->localName;
        $style = ['fill' => '#808080', 'stroke' => '#000000', 'stroke_width' => 0, '_geometry' => strtolower(substr($kind, 0, -10))];
        if ($node->hasAttribute('uom') || $node->hasAttribute('version')) {
            throw new WmsException('Symbolizer units/version overrides are unsupported.');
        }
        if ($kind === 'TextSymbolizer') {
            self::children($node, ['Label','Font','LabelPlacement','Fill','Halo']);
            $label = self::one($node, 'Label', true);
            self::children($label, ['PropertyName']);
            $style['label_field'] = self::value($label, 'PropertyName');
            $style['_text_only'] = true;
            $style['_geometry'] = 'any';
            $style['label_color'] = self::paint(self::one($node, 'Fill'), 'fill', '#000000')['fill'];
            $font = self::parameters(self::one($node, 'Font'), ['font-family','font-size','font-style','font-weight']);
            if (($font['font-style'] ?? 'normal') !== 'normal' || ($font['font-weight'] ?? 'normal') !== 'normal') {
                throw new WmsException('Use a local font file for styled font variants.');
            }
            $family = $font['font-family'] ?? 'DejaVuSans.ttf';
            $style['font'] = $family === 'DejaVu Sans' ? 'DejaVuSans.ttf' : $family;
            // SLD font size is pixels; GD FreeType accepts points at 96 DPI.
            $style['font_size'] = self::number($font['font-size'] ?? '14.6666667', 8, 64) * 0.75;
            $placement = self::one($node, 'LabelPlacement');
            if ($placement !== null) {
                self::children($placement, ['PointPlacement','LinePlacement']);
                $point = self::one($placement, 'PointPlacement');
                $line = self::one($placement, 'LinePlacement');
                if (($point === null) === ($line === null)) { throw new WmsException('Select one label placement.'); }
                $style['label_anchor'] = [0.5,0.5];
                $style['label_offset'] = [0,0];
                if ($line !== null) {
                    self::children($line, []);
                    $style['label_follow_line'] = true;
                } else {
                    self::children($point, ['AnchorPoint','Displacement','Rotation']);
                    $style['label_angle'] = -self::number(self::value($point, 'Rotation', '0'), -360, 360);
                    foreach (['AnchorPoint' => ['label_anchor','AnchorPointX','AnchorPointY',0,1], 'Displacement' => ['label_offset','DisplacementX','DisplacementY',-256,256]] as $element => $settings) {
                        $part = self::one($point, $element);
                        if ($part !== null) {
                            self::children($part, [$settings[1],$settings[2]]);
                            $style[$settings[0]] = [self::number(self::value($part, $settings[1]), $settings[3], $settings[4]), self::number(self::value($part, $settings[2]), $settings[3], $settings[4])];
                        }
                    }
                }
            }
            $halo = self::one($node, 'Halo');
            $style['halo'] = 0;
            if ($halo !== null) {
                self::children($halo, ['Radius','Fill']);
                $style['halo'] = (int)round(self::number(self::value($halo, 'Radius', '1'), 0, 4));
                $style['halo_color'] = self::paint(self::one($halo, 'Fill'), 'fill', '#ffffff')['fill'];
            }
            return $style;
        }
        if ($kind === 'PointSymbolizer') {
            self::children($node, ['Graphic']);
            $graphic = self::one($node, 'Graphic', true);
            self::children($graphic, ['Mark','ExternalGraphic','Size','Opacity']);
            $style['size'] = (int)round(self::number(self::value($graphic, 'Size', '12'), 1, 128));
            $mark = self::one($graphic, 'Mark');
            $external = self::one($graphic, 'ExternalGraphic');
            if (($mark === null) === ($external === null)) {
                throw new WmsException('SLD Graphic requires one Mark or ExternalGraphic.');
            }
            if ($external !== null) {
                self::children($external, ['OnlineResource','Format']);
                if (self::value($external, 'Format') !== 'image/png') {
                    throw new WmsException('SLD external graphics must be local PNG resources.');
                }
                $style['icon'] = self::one($external, 'OnlineResource', true)->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                if (self::value($graphic, 'Opacity', '1') !== '1') {
                    throw new WmsException('Set PNG transparency in the image for external graphics.');
                }
                return $style;
            }
            self::children($mark, ['WellKnownName','Fill','Stroke']);
            $style['symbol'] = self::value($mark, 'WellKnownName', 'square');
            if (!in_array($style['symbol'], ['circle','square','triangle','diamond','cross'], true)) {
                throw new WmsException('Unsupported SLD mark.');
            }
            $style = array_replace($style, self::paint(self::one($mark, 'Fill'), 'fill', '#808080'), self::paint(self::one($mark, 'Stroke'), 'stroke', '#000000'));
            $opacity = self::number(self::value($graphic, 'Opacity', '1'), 0, 1);
            foreach (['fill','stroke'] as $key) {
                $style[$key] = self::opacity($style[$key], $opacity);
            }
            return $style;
        }
        self::children($node, $kind === 'PolygonSymbolizer' ? ['Fill','Stroke'] : ['Stroke']);
        $fill = self::one($node, 'Fill');
        $stroke = self::one($node, 'Stroke');
        $style['fill'] = '#00000000';
        if ($fill !== null) {
            $style = array_replace($style, self::paint($fill, 'fill', '#808080'));
        }
        if ($stroke !== null) {
            $style = array_replace($style, self::paint($stroke, 'stroke', '#000000'));
        }
        return $style;
    }

    private static function paint(?\DOMElement $node, string $kind, string $default): array
    {
        $allowed = $kind === 'fill' ? ['fill','fill-opacity'] : ['stroke','stroke-opacity','stroke-width','stroke-dasharray'];
        $parameters = self::parameters($node, $allowed);
        $color = $parameters[$kind] ?? $default;
        if (!preg_match('/^#[a-fA-F0-9]{6}$/D', $color)) {
            throw new WmsException('SLD colors must use #RRGGBB.');
        }
        $result = [$kind => self::opacity($color, self::number($parameters[$kind . '-opacity'] ?? '1', 0, 1))];
        if ($kind === 'stroke') {
            $result['stroke_width'] = (int)round(self::number($parameters['stroke-width'] ?? '1', 0, 16));
            if (isset($parameters['stroke-dasharray'])) {
                $values = preg_split('/[ ,]+/', trim($parameters['stroke-dasharray']));
                if (!$values || count($values) > 16) { throw new WmsException('At most 16 dash lengths.'); }
                $result['stroke_dasharray'] = array_map(static fn ($value) => (int)round(self::number($value, 1, 128)), $values);
            }
        }
        return $result;
    }

    private static function opacity(string $color, float $opacity): string
    {
        $alpha = strlen($color) === 9 ? hexdec(substr($color, 7, 2)) : 255;
        return substr($color, 0, 7) . sprintf('%02x', (int)round($alpha * $opacity));
    }

    private static function parameters(?\DOMElement $node, array $allowed): array
    {
        if ($node === null) {
            return [];
        }
        $result = [];
        foreach (self::children($node, ['CssParameter','SvgParameter']) as $parameter) {
            $name = $parameter->getAttribute('name');
            if (!in_array($name, $allowed, true) || isset($result[$name])) {
                throw new WmsException('Unsupported or duplicate SLD parameter: ' . $name);
            }
            $result[$name] = self::text($parameter);
        }
        return $result;
    }

    private static function filter(\DOMElement $node, int $depth = 0): array
    {
        if ($depth > 8 || $node->namespaceURI !== self::OGC) {
            throw new WmsException('Invalid or excessively nested OGC filter.');
        }
        $operator = $node->localName;
        if (in_array($operator, ['Filter','And','Or','Not'], true)) {
            $children = self::children($node);
            if (!$children || (in_array($operator, ['And','Or'], true) && count($children) < 2) || (in_array($operator, ['Filter','Not'], true) && count($children) !== 1)) {
                throw new WmsException('Invalid logical filter operands.');
            }
            return ['op' => $operator, 'args' => array_map(static fn ($child) => self::filter($child, $depth + 1), $children)];
        }
        if (!in_array($operator, ['PropertyIsEqualTo','PropertyIsNotEqualTo','PropertyIsLessThan','PropertyIsLessThanOrEqualTo','PropertyIsGreaterThan','PropertyIsGreaterThanOrEqualTo','PropertyIsNull','PropertyIsLike'], true)) {
            throw new WmsException('Unsupported SLD filter: ' . $operator);
        }
        if ($node->hasAttribute('matchCase') && !in_array($node->getAttribute('matchCase'), ['true','1'], true)) {
            throw new WmsException('Only case-sensitive SLD comparisons are supported.');
        }
        self::children($node, $operator === 'PropertyIsNull' ? ['PropertyName'] : ['PropertyName','Literal']);
        if ($operator === 'PropertyIsLike') {
            $like = [];
            foreach (['wildCard','singleChar','escapeChar'] as $attribute) {
                $like[$attribute] = $node->getAttribute($attribute);
                if (strlen($like[$attribute]) !== 1) { throw new WmsException('Like filter requires single-byte wildcard, singleChar and escapeChar.'); }
            }
            if (count(array_unique($like)) !== 3) { throw new WmsException('Like control characters must be distinct.'); }
            $literal = self::value($node, 'Literal');
            if (strlen($literal) > 256) { throw new WmsException('Like pattern exceeds 256 bytes.'); }
            return ['op' => $operator, 'field' => self::value($node, 'PropertyName'), 'value' => $literal, 'like' => $like];
        }
        return ['op' => $operator, 'field' => self::value($node, 'PropertyName'), 'value' => $operator === 'PropertyIsNull' ? null : self::value($node, 'Literal')];
    }

    public static function matches(?array $filter, array $properties): bool
    {
        if ($filter === null) {
            return true;
        }
        $op = $filter['op'];
        if (isset($filter['args'])) {
            $values = array_map(static fn ($child) => self::matches($child, $properties), $filter['args']);
            return match ($op) { 'Not' => !$values[0], 'Or' => in_array(true, $values, true), default => !in_array(false, $values, true) };
        }
        $value = $properties[$filter['field']] ?? null;
        if ($op === 'PropertyIsNull') {
            return $value === null;
        }
        if (!is_scalar($value)) {
            return false;
        }
        $expected = $filter['value'];
        if ($op === 'PropertyIsLike') {
            $pattern = '';
            $escaped = false;
            foreach (str_split($expected) as $character) {
                if ($escaped) { $pattern .= preg_quote($character, '~'); $escaped = false; }
                elseif ($character === $filter['like']['escapeChar']) { $escaped = true; }
                elseif ($character === $filter['like']['wildCard']) { $pattern .= '.*'; }
                elseif ($character === $filter['like']['singleChar']) { $pattern .= '.'; }
                else { $pattern .= preg_quote($character, '~'); }
            }
            if ($escaped) { $pattern .= preg_quote($filter['like']['escapeChar'], '~'); }
            $matched = preg_match('~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)\A' . $pattern . '\z~us', (string)$value);
            if ($matched === false) { throw new WmsException('Like filter exceeds pattern evaluation limits.'); }
            return $matched === 1;
        }
        $compare = is_numeric($value) && is_numeric($expected) ? ((float)$value <=> (float)$expected) : strcmp((string)$value, $expected);
        return match ($op) {
            'PropertyIsEqualTo' => $compare === 0, 'PropertyIsNotEqualTo' => $compare !== 0,
            'PropertyIsLessThan' => $compare < 0, 'PropertyIsLessThanOrEqualTo' => $compare <= 0,
            'PropertyIsGreaterThan' => $compare > 0, 'PropertyIsGreaterThanOrEqualTo' => $compare >= 0,
        };
    }

    private static function children(\DOMElement $node, ?array $allowed = null): array
    {
        $result = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if (!in_array($child->namespaceURI, [self::SLD,self::SE,self::OGC], true) || ($allowed !== null && !in_array($child->localName, $allowed, true))) {
                throw new WmsException('Unsupported SLD element: ' . $child->nodeName);
            }
            if ($child->localName === 'Description') {
                self::children($child, ['Title','Abstract']);
            }
            $result[] = $child;
        }
        return $result;
    }

    private static function all(\DOMElement $node, string $name): array
    {
        return array_values(array_filter(self::children($node), static fn ($child) => $child->localName === $name));
    }

    private static function one(\DOMElement $node, string $name, bool $required = false): ?\DOMElement
    {
        $nodes = self::all($node, $name);
        if (count($nodes) > 1 || (!$nodes && $required)) {
            throw new WmsException('Missing or duplicate SLD element: ' . $name);
        }
        return $nodes[0] ?? null;
    }

    private static function value(\DOMElement $node, string $name, ?string $default = null): string
    {
        $child = self::one($node, $name, $default === null);
        return $child ? self::text($child) : $default;
    }

    private static function text(\DOMElement $node): string
    {
        $children = self::children($node);
        if ($children && (count($children) !== 1 || $children[0]->localName !== 'Literal' || $children[0]->namespaceURI !== self::OGC || self::children($children[0]))) {
            throw new WmsException('Only literal SLD values are supported here.');
        }
        return trim($node->textContent);
    }

    private static function number(string $value, float $min, float $max): float
    {
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < $min || (float)$value > $max) {
            throw new WmsException('SLD numeric value outside supported range.');
        }
        return (float)$value;
    }
}
