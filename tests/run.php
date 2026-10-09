<?php

declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use PureWms\Canvas;
use PureWms\Projection;
use PureWms\Request;
use PureWms\WmsException;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $action): void
{
    try {
        $action();
    } catch (WmsException $error) {
        check(true, 'Rejected');
        return;
    }
    throw new RuntimeException('Expected WMS exception');
}
$request = Request::fromQuery('service=WMS&request=GetMap&version=1.3.0&layers=demo&styles=&crs=EPSG:4326&bbox=47,10,49,12&width=256&height=256&format=image/png');
check($request->map()['bbox'] === [10.0,47.0,12.0,49.0], 'WMS 1.3 latitude/longitude order');
rejects(fn () => Request::fromQuery('REQUEST=GetMap&request=GetCapabilities'));
rejects(fn () => Request::fromQuery('LAYERS[]=x'));
rejects(fn () => Request::fromQuery('REQUEST=GetMap')->map());
$projection = new Projection();
$point = $projection->point([11.0,48.0], 'EPSG:4326', 'EPSG:3857');
check(abs($point[0] - 1224514.3987) < 0.1 && abs($point[1] - 6106854.8349) < 0.1, 'Mercator reference');
$canvas = new Canvas(20, 20, '#00000000');
$canvas->polygon([[[1,1],[19,1],[19,19],[1,19],[1,1]], [[7,7],[13,7],[13,13],[7,13],[7,7]]], '#ff0000ff');
check($canvas->pixel(3, 3) === [255,0,0,255], 'Polygon filled');
check($canvas->pixel(10, 10) === [0,0,0,0], 'Polygon hole remains transparent');
check(substr($canvas->png(), 0, 8) === "\x89PNG\r\n\x1a\n", 'PNG signature');
echo "Passed $checks checks\n";

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Protocol and rendering integration tests use the shipped synthetic datasets.
$config = new PureWms\Config(dirname(__DIR__));
$server = new PureWms\Server($config);
$base = ['SERVICE' => 'WMS','REQUEST' => 'GetMap','VERSION' => '1.3.0','MAP' => 'demo','LAYERS' => 'land,routes,places','STYLES' => '','CRS' => 'CRS:84','BBOX' => '11.28,48.18,11.39,48.245','WIDTH' => 1000,'HEIGHT' => 700,'FORMAT' => 'image/png','TRANSPARENT' => 'TRUE'];
function response(array $parameters): array
{
    global $server;
    return $server->handle(Request::fromQuery(http_build_query($parameters, '', '&', PHP_QUERY_RFC3986)));
}
function fails(callable $action): void
{
    try {
        $action();
    } catch (Throwable $error) {
        check(true, 'Failed as expected');
        return;
    }
    throw new RuntimeException('Expected failure');
}
[$mime,$png] = response($base);
check($mime === 'image/png', 'Map MIME');
$image = imagecreatefromstring($png);
check(imagesx($image) === 1000 && imagesy($image) === 700, 'Map dimensions');
check(imagecolorsforindex($image, imagecolorat($image, 0, 0))['alpha'] === 127, 'Transparent background');
$ordered = $base;
$ordered['CRS'] = 'EPSG:4326';
$ordered['BBOX'] = '48.18,11.28,48.245,11.39';
check(response($ordered)[1] === $png, 'CRS:84 and EPSG:4326 render identically with swapped axis order');
$opaque = $base;
$opaque['TRANSPARENT'] = 'FALSE';
$opaque['BGCOLOR'] = '0x112233';
$im = imagecreatefromstring(response($opaque)[1]);
$color = imagecolorsforindex($im, imagecolorat($im, 0, 0));
check($color['red'] === 17 && $color['green'] === 34 && $color['blue'] === 51 && $color['alpha'] === 0, 'Opaque background color');
foreach ([['WIDTH' => 0],['WIDTH' => 9999999],['BBOX' => '0,0,NaN,1'],['BBOX' => '1,1,0,0'],['CRS' => 'EPSG:9999'],['FORMAT' => 'image/jpeg'],['STYLES' => 'unknown'],['LAYERS' => 'missing'],['VERSION' => '1.1.1'],['MAP' => '../private'],['TRANSPARENT' => 'maybe'],['BGCOLOR' => 'red']] as $change) {
    rejects(fn () => response(array_replace($base, $change)));
}
$info = array_replace($base, ['REQUEST' => 'GetFeatureInfo','QUERY_LAYERS' => 'places','INFO_FORMAT' => 'application/json','I' => 482,'J' => 237,'FEATURE_COUNT' => 5]);
$features = json_decode(response($info)[1], true)['features'];
check(count($features) === 1 && $features[0]['properties']['name'] === 'Olching', 'GetFeatureInfo finds point');
check(!isset($features[0]['properties']['internal_note']), 'GetFeatureInfo field allowlist');
check($features[0]['geometry']['coordinates'] === [11.333,48.223], 'GeoJSON output uses WGS84 longitude/latitude');
rejects(fn () => response(array_replace($info, ['I' => 1000])));
rejects(fn () => response(array_replace($info, ['QUERY_LAYERS' => 'not_rendered'])));
$hole = array_replace($info, ['QUERY_LAYERS' => 'land','I' => 460,'J' => 367]);
check(count(json_decode(response($hole)[1], true)['features']) === 0, 'FeatureInfo ignores polygon hole interior');
$xml = response(['SERVICE' => 'WMS','REQUEST' => 'GetCapabilities','VERSION' => '1.1.1'])[1];
$document = new DOMDocument();
check($document->loadXML($xml), 'Capabilities XML parses');
check($document->documentElement->getAttribute('version') === '1.3.0', 'Capabilities version negotiation');
$schema = __DIR__.'/schemas/capabilities.xsd';
if (is_file($schema)) {
    check($document->schemaValidate($schema), 'Capabilities validates against official OGC XSD');
}
$legend = response(['SERVICE' => 'WMS','REQUEST' => 'GetLegendGraphic','VERSION' => '1.3.0','LAYER' => 'places','FORMAT' => 'image/png'])[1];
check(imagesy(imagecreatefromstring($legend)) === 80, 'Classified style legend');

$renderer = new PureWms\Renderer($config, Request::fromQuery(http_build_query($base))->map(), new PureWms\Budget($config->server));
$rule_style = ['fill' => '#112233','rules' => [['field' => 'kind','equals' => 'park','symbolizer' => ['fill' => '#ffffff']]]];
check($renderer->symbolizer($rule_style, ['kind' => ['park']])['fill'] === '#112233', 'Array-valued property does not match scalar rule');

// Verify common source formats with independent binary fixtures.
$points = iterator_to_array(PureWms\Sources::features(__DIR__.'/fixtures/points.shp', ['encoding' => 'Windows-1252']));
check(count($points) === 2 && $points[0]['properties']['NAME'] === 'Gröbenzell', 'Shapefile plus DBF encoding');
check($points[0]['geometry']['coordinates'] === [11.333,48.223], 'Shapefile coordinate order');
$polygons = iterator_to_array(PureWms\Sources::features(__DIR__.'/fixtures/polygons.shp', ['encoding' => 'Windows-1252']));
$geometry = $polygons[0]['geometry'];
check($geometry['type'] === 'MultiPolygon' && count($geometry['coordinates']) === 2 && count($geometry['coordinates'][0]) === 2, 'Shapefile ring nesting separates islands and holes');
$lines = iterator_to_array(PureWms\Sources::features(__DIR__.'/fixtures/lines.shp', ['encoding' => 'Windows-1252']));
check($lines[0]['geometry']['type'] === 'MultiLineString', 'Shapefile line geometry');
$gpkg = iterator_to_array(PureWms\Sources::features(__DIR__.'/fixtures/points.gpkg', ['table' => 'places','crs' => 'EPSG:4326']));
check(count($gpkg) === 1 && $gpkg[0]['properties']['name'] === 'Olching', 'GeoPackage table and geometry');
foreach ([1001 => 3,2001 => 3,3001 => 4] as $type => $dimensions) {
    $blob = "GP\0\1".pack('V', 4326).pack('CV', 1, $type).pack('e*', ...array_slice([11,48,123,456], 0, $dimensions));
    check(PureWms\Wkb::geopackage($blob, 4326)['coordinates'] === [11.0,48.0], 'GeoPackage Z/M coordinate dimensions');
}
fails(fn () => PureWms\Wkb::geopackage('GP', 4326));
fails(fn () => PureWms\Wkb::geopackage("GP\0\1".pack('V', 3857).pack('CVee', 1, 1, 1, 1), 4326));
$canvas = new Canvas(20, 20, '#0000ffff');
$canvas->polygon([[[1,1],[19,1],[19,19],[1,19]],[[7,7],[13,7],[13,13],[7,13]]], '#ff0000ff');
check($canvas->pixel(10, 10) === [0,0,255,255], 'Polygon hole preserves underlying layer');
$canvas->line([[-1e12,4],[1e12,4]], '#00ff00', 2);
check($canvas->pixel(10, 4) === [0,255,0,255], 'Huge offscreen line clipped safely');
$utm = $projection->point([11.0,48.0], 'EPSG:4326', 'EPSG:25832');
check(abs($utm[0] - 649187.87496) < 1 && abs($utm[1] - 5318235.613) < 1, 'UTM32 independent reference');
$back = $projection->point($utm, 'EPSG:25832', 'EPSG:4326');
check(abs($back[0] - 11) < 1e-7 && abs($back[1] - 48) < 1e-7, 'UTM roundtrip');

// Bounded worker slots, file path confinement, and bounded cache.
$cache = new PureWms\Cache($config);
$locks = [];
try {
    for ($i = 0;$i < $config->server['max_parallel'];++$i) {
        $locks[] = $cache->acquire();
    } rejects(fn () => $cache->acquire());
} finally {
    foreach ($locks as $lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
fails(fn () => $config->sourcePath('../composer.json'));
fails(fn () => $config->resource('../composer.json', 'ttf'));
rejects(fn () => (new PureWms\Budget($config->server))->check(0, 0, 20000001));
$cache->write(hash('sha256', 'test-key'), $png);
check($cache->read(hash('sha256', 'test-key')) === $png, 'Cache roundtrip');
check(count(glob($config->root.'/cache/map-*.bin')) <= $config->server['cache_slots'], 'Cache uses bounded slots');

// Verify failed imports preserve the published database.
$temp = sys_get_temp_dir().'/wms-test-'.bin2hex(random_bytes(6));
foreach (['config','sources','data','cache'] as $directory) {
    mkdir($temp.'/'.$directory, 0700, true);
}
copy($config->root.'/config/server.json', $temp.'/config/server.json');
file_put_contents($temp.'/config/sources.json', json_encode(['test' => ['file' => 'test.geojson','crs' => 'EPSG:4326']]));
copy($config->root.'/sources/places.geojson', $temp.'/sources/test.geojson');
try {
    $test_config = new PureWms\Config($temp);
    $metadata = PureWms\Dataset::import($test_config, 'test');
    $hash = hash_file('sha256', $temp.'/data/test.sqlite');
    file_put_contents($temp.'/sources/test.geojson', json_encode(['type' => 'FeatureCollection','features' => [['type' => 'Feature','properties' => [],'geometry' => ['type' => 'LineString','coordinates' => array_fill(0, 20001, [11.3,48.2])]]]]));
    fails(fn () => PureWms\Dataset::import($test_config, 'test'));
    check(hash_file('sha256', $temp.'/data/test.sqlite') === $hash, 'Oversized geometry does not replace published dataset');
    file_put_contents($temp.'/sources/test.geojson', '{"type":"FeatureCollection","features":[');
    fails(fn () => PureWms\Dataset::import($test_config, 'test'));
    check(hash_file('sha256', $temp.'/data/test.sqlite') === $hash, 'Failed import preserves previous dataset');
    $dataset = new PureWms\Dataset($temp.'/data/test.sqlite');
    check(count(iterator_to_array($dataset->query([11.332,48.222,11.334,48.224], 10))) === 1, 'RTree selects local features');
    rejects(fn () => iterator_to_array($dataset->query([11,48,12,49], 1)));
    $dataset = null;
    $unsafe = new PDO('sqlite:'.$temp.'/data/test.sqlite');
    $unsafe->prepare('UPDATE features SET geometry=? WHERE id=1')->execute([str_repeat(' ', 1048577)]);
    $unsafe = null;
    $dataset = new PureWms\Dataset($temp.'/data/test.sqlite');
    rejects(fn () => iterator_to_array($dataset->query([11.332,48.222,11.334,48.224], 10)));
    $dataset = null;
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($temp);
}
require __DIR__ . "/ogc_regression.php";
echo "Passed $checks total checks\n";
