<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use PureWms\{Config, Dataset, Request, Server};

$project_root = dirname(__DIR__);
$benchmark_root = sys_get_temp_dir() . '/wms-benchmark-' . bin2hex(random_bytes(6));
foreach (['config/services','config/styles','sources','data','cache','resources'] as $directory) {
    mkdir($benchmark_root . '/' . $directory, 0700, true);
}
copy($project_root . '/resources/DejaVuSans.ttf', $benchmark_root . '/resources/DejaVuSans.ttf');
copy($project_root . '/config/server.json', $benchmark_root . '/config/server.json');
file_put_contents($benchmark_root . '/config/sources.json', json_encode(['points' => ['file' => 'points.geojson','crs' => 'EPSG:4326']]));
file_put_contents($benchmark_root . '/config/styles/points.json', json_encode(['fill' => '#27826c','size' => 6,'stroke_width' => 0]));
file_put_contents($benchmark_root . '/config/services/demo.json', json_encode(['title' => 'Benchmark','layers' => ['points' => ['title' => 'Points','dataset' => 'points','styles' => ['points'],'query_fields' => []]]]));
$features = [];
for ($i = 0; $i < 3000; ++$i) {
    $features[] = ['type' => 'Feature','properties' => [],'geometry' => ['type' => 'Point','coordinates' => [11.28 + ($i % 75) * 0.0014,48.18 + intdiv($i, 75) * 0.0015]]];
}
file_put_contents($benchmark_root . '/sources/points.geojson', json_encode(['type' => 'FeatureCollection','features' => $features]));
unset($features);
try {
    $config = new Config($benchmark_root);
    $start = microtime(true);
    Dataset::import($config, 'points');
    $import_seconds = microtime(true) - $start;
    $request = Request::fromQuery('SERVICE=WMS&REQUEST=GetMap&VERSION=1.3.0&LAYERS=points&STYLES=&CRS=CRS:84&BBOX=11.28,48.18,11.39,48.245&WIDTH=1000&HEIGHT=700&FORMAT=image/png');
    $server = new Server($config);
    $start = microtime(true);
    $cold = $server->handle($request);
    $cold_seconds = microtime(true) - $start;
    $start = microtime(true);
    $warm = $server->handle($request);
    $warm_seconds = microtime(true) - $start;
    if ($cold[1] !== $warm[1]) {
        throw new RuntimeException('Cache output differs.');
    }
    echo json_encode(['php' => PHP_VERSION,'features' => 3000,'size' => '1000x700','import_seconds' => $import_seconds,'cold_seconds' => $cold_seconds,'cached_seconds' => $warm_seconds,'peak_memory_bytes' => memory_get_peak_usage(true),'png_bytes' => strlen($cold[1])], JSON_PRETTY_PRINT),PHP_EOL;
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($benchmark_root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($benchmark_root);
}
