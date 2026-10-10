<?php
// This file uses the integration harness in run.php.
function shp_fixture(string $directory, int $type, array $records, array $deleted = []): string
{
    $body = '';
    foreach ($records as $i => $raw) {
        $body .= pack('N2', $i + 1, strlen($raw) / 2) . $raw;
    }
    $path = $directory . '/test.shp';
    file_put_contents($path, pack('N7', 9994, 0, 0, 0, 0, 0, (100 + strlen($body)) / 2) . pack('V2e8', 1000, $type, 0, 0, 1, 1, 0, 0, 0, 0) . $body);
    $dbf = "\x03\x7e\x0a\x09" . pack('Vvv', count($records), 65, 41) . str_repeat("\0", 20);
    $dbf .= str_pad('NAME', 11, "\0") . 'C' . str_repeat("\0", 4) . chr(40) . str_repeat("\0", 15) . "\r";
    foreach ($records as $i => $_record) {
        $dbf .= (in_array($i, $deleted, true) ? '*' : ' ') . str_pad('Row ' . $i, 40);
    }
    file_put_contents($directory . '/test.dbf', $dbf . "\x1a");
    return $path;
}
$shp_root = sys_get_temp_dir() . '/wms-shp-tests-' . bin2hex(random_bytes(6));
mkdir($shp_root);
try {
    foreach ([1, 11, 21] as $type) {
        $raw = pack('Ve2', $type, 11, 48) . ($type === 1 ? '' : pack('e', 9));
        $path = shp_fixture($shp_root, $type, [$raw]);
        $items = iterator_to_array(PureWms\Sources::features($path, []));
        check($items[0]['geometry']['coordinates'] === [11.0, 48.0], 'SHP point type ' . $type);
    }
    // Multipart Z/M geometries must preserve XY for every part, including part 2.
    foreach ([3, 5, 8, 13, 15, 18, 23, 25, 28] as $type) {
        $base_type = $type % 10;
        $coords = $base_type === 5 ? [[0,0],[1,0],[1,1],[0,0],[2,0],[3,0],[3,1],[2,0]] : [[0,0],[1,1],[2,2],[3,3]];
        $raw = pack('Ve4', $type, 0, 0, 3, 3);
        $raw .= $base_type === 8 ? pack('V', count($coords)) : pack('V4', 2, count($coords), 0, count($coords) / 2);
        foreach ($coords as $coord) {
            $raw .= pack('e2', ...$coord);
        }
        $values = pack('e2', 0, 0) . str_repeat(pack('e', 0), count($coords));
        if ($type >= 10 && $type < 20) {
            $raw .= $values;
        }
        foreach ($type >= 10 ? ['', $values] : [''] as $optional_m) {
            $path = shp_fixture($shp_root, $type, [$raw . $optional_m]);
            $items = iterator_to_array(PureWms\Sources::features($path, []));
            check(PureWms\Geometry::bbox($items[0]['geometry']) === [0.0, 0.0, 3.0, $base_type === 5 ? 1.0 : 3.0], 'Multipart SHP Z/M XY preserved: ' . $type);
        }
    }
    $path = shp_fixture($shp_root, 1, [pack('V', 0), pack('Ve2', 1, 11, 48), pack('Ve2', 1, 12, 49)], [1]);
    $items = iterator_to_array(PureWms\Sources::features($path, []));
    check(count($items) === 1 && $items[0]['properties']['NAME'] === 'Row 2', 'Null SHP/deleted DBF preserve alignment');
    $good = file_get_contents($path);
    foreach ([substr($good, 0, -1), substr_replace($good, pack('N', 99999999), 104, 4), substr_replace($good, pack('V', 999), 28, 4), substr_replace($good, pack('V', 8), 120, 4)] as $invalid) {
        file_put_contents($path, $invalid);
        fails(fn () => iterator_to_array(PureWms\Sources::features($path, [])));
    }
    file_put_contents($path, $good);
    $dbf = file_get_contents($shp_root . '/test.dbf');
    file_put_contents($shp_root . '/test.dbf', substr_replace($dbf, pack('V', 2), 4, 4));
    fails(fn () => iterator_to_array(PureWms\Sources::features($path, [])));
} finally {
    foreach (glob($shp_root . '/*') as $file) {
        unlink($file);
    }
    rmdir($shp_root);
}
// A missing dataset on an unselected layer must not take another layer offline.
$isolated_root = sys_get_temp_dir() . '/wms-isolation-' . bin2hex(random_bytes(6));
foreach (['config/services', 'config/styles', 'data', 'cache', 'resources'] as $directory) {
    mkdir($isolated_root . '/' . $directory, 0700, true);
}
try {
    foreach (['config/server.json', 'config/sources.json', 'config/services/demo.json', 'resources/DejaVuSans.ttf', 'resources/places.sld', 'data/places.sqlite'] as $file) {
        copy(dirname(__DIR__) . '/' . $file, $isolated_root . '/' . $file);
    }
    foreach (glob(dirname(__DIR__) . '/config/styles/*.json') as $file) {
        copy($file, $isolated_root . '/config/styles/' . basename($file));
    }
    $isolated_config = new PureWms\Config($isolated_root);
    $isolated_server = new PureWms\Server($isolated_config);
    $map_request = PureWms\Request::fromQuery(http_build_query(array_replace($base, ['LAYERS' => 'places'])));
    check($isolated_server->handle($map_request)[0] === 'image/png', 'Only selected dataset needed for GetMap');
    $many = new PDO('sqlite:' . $isolated_root . '/data/places.sqlite');
    $many->exec('DELETE FROM features; DELETE FROM spatial');
    $many->beginTransaction();
    $insert_feature = $many->prepare('INSERT INTO features VALUES(?,?,?)');
    $insert_index = $many->prepare('INSERT INTO spatial VALUES(?,?,?,?,?)');
    for ($i = 1; $i <= 6000; ++$i) {
        $x = 11.30 + (($i - 1) % 100) * 0.0008;
        $y = 48.19 + intdiv($i - 1, 100) * 0.0008;
        $insert_feature->execute([$i, json_encode(['type' => 'Point', 'coordinates' => [$x,$y]]), '{"name":"local"}']);
        $insert_index->execute([$i, $x, $x, $y, $y]);
    }
    $many->commit();
    $local_info = array_replace($base, ['REQUEST' => 'GetFeatureInfo', 'LAYERS' => 'places', 'QUERY_LAYERS' => 'places', 'INFO_FORMAT' => 'application/json', 'I' => 182, 'J' => 592]);
    $local_result = $isolated_server->handle(PureWms\Request::fromQuery(http_build_query($local_info)));
    check(count(json_decode($local_result[1], true)['features']) === 1, 'FeatureInfo selects local candidates from a map with 6000 features');
    $many->exec('DELETE FROM features; DELETE FROM spatial');
    $large_properties = json_encode(['name' => str_repeat('x', 63000)]);
    $many->beginTransaction();
    for ($i = 1; $i <= 100; ++$i) {
        $insert_feature->execute([$i, '{"type":"Point","coordinates":[11.3,48.19]}', $large_properties]);
        $insert_index->execute([$i, 11.3, 11.3, 48.19, 48.19]);
    }
    $many->commit();
    $service_file = $isolated_root . '/config/services/demo.json';
    $service_data = json_decode(file_get_contents($service_file), true);
    $service_data['layers']['places_two'] = $service_data['layers']['places'];
    file_put_contents($service_file, json_encode($service_data));
    $large_info = array_replace($local_info, ['LAYERS' => 'places,places_two', 'QUERY_LAYERS' => 'places,places_two', 'FEATURE_COUNT' => 100]);
    try {
        $isolated_server->handle(PureWms\Request::fromQuery(http_build_query($large_info)));
        throw new RuntimeException('Expected FeatureInfo size limit');
    } catch (PureWms\WmsException $error) {
        check($error->status === 503 && str_contains($error->getMessage(), '8 MiB'), 'FeatureInfo aggregate payload is bounded');
    }
    $many->exec('DELETE FROM features; DELETE FROM spatial');
    $nested_value = 'leaf';
    for ($i = 0; $i < 30; ++$i) {
        $nested_value = [$nested_value];
    }
    $insert_feature->execute([1, '{"type":"Point","coordinates":[11.3,48.19]}', json_encode(['name' => $nested_value])]);
    $insert_index->execute([1, 11.3, 11.3, 48.19, 48.19]);
    $nested_info = array_replace($local_info, ['INFO_FORMAT' => 'text/plain']);
    $nested_result = $isolated_server->handle(PureWms\Request::fromQuery(http_build_query($nested_info)));
    check($nested_result[0] === 'text/plain' && str_contains($nested_result[1], 'leaf'), 'Text feature info preserves accepted nested properties');
    $insert_feature = $insert_index = $many = null;
    unlink($isolated_root . '/data/places.sqlite');
    check($isolated_server->handle(PureWms\Request::fromQuery('REQUEST=GetLegendGraphic&VERSION=1.3.0&LAYER=places&FORMAT=image/png'))[0] === 'image/png', 'Legend needs no dataset');
    $isolated_cache = new PureWms\Cache($isolated_config);
    $key = hash('sha256', 'race');
    check($isolated_cache->read($key) === null, 'Missing cache file is a miss');
    $isolated_cache->write($key, $png);
    foreach (glob($isolated_root . '/cache/map-*.bin') as $file) {
        file_put_contents($file, $key . "\nnot a PNG");
    }
    check($isolated_cache->read($key) === null, 'Corrupt cache payload is a miss');
    $slot = hexdec(substr($key, 0, 7)) % $isolated_config->server['cache_slots'];
    $slot_path = $isolated_root . '/cache/map-' . $slot . '.bin';
    unlink($slot_path);
    mkdir($slot_path);
    check($isolated_cache->read($key) === null, 'Non-file cache slot is a miss');
    $previous_log = ini_set('error_log', $isolated_root . '/cache-error.log');
    try {
        $isolated_cache->write($key, $png);
        check(str_contains(file_get_contents($isolated_root . '/cache-error.log'), 'write failed'), 'Failed optional cache write logs without throwing');
    } finally {
        ini_set('error_log', $previous_log);
    }

} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($isolated_root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($isolated_root);
}

$edge_map = ['width' => 512, 'height' => 512, 'background' => '#ffffff', 'crs' => 'EPSG:3857', 'bbox' => [20036508,1000000,20037508.34,1001000]];
$edge_renderer = new PureWms\Renderer($config, $edge_map, new PureWms\Budget($config->server));
$edge_bounds = $edge_renderer->queryBounds(511.5, 511.5, 64);
check($edge_bounds[0] > 179 && $edge_bounds[2] - $edge_bounds[0] < 0.1, 'Mercator east-edge query remains local');
$edge_map['bbox'] = [-20037508.34,1000000,-20036508,1001000];
$edge_renderer = new PureWms\Renderer($config, $edge_map, new PureWms\Budget($config->server));
$edge_bounds = $edge_renderer->queryBounds(0.5, 511.5, 64);
check($edge_bounds[2] < -179 && $edge_bounds[2] - $edge_bounds[0] < 0.1, 'Mercator west-edge query remains local');
