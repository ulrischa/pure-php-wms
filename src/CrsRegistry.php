<?php

declare(strict_types=1);

namespace PureWms;

/** Administrator-controlled definitions; request parameters can never trigger EPSG downloads. */
final class CrsRegistry
{
    public readonly array $definitions;

    public function __construct(array $additional = [])
    {
        $geographic = ['proj4' => '+proj=longlat +datum=WGS84 +no_defs', 'axis_order' => 'yx', 'meters_per_unit' => 111319.49079327358, 'bounds' => [-180,-90,180,90]];
        $definitions = ['EPSG:4326' => $geographic, 'CRS:84' => array_replace($geographic, ['axis_order' => 'xy']),
            'EPSG:3857' => ['proj4' => '+proj=merc +a=6378137 +b=6378137 +lat_ts=0 +lon_0=0 +x_0=0 +y_0=0 +k=1 +units=m +nadgrids=@null +no_defs', 'axis_order' => 'xy', 'meters_per_unit' => 1, 'bounds' => [-20037508.35,-20037508.35,20037508.35,20037508.35]]];
        foreach ([32,33] as $zone) {
            $definitions['EPSG:258' . $zone] = ['proj4' => "+proj=utm +zone=$zone +ellps=GRS80 +towgs84=0,0,0 +units=m +no_defs", 'axis_order' => 'xy', 'meters_per_unit' => 1, 'bounds' => [100000,0,900000,10000000]];
        }
        if (count($additional) > 64) {
            throw new \RuntimeException('At most 64 additional CRS definitions.');
        }
        foreach ($additional as $code => $definition) {
            if (!is_string($code) || !preg_match('/^EPSG:[1-9][0-9]{2,6}$/D', $code) || isset($definitions[$code]) || !is_array($definition)) {
                throw new \RuntimeException('Invalid or reserved CRS code: ' . $code);
            }
            $proj4 = $definition['proj4'] ?? '';
            if (!is_string($proj4) || strlen($proj4) > 2048 || !preg_match('/(?:^|\s)\+proj=([a-z]+)(?:\s|$)/', $proj4, $match)
                || preg_match('/\+(?:init|axis|nadgrids|geoidgrids)\b|[\x00-\x1f]/', $proj4)
                || ($match[1] !== 'longlat' && !class_exists('proj4php\\projCode\\' . ucfirst($match[1])))) {
                throw new \RuntimeException('Use a local supported PROJ definition without grids, init or axis: ' . $code);
            }
            if (!in_array($definition['axis_order'] ?? null, ['xy','yx'], true)
                || !is_numeric($definition['meters_per_unit'] ?? null) || !is_finite((float)$definition['meters_per_unit']) || $definition['meters_per_unit'] <= 0) {
                throw new \RuntimeException('CRS requires axis_order and positive meters_per_unit: ' . $code);
            }
            $bounds = $definition['bounds'] ?? null;
            if (!is_array($bounds) || !array_is_list($bounds) || count($bounds) !== 4) {
                throw new \RuntimeException('CRS requires four XY domain bounds: ' . $code);
            }
            foreach ($bounds as $value) {
                if (!is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > 1e9) {
                    throw new \RuntimeException('Invalid CRS domain: ' . $code);
                }
            }
            if ($bounds[0] >= $bounds[2] || $bounds[1] >= $bounds[3]) {
                throw new \RuntimeException('CRS domain must have positive extent.');
            }
            $definitions[$code] = $definition;
        }
        $this->definitions = $definitions;
    }

    public function get(string $code): array
    {
        return $this->definitions[$code] ?? throw new WmsException('Unsupported CRS: ' . $code, 'InvalidCRS');
    }

    public function codes(): array
    {
        return array_keys($this->definitions);
    }

    public function wmsBounds(array $xy, string $code): array
    {
        return $this->get($code)['axis_order'] === 'yx' ? [$xy[1],$xy[0],$xy[3],$xy[2]] : $xy;
    }
}
