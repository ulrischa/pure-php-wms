<?php

declare(strict_types=1);

namespace PureWms;

final class Server
{
    public array $diagnostics = ['cache' => 'none'];
    public function __construct(private readonly Config $config)
    {
    }

    /** Return MIME type, body and cache lifetime. */
    public function handle(Request $request): array
    {
        $operation = $request->get('REQUEST');
        $this->diagnostics = ['operation' => $operation, 'cache' => 'none'];
        if ($request->get('SERVICE', $operation === 'GetCapabilities' ? null : 'WMS') !== 'WMS') {
            throw new WmsException('SERVICE must be WMS.');
        }
        // Unknown extension parameters are ignored. SLD resources are strictly local.
        if (!in_array($operation, ['GetCapabilities','GetMap','GetFeatureInfo','GetLegendGraphic','GetStyles'], true)) {
            throw new WmsException('Unsupported operation.', 'OperationNotSupported');
        }
        $id = $request->get('MAP', $this->config->server['default_service']);
        $service = $this->config->service($id);
        if ($operation !== 'GetCapabilities' && $request->get('VERSION') !== '1.3.0') {
            throw new WmsException('Only WMS 1.3.0 is supported.');
        }
        if ($operation === 'GetCapabilities') {
            $version = $request->get('VERSION', '1.3.0');
            if (!preg_match('/^\d+\.\d{1,2}\.\d{1,2}$/D', $version)) {
                throw new WmsException('Invalid version.');
            }

            $datasets = [];
            foreach ($service['layers'] as $layer) {
                $dataset = $layer['dataset'];
                $datasets[$dataset] ??= $this->dataset($dataset);
            }
            return ['text/xml',Capabilities::build($this->config, $id, $service, $datasets),0];
        }
        if ($operation === 'GetStyles') {
            return ['application/vnd.ogc.sld+xml', StyleExport::build($this->config, $service, $request), 0];
        }
        if ($operation === 'GetLegendGraphic') {
            return $this->legend($request, $service);
        }
        $map = $request->map($this->config->server['max_side'], $this->config->server['max_pixels'], $this->config->crs);
        $styles = [];
        foreach ($map['layers'] as $i => $name) {
            $layer = $service['layers'][$name] ?? throw new WmsException('Unknown layer: ' . $name, 'LayerNotDefined');
            $styles[] = $this->requestedStyle($request, $name, $layer, $map['styles'][$i]);
        }
        $datasets = [];
        $scale = LayerPlan::scale($map, $this->config->crs);
        foreach ($map['layers'] as $i => $name) {
            $service['layers'][$name]['dataset'] = LayerPlan::dataset($service['layers'][$name], $scale);
            if (LayerPlan::visible($styles[$i], $scale)) {
                $dataset = $service['layers'][$name]['dataset'];
                $datasets[$dataset] ??= $this->dataset($dataset);
            }
        }
        $cache = new Cache($this->config);
        $revisions = [];
        foreach ($datasets as $key => $dataset) {
            $revisions[$key] = $dataset->metadata['revision'];
        }
        $resources = [];
        foreach ($styles as $style) {
            foreach (Config::styleParts($style) as $part) {
                foreach (['font' => 'ttf','icon' => 'png'] as $key => $ext) {
                    $name = $part[$key] ?? ($key === 'font' ? 'DejaVuSans.ttf' : null);
                    if ($name !== null) {
                        $path = $this->config->resource($name, $ext);
                        $resources[$name] ??= hash_file('sha256', $path);
                    }
                }
            }
        }
        $key = hash('sha256', json_encode(['renderer' => 4, 'crs_definitions' => $this->config->crs->definitions,'map' => $map,'styles' => $styles,'service' => $service,'datasets' => $revisions,'resources' => $resources], JSON_THROW_ON_ERROR));
        if ($operation === 'GetMap' && ($cached = $cache->read($key)) !== null) {
            $this->diagnostics['cache'] = 'hit';
            return ['image/png',$cached,0];
        }
        $keyLock = null;
        $lock = null;
        $budget = null;
        try {
            if ($operation === 'GetMap') {
                $keyLock = $cache->acquireKey($key);
                if (($cached = $cache->read($key)) !== null) {
                    $this->diagnostics['cache'] = 'hit';
                    return ['image/png',$cached,0];
                }
                $this->diagnostics['cache'] = 'miss';
            }
            $lock = $cache->acquire();
            $budget = new Budget($this->config->server);
            $renderer = new Renderer($this->config, $map, $budget);
            $bbox = $renderer->geographicBounds();
            if ($operation === 'GetFeatureInfo') {
                return $this->featureInfo($request, $service, $datasets, $styles, $renderer, $bbox);
            }
            foreach ($map['layers'] as $i => $name) {
                $layer = $service['layers'][$name];
                if (!LayerPlan::visible($styles[$i], $scale)) {
                    continue;
                }
                // Each FeatureTypeStyle is a separate painter's pass over the layer.
                $passes = isset($styles[$i]['_sld_groups'])
                    ? array_map(static fn ($group) => ['_sld_groups' => [$group]], $styles[$i]['_sld_groups'])
                    : [$styles[$i]];
                foreach ($passes as $pass) {
                    foreach ($datasets[$layer['dataset']]->query($bbox, $this->config->server['max_features']) as $feature) {
                        $budget->check();
                        $renderer->feature($feature, $pass);
                    }
                }
            }
            $renderer->labels();
            $budget->check();
            $png = $renderer->canvas->png();
            $cache->write($key, $png);

            return ['image/png',$png,0];
        } finally {
            if ($budget !== null) { $this->diagnostics += $budget->metrics(); }
            foreach ([$lock, $keyLock] as $handle) {
                if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
            }
        }
    }

    private function featureInfo(Request $request, array $service, array $datasets, array $styles, Renderer $renderer, array $bbox): array
    {
        $format = $request->get('INFO_FORMAT');
        if (!in_array($format, ['application/json','text/plain'], true)) {
            throw new WmsException('Supported INFO_FORMAT: application/json, text/plain.', 'InvalidFormat');
        }
        $map = $renderer->map;
        try {
            $x = $request->integer('I', 0, $map['width'] - 1);
            $y = $request->integer('J', 0, $map['height'] - 1);
        } catch (WmsException $error) {
            throw new WmsException('I and J must identify a pixel inside the map.', 'InvalidPoint');
        }
        $requestedCount = trim($request->get('FEATURE_COUNT', '1'));
        $count = preg_match('/^\+?\d+$/D', $requestedCount) && (float)$requestedCount > 0
            ? min(100, (int)$requestedCount) : 1;
        $queryLayers = array_values(array_unique(explode(',', $request->get('QUERY_LAYERS'))));
        // The largest supported symbol is 128 px; include its radius in the
        // spatial prefilter, then apply each feature's actual hit tolerance.
        $bbox = $renderer->queryBounds($x + 0.5, $y + 0.5, 64);
        $resultBytes = 0;
        $features = [];
        foreach ($queryLayers as $name) {
            $i = array_search($name, $map['layers'], true);
            if ($i === false) {
                throw new WmsException('QUERY_LAYERS must be a subset of LAYERS.', 'LayerNotDefined');
            }
            $layer = $service['layers'][$name];
            if (!($layer['queryable'] ?? true)) {
                throw new WmsException('Layer cannot be queried.', 'LayerNotQueryable');
            }
        }
        foreach ($queryLayers as $name) {
            $i = array_search($name, $map['layers'], true);
            $layer = $service['layers'][$name];
            if (!LayerPlan::visible($styles[$i], $renderer->scale)) {
                continue;
            }
            $matches = [];
            $matchBytes = 0;
            foreach ($datasets[$layer['dataset']]->query($bbox, $this->config->server['max_features']) as $feature) {
                $matchedStyles = $renderer->symbolizers($styles[$i], $feature['properties']);
                if (!$matchedStyles) {
                    continue;
                }
                $geometry = $renderer->screenGeometry($feature['geometry']);
                $pointType = in_array($geometry['type'], ['Point','MultiPoint'], true);
                $distance = INF;
                foreach ($matchedStyles as $style) {
                    if (($style['_text_only'] ?? false) && (!isset($feature['properties'][$style['label_field']]) || !is_scalar($feature['properties'][$style['label_field']]) || (string)$feature['properties'][$style['label_field']] === '')) {
                        continue;
                    }
                    $tolerance = $pointType ? max(5, ($style['size'] ?? 12) / 2) : max(3, ($style['stroke_width'] ?? 1) / 2 + 2);
                    $candidate = $renderer->hitDistance($geometry, $style, [$x + 0.5,$y + 0.5]);
                    if ($candidate <= $tolerance) {
                        $distance = min($distance, $candidate);
                    }
                }
                if (!is_finite($distance)) {
                    continue;
                }
                $feature['properties'] = array_intersect_key($feature['properties'], array_flip($layer['query_fields']));
                $feature['properties'] = (object)$feature['properties'];
                $feature['id'] = $name . '.' . $feature['id'];
                if ($format === 'text/plain') {
                    unset($feature['geometry']);
                }
                // Retain serialized results rather than large PHP coordinate arrays.
                $encoded = json_encode($feature, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $matches[] = ['distance' => $distance, 'id' => $feature['id'], 'encoded' => $encoded];
                $matchBytes += strlen($encoded);
                usort($matches, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance'] ?: strcmp($a['id'], $b['id']));
                if (count($matches) > $count) {
                    $removed = array_pop($matches);
                    $matchBytes -= strlen($removed['encoded']);
                }
                if ($resultBytes + $matchBytes > 8388608) {
                    throw new WmsException('Feature info exceeds 8 MiB. Reduce FEATURE_COUNT or QUERY_LAYERS.', 'NoApplicableCode', 503);
                }
            }
            $resultBytes += $matchBytes;
            foreach ($matches as $match) {
                $features[] = $match['encoded'];
            }
        }
        if ($format === 'application/json') {
            return ['application/json','{"type":"FeatureCollection","features":[' . implode(',', $features) . ']}',0];
        }
        $text = '';
        foreach ($features as $encoded) {
            $feature = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
            $text .= $feature['id'] . "\n";
            foreach ($feature['properties'] as $name => $value) {
                $text .= $name . ': ' . json_encode($value, JSON_UNESCAPED_UNICODE) . "\n";
            } $text .= "\n";
        }

        return ['text/plain',$text ?: "No matching features.\n",0];
    }

    private function requestedStyle(Request $request, string $name, array $layer, string $styleName): array
    {
        $body = $request->get('SLD_BODY', '');
        $url = $request->get('SLD', '');
        if ($body !== '' && $url !== '') {
            throw new WmsException('Use SLD or SLD_BODY, not both.');
        }
        if ($url !== '') {
            $path = $this->config->root . '/config/sld.json';
            $registered = is_file($path) ? Config::json($path) : [];
            if (!isset($registered[$url]) || !is_string($registered[$url])) {
                throw new WmsException('SLD URL is not registered in config/sld.json. Remote fetching is disabled.');
            }
            $body = $this->config->sldFile($registered[$url]);
        }
        if ($body !== '') {
            $style = $this->config->sldStyle($body, $name, $styleName);
            if (!isset($style['_named_style'])) {
                return $style;
            }
            $styleName = $style['_named_style'];
        }
        $id = $styleName !== '' ? $styleName : $layer['styles'][0];
        if (!in_array($id, $layer['styles'], true)) {
            throw new WmsException('Unknown style for layer: ' . $name, 'StyleNotDefined');
        }
        return $this->config->style($id);
    }

    private function dataset(string $id): Dataset
    {
        return new Dataset($this->config->root . '/data/' . $id . '.sqlite');
    }

    private function legend(Request $request, array $service): array
    {
        $name = $request->get('LAYER');
        $layer = $service['layers'][$name] ?? throw new WmsException('Unknown legend layer.', 'LayerNotDefined');
        $style = $this->requestedStyle($request, $name, $layer, $request->get('STYLE', ''));
        return Legend::render($this->config, $request, $layer, $style);
    }
}
