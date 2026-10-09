<?php
// Regression checks against OGC 06-042, using the integration harness in run.php.
$without_service = $base;
unset($without_service['SERVICE']);
check(response($without_service)[1] === response($base)[1], '7.3: SERVICE optional for GetMap');
rejects(fn () => response(['REQUEST' => 'GetCapabilities']));
foreach (['TIME', 'ELEVATION', 'SLD', 'SLD_BODY', 'FILTER', 'CQL_FILTER', 'REMOTE_OWS_URL', 'X-TRACE'] as $extension) {
    check(response(array_replace($base, [$extension => 'invalid:https://localhost/private']))[1] === response($base)[1], '6.8.1: ignored extension ' . $extension);
}
$tiny = response(array_replace($base, ['BBOX' => '1,1,1.0000000000001,2']));
check($tiny[0] === 'image/png', '7.3.3.6: strictly positive tiny BBOX');
$multi = array_replace($info, ['QUERY_LAYERS' => 'land,places', 'FEATURE_COUNT' => 1]);
unset($multi['SERVICE']);
foreach (['1', '0', '-1', 'invalid', '1.5', '+1', ''] as $count_value) {
    $result = response(array_replace($multi, ['FEATURE_COUNT' => $count_value]));
    check(count(json_decode($result[1], true)['features']) === 2, '7.4.3.6: count per layer and invalid default ' . $count_value);
    check($result[0] === 'application/json', '6.10: exact requested MIME');
}
check(count(json_decode(response(array_replace($multi, ['FEATURE_COUNT' => 101]))[1], true)['features']) === 2, 'Positive counts above local result cap accepted');
foreach (['I' => [-1, 1000, 'invalid', null], 'J' => [-1, 700, '1.5', null]] as $axis => $values) {
    foreach ($values as $value) {
        $invalid = array_replace($info, [$axis => $value]);
        try {
            response($invalid);
            throw new RuntimeException('Expected InvalidPoint');
        } catch (PureWms\WmsException $error) {
            check($error->wmsCode === 'InvalidPoint', '7.4.3.7: InvalidPoint');
        }
    }
}
$cap = response(['SERVICE' => 'WMS', 'REQUEST' => 'GetCapabilities']);
$doc = new DOMDocument();
$doc->loadXML($cap[1]);
check($doc->documentElement->hasAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation'), '7.2.4.1: schemaLocation');
$xpath = new DOMXPath($doc);
$xpath->registerNamespace('w', 'http://www.opengis.net/wms');
check($xpath->query('/w:WMS_Capabilities/w:Capability/w:Layer/w:BoundingBox')->length === 1, '7.2.4.6.8: root category extent');
$doc->loadXML(PureWms\Capabilities::exception(new PureWms\WmsException('<invalid>', 'InvalidPoint')));
check($doc->schemaValidate(__DIR__ . '/schemas/exceptions.xsd'), 'Exception validates against uploaded XSD');
check(PureWms\Geometry::distanceTo(['type' => 'MultiPoint', 'coordinates' => [[9, 0], [2, 0]]], [0, 0]) === 2.0, 'Nearest multipoint distance');
check(PureWms\Geometry::distanceTo(['type' => 'Polygon', 'coordinates' => [[[0,0],[10,0],[10,10],[0,10],[0,0]], [[4,4],[6,4],[6,6],[4,6],[4,4]]]], [5,5]) === 1.0, 'Distance respects polygon hole');
foreach (['capabilities' => 'capabilities', 'exceptions' => 'exceptions'] as $example => $schema_name) {
    $doc->load(__DIR__ . '/references/' . $example . '_1_3_0.xml', LIBXML_NONET);
    check($doc->schemaValidate(__DIR__ . '/schemas/' . $schema_name . '.xsd'), 'Uploaded reference example validates: ' . $example);
}
$nearest_root = sys_get_temp_dir() . '/wms-nearest-' . bin2hex(random_bytes(6));
try {
    foreach (['config/services', 'config/styles', 'sources', 'data', 'cache', 'resources'] as $directory) {
        mkdir($nearest_root . '/' . $directory, 0700, true);
    }
    copy(dirname(__DIR__) . '/resources/DejaVuSans.ttf', $nearest_root . '/resources/DejaVuSans.ttf');
    copy(dirname(__DIR__) . '/config/server.json', $nearest_root . '/config/server.json');
    file_put_contents($nearest_root . '/config/sources.json', json_encode(['points' => ['file' => 'points.geojson', 'crs' => 'EPSG:4326']]));
    file_put_contents($nearest_root . '/config/styles/points.json', json_encode(['size' => 12, 'fill' => '#000000']));
    file_put_contents($nearest_root . '/config/services/demo.json', json_encode(['title' => 'Nearest', 'layers' => ['points' => ['title' => 'Points', 'dataset' => 'points', 'styles' => ['points'], 'query_fields' => ['name']]]]));
    $points = [];
    foreach (['far' => [5.45, 4.95], 'near' => [5.05, 4.95]] as $name => $coordinates) {
        $points[] = ['type' => 'Feature', 'properties' => ['name' => $name], 'geometry' => ['type' => 'Point', 'coordinates' => $coordinates]];
    }
    file_put_contents($nearest_root . '/sources/points.geojson', json_encode(['type' => 'FeatureCollection', 'features' => $points]));
    $nearest_config = new PureWms\Config($nearest_root);
    PureWms\Dataset::import($nearest_config, 'points');
    $nearest_server = new PureWms\Server($nearest_config);
    $nearest_request = PureWms\Request::fromQuery('REQUEST=GetFeatureInfo&VERSION=1.3.0&LAYERS=points&STYLES=&CRS=CRS:84&BBOX=0,0,10,10&WIDTH=100&HEIGHT=100&FORMAT=image/png&QUERY_LAYERS=points&INFO_FORMAT=application/json&I=50&J=50');
    $nearest_features = json_decode($nearest_server->handle($nearest_request)[1], true)['features'];
    check(count($nearest_features) === 1 && $nearest_features[0]['properties']['name'] === 'near', '7.4.4: nearest feature wins over storage order');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($nearest_root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($nearest_root);
}
