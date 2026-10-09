<?php

declare(strict_types=1);

namespace PureWms;

final class Request
{
    private function __construct(public readonly array $parameters)
    {
    }

    public static function fromQuery(string $query): self
    {
        if (strlen($query) > 8192) {
            throw new WmsException('Query exceeds 8192 bytes.');
        }
        $parameters = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = strtoupper(urldecode($key));
            $value = urldecode($value);
            if (!preg_match('/^[A-Z][A-Z0-9_.-]*$/D', $key) || isset($parameters[$key]) || str_contains($value, "\0")) {
                throw new WmsException('Invalid or duplicate parameter.');
            }
            $parameters[$key] = $value;
        }

        return new self($parameters);
    }

    public function get(string $key, ?string $default = null): string
    {
        if (!array_key_exists($key, $this->parameters) && $default === null) {
            throw new WmsException('Missing parameter: ' . $key, 'MissingParameterValue');
        }

        return $this->parameters[$key] ?? $default;
    }

    public function integer(string $key, int $min, int $max, ?int $default = null): int
    {
        $value = trim($this->get($key, $default === null ? null : (string)$default));
        if (!preg_match('/^[+-]?\d+$/D', $value) || (int)$value < $min || (int)$value > $max) {
            throw new WmsException("$key must be between $min and $max.");
        }

        return (int)$value;
    }

    public function map(int $maxSide = 2048, int $maxPixels = 2097152): array
    {
        if ($this->get('VERSION') !== '1.3.0') {
            throw new WmsException('Only WMS 1.3.0 is supported.', 'InvalidParameterValue');
        }
        if ($this->get('FORMAT') !== 'image/png') {
            throw new WmsException('Supported map format: image/png.', 'InvalidFormat');
        }
        $crs = $this->get('CRS');
        if (!in_array($crs, Projection::OUTPUT_CRS, true)) {
            throw new WmsException('Unsupported CRS.', 'InvalidCRS');
        }
        $bbox = explode(',', $this->get('BBOX'));
        if (count($bbox) !== 4) {
            throw new WmsException('BBOX requires four numbers.');
        }
        foreach ($bbox as &$number) {
            if (!is_numeric($number) || !is_finite((float)$number) || abs((float)$number) > 1e9) {
                throw new WmsException('Invalid BBOX coordinate.');
            }
            $number = (float)$number;
        }
        unset($number);
        if ($crs === 'EPSG:4326') {
            $bbox = [$bbox[1], $bbox[0], $bbox[3], $bbox[2]];
        }
        if ($bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3]) {
            throw new WmsException('BBOX must have positive width and height; antimeridian wrapping is unsupported.');
        }
        if (in_array($crs, ['EPSG:4326','CRS:84'], true) && ($bbox[0] < -180 || $bbox[2] > 180 || $bbox[1] < -90 || $bbox[3] > 90)) {
            throw new WmsException('Geographic BBOX is outside the valid range.');
        }
        if ($crs === 'EPSG:3857' && (min($bbox) < -20037508.35 || max($bbox) > 20037508.35)) {
            throw new WmsException('Mercator BBOX exceeds the supported world extent.');
        }
        if (in_array($crs, ['EPSG:25832','EPSG:25833'], true) && ($bbox[0] < 100000 || $bbox[2] > 900000 || $bbox[1] < 0 || $bbox[3] > 10000000)) {
            throw new WmsException('UTM BBOX is outside its supported zone domain.');
        }
        $width = $this->integer('WIDTH', 1, $maxSide);
        $height = $this->integer('HEIGHT', 1, $maxSide);
        if ($width * $height > $maxPixels) {
            throw new WmsException('Image exceeds pixel limit.');
        }
        $transparent = strtoupper($this->get('TRANSPARENT', 'FALSE'));
        if (!in_array($transparent, ['TRUE','FALSE'], true)) {
            throw new WmsException('TRANSPARENT must be TRUE or FALSE.');
        }
        $background = $this->get('BGCOLOR', '0xFFFFFF');
        if (!preg_match('/^0x[0-9a-fA-F]{6}$/D', $background)) {
            throw new WmsException('Invalid BGCOLOR.');
        }
        if ($this->get('EXCEPTIONS', 'XML') !== 'XML') {
            throw new WmsException('Supported exception format: XML.', 'InvalidFormat');
        }
        $layers = explode(',', $this->get('LAYERS'));
        if (!$layers || count($layers) > 16 || in_array('', $layers, true)) {
            throw new WmsException('Select 1–16 layers.', 'LayerNotDefined');
        }
        $stylesValue = $this->get('STYLES');
        $styles = $stylesValue === '' ? array_fill(0, count($layers), '') : explode(',', $stylesValue);
        if (count($styles) !== count($layers)) {
            throw new WmsException('STYLES must match LAYERS.', 'StyleNotDefined');
        }

        return ['bbox' => $bbox, 'crs' => $crs, 'width' => $width, 'height' => $height, 'layers' => $layers, 'styles' => $styles,
            'background' => '#' . substr($background, 2) . ($transparent === 'TRUE' ? '00' : 'ff')];
    }
}
