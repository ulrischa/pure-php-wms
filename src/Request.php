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

    public function map(int $maxSide = 2048, int $maxPixels = 2097152, ?CrsRegistry $registry = null): array
    {
        if ($this->get('VERSION') !== '1.3.0') {
            throw new WmsException('Only WMS 1.3.0 is supported.', 'InvalidParameterValue');
        }
        if ($this->get('FORMAT') !== 'image/png') {
            throw new WmsException('Supported map format: image/png.', 'InvalidFormat');
        }
        $crs = $this->get('CRS');
        $registry ??= new CrsRegistry();
        $definition = $registry->get($crs);
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
        $bbox = $registry->wmsBounds($bbox, $crs);
        if ($bbox[0] >= $bbox[2] || $bbox[1] >= $bbox[3]) {
            throw new WmsException('BBOX must have positive width and height; antimeridian wrapping is unsupported.');
        }
        $domain = $definition['bounds'];
        if ($bbox[0] < $domain[0] || $bbox[1] < $domain[1] || $bbox[2] > $domain[2] || $bbox[3] > $domain[3]) {
            throw new WmsException('BBOX is outside the configured CRS domain.');
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
