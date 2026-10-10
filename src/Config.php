<?php

declare(strict_types=1);

namespace PureWms;

final class Config
{
    public readonly array $server;
    public readonly array $sources;
    public readonly CrsRegistry $crs;

    public function __construct(public readonly string $root)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('A 64-bit PHP installation is required.');
        }
        $this->server = self::json($root . '/config/server.json');
        $this->sources = self::json($root . '/config/sources.json');
        $this->crs = new CrsRegistry(is_file($root . '/config/crs.json') ? self::json($root . '/config/crs.json') : []);
        self::id($this->server['default_service'] ?? '');
        if (!is_array($this->server['cors_origins'] ?? [])) {
            throw new \RuntimeException('cors_origins must be an array.');
        }
        foreach ($this->server['cors_origins'] ?? [] as $origin) {
            if (!is_string($origin) || ($origin !== '*' && !preg_match('~^https?://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$~D', $origin))) {
                throw new \RuntimeException('Invalid CORS origin.');
            }
        }
        $url = $this->server['base_url'] ?? '';
        if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http','https'], true) || parse_url($url, PHP_URL_QUERY) || parse_url($url, PHP_URL_FRAGMENT) || parse_url($url, PHP_URL_USER)) {
            throw new \RuntimeException('base_url must be an absolute HTTP(S) URL to wms.php without query, fragment or credentials.');
        }
        foreach (['max_side' => [64,4096],'max_pixels' => [4096,4194304],'max_features' => [1,50000],'max_vertices' => [100,1000000],'max_seconds' => [1,60],'max_parallel' => [1,16],'cache_slots' => [0,2048],'cache_ttl' => [0,86400]] as $key => $range) {
            $value = $this->server[$key] ?? null;
            if (!is_int($value) || $value < $range[0] || $value > $range[1]) {
                throw new \RuntimeException('Invalid server setting: ' . $key);
            }
        }
    }

    public static function json(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) > 1048576) {
            throw new \RuntimeException('Cannot read configuration: ' . basename($path));
        }
        $value = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new \RuntimeException('Configuration must be an object.');
        }

        return $value;
    }

    public static function id(string $id): string
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id)) {
            throw new WmsException('Invalid identifier.');
        }

        return $id;
    }

    public function service(string $id): array
    {
        self::id($id);
        $path = $this->root . '/config/services/' . $id . '.json';
        if (!is_file($path)) {
            throw new WmsException('Unknown service.', 'InvalidParameterValue', 404);
        }
        $service = self::json($path);
        if (!is_string($service['title'] ?? null) || !is_array($service['layers'] ?? null) || !$service['layers']) {
            throw new \RuntimeException('Service requires title and layers.');
        }
        foreach ($service['layers'] as $name => $layer) {
            self::id($name);
            self::id($layer['dataset'] ?? '');
            if (!isset($this->sources[$layer['dataset']]) || !is_string($layer['title'] ?? null) || !is_array($layer['styles'] ?? null) || !$layer['styles']) {
                throw new \RuntimeException('Invalid layer: ' . $name);
            }
            if (!is_array($layer['query_fields'] ?? null)) {
                throw new \RuntimeException('Explicit query_fields allowlist required for ' . $name);
            }
            if (isset($layer['queryable']) && !is_bool($layer['queryable'])) {
                throw new \RuntimeException('queryable must be a boolean.');
            }
            foreach ($layer['query_fields'] as $field) {
                if (!is_string($field)) {
                    throw new \RuntimeException('Invalid query field.');
                }
            }
            foreach ($layer['styles'] as $style) {
                $this->style($style);
            }
        }

        return $service;
    }

    public function style(string $id): array
    {
        self::id($id);
        $style = self::json($this->root . '/config/styles/' . $id . '.json');
        if (isset($style['sld_file'])) {
            if (array_diff(array_keys($style), ['sld_file','sld_layer','sld_style'])) {
                throw new \RuntimeException('SLD wrappers cannot contain JSON symbolizer overrides.');
            }
            $xml = $this->sldFile($style['sld_file']);
            $compiled = $this->sldStyle($xml, $style['sld_layer'] ?? '', $style['sld_style'] ?? '');
            if (isset($compiled['_named_style'])) {
                throw new \RuntimeException('Configured SLD files must contain a UserStyle.');
            }
            return $compiled;
        }
        $this->validateSymbolizer($style);
        if (count($style['rules'] ?? []) > 32) {
            throw new \RuntimeException('Maximum 32 style rules.');
        }
        foreach ($style['rules'] ?? [] as $rule) {
            if (!is_string($rule['field'] ?? null) || !array_key_exists('equals', $rule) || !is_scalar($rule['equals'])) {
                throw new \RuntimeException('Rules need field and scalar equals.');
            }
            $this->validateSymbolizer($rule['symbolizer'] ?? []);
        }

        return $style;
    }

    public function sldFile(string $name): string
    {
        $path = $this->resource($name, 'sld');
        $xml = file_get_contents($path, false, null, 0, 131073);
        if ($xml === false || strlen($xml) > 131072) {
            throw new WmsException('Cannot read SLD or document exceeds 128 KiB.');
        }
        return $xml;
    }

    public function sldStyle(string $xml, string $layer, string $name = ''): array
    {
        $style = Sld::style($xml, $layer, $name);
        foreach (self::styleParts($style) as $part) {
            foreach (['font' => 'ttf', 'icon' => 'png'] as $key => $extension) {
                if (isset($part[$key])) {
                    try {
                        $this->resource($part[$key], $extension);
                    } catch (\RuntimeException $error) {
                        throw new WmsException('SLD graphics and fonts must name existing local resources.');
                    }
                }
            }
        }
        return $style;
    }

    public static function styleParts(array $style): array
    {
        if (!isset($style['_sld_groups'])) {
            return array_merge([$style], array_column($style['rules'] ?? [], 'symbolizer'));
        }
        $parts = [];
        foreach ($style['_sld_groups'] as $group) {
            foreach ($group as $rule) {
                array_push($parts, ...$rule['symbols']);
            }
        }
        return $parts;
    }

    private function validateSymbolizer(array $style): void
    {
        foreach (['fill','stroke','label_color','halo_color'] as $key) {
            if (isset($style[$key]) && !preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/iD', $style[$key])) {
                throw new \RuntimeException('Invalid color: ' . $key);
            }
        }
        foreach (['stroke_width' => [0,16],'size' => [1,128],'font_size' => [6,48],'halo' => [0,4]] as $key => $range) {
            if (isset($style[$key]) && (!is_int($style[$key]) || $style[$key] < $range[0] || $style[$key] > $range[1])) {
                throw new \RuntimeException('Invalid style setting: ' . $key);
            }
        }
        if (isset($style['symbol']) && !in_array($style['symbol'], ['circle','square','triangle','diamond','cross'], true)) {
            throw new \RuntimeException('Unsupported symbol.');
        }
        foreach (['font','icon'] as $key) {
            if (isset($style[$key])) {
                $this->resource($style[$key], $key === 'font' ? 'ttf' : 'png');
            }
        }
        foreach (['min_scale','max_scale'] as $key) {
            if (isset($style[$key]) && (!is_numeric($style[$key]) || $style[$key] < 0 || !is_finite((float)$style[$key]))) {
                throw new \RuntimeException('Invalid scale.');
            }
        }
        if (isset($style['label_field']) && !is_string($style['label_field'])) {
            throw new \RuntimeException('Invalid label field.');
        }
    }

    public function resource(string $name, string $extension): string
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/D', $name) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $extension) {
            throw new \RuntimeException('Invalid resource name.');
        }
        $root = realpath($this->root . '/resources');
        $path = realpath($root . '/' . $name);
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new \RuntimeException('Resource not found.');
        }

        return $path;
    }

    public function sourcePath(string $name): string
    {
        $root = realpath($this->root . '/sources');
        $path = realpath($root . '/' . $name);
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            throw new \RuntimeException('Source must be a local file below sources/.');
        }

        return $path;
    }
}
