<?php

declare(strict_types=1);

// CRS reference values, import normalization and WMS request axis order.
$registry = $config->crs;
$projection = new PureWms\Projection($registry);
$center = $projection->point([10,52], 'EPSG:4326', 'EPSG:3035');
check(abs($center[0] - 4321000) < 0.01 && abs($center[1] - 3210000) < 0.01, 'ETRS89 LAEA natural origin reference');
$back = $projection->point($center, 'EPSG:3035', 'EPSG:4326');
check(abs($back[0] - 10) < 1e-7 && abs($back[1] - 52) < 1e-7, 'LAEA inverse');
foreach ($registry->codes() as $code) {
    $projected = $projection->point([11.333,48.223], 'EPSG:4326', $code);
    $back = $projection->point($projected, $code, 'EPSG:4326');
    check(abs($back[0] - 11.333) < 1e-6 && abs($back[1] - 48.223) < 1e-6, 'Roundtrip ' . $code);
}
rejects(fn () => $projection->point([10,52], 'EPSG:4326', 'EPSG:999999'));
fails(fn () => new PureWms\CrsRegistry(['EPSG:3035' => ['proj4' => '+init=epsg:3035']]));
fails(fn () => new PureWms\CrsRegistry(['EPSG:3035' => ['proj4' => '+proj=laea +nadgrids=remote']]));
$point_xy = $projection->point([11.333,48.223], 'EPSG:4326', 'EPSG:3035');
$box_xy = [$point_xy[0]-1000,$point_xy[1]-1000,$point_xy[0]+1000,$point_xy[1]+1000];
$params = array_replace($base, ['LAYERS'=>'places','STYLES'=>'places_sld','WIDTH'=>200,'HEIGHT'=>200,'CRS'=>'EPSG:3035','BBOX'=>implode(',', $registry->wmsBounds($box_xy, 'EPSG:3035'))]);
$parsed = PureWms\Request::fromQuery(http_build_query($params))->map(2048,2097152,$registry);
check(abs($parsed['bbox'][0]-$box_xy[0]) < 0.001, 'EPSG3035 WMS northing/easting axis order');
$img = imagecreatefromstring(response($params)[1]);
$rgba = imagecolorsforindex($img, imagecolorat($img,100,100));
check($rgba['red'] === 231 && $rgba['green'] === 119, 'Reprojected map places SLD point at expected pixel');
$info = json_decode(response(array_replace($params, ['REQUEST'=>'GetFeatureInfo','QUERY_LAYERS'=>'places','INFO_FORMAT'=>'application/json','I'=>100,'J'=>100]))[1], true);
check(count($info['features']) === 1 && $info['features'][0]['properties']['name'] === 'Olching', 'Feature info in reprojected CRS');
check(abs($info['features'][0]['geometry']['coordinates'][0]-11.333) < 1e-8, 'Feature-info GeoJSON remains longitude/latitude');
$caps = response(['SERVICE'=>'WMS','REQUEST'=>'GetCapabilities'])[1];
check(str_contains($caps, '<CRS>EPSG:3035</CRS>') && str_contains($caps, '<Name>places_sld</Name>'), 'Capabilities advertise configured CRS and SLD style');

// A projected input is normalized on import, independently of output CRS.
$temp_crs = sys_get_temp_dir() . '/wms-crs-' . bin2hex(random_bytes(6));
foreach (['config','sources','data'] as $directory) { mkdir($temp_crs . '/' . $directory,0700,true); }
try {
    copy($config->root . '/config/server.json', $temp_crs . '/config/server.json');
    copy($config->root . '/config/crs.json', $temp_crs . '/config/crs.json');
    file_put_contents($temp_crs . '/config/sources.json', json_encode(['laea'=>['file'=>'laea.geojson','crs'=>'EPSG:3035']]));
    file_put_contents($temp_crs . '/sources/laea.geojson', json_encode(['type'=>'FeatureCollection','features'=>[['type'=>'Feature','geometry'=>['type'=>'Point','coordinates'=>$center],'properties'=>[]]]]));
    $import_config = new PureWms\Config($temp_crs);
    PureWms\Dataset::import($import_config, 'laea');
    $imported = new PureWms\Dataset($temp_crs . '/data/laea.sqlite');
    $rows = iterator_to_array($imported->query([9.99,51.99,10.01,52.01],10));
    check(count($rows) === 1, 'Configured projected source imported and spatially indexed');
    $imported = null;
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_crs, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($temp_crs);
}

$xml = file_get_contents($config->root . '/resources/places.sld');
$style = $config->sldStyle($xml, 'places');
$renderer = new PureWms\Renderer($config, $parsed, new PureWms\Budget($config->server));
$station_styles = $renderer->symbolizers($style, ['kind'=>'station']);
$town_styles = $renderer->symbolizers($style, ['kind'=>'town']);
check($station_styles[0]['symbol'] === 'square' && count($station_styles) === 2, 'SLD filter and separate label group');
check($town_styles[0]['symbol'] === 'circle', 'SLD ElseFilter');
$inline = array_replace($params, ['STYLES'=>'','SLD_BODY'=>$xml]);
check(response($inline)[1] === response($params)[1], 'Inline SLD and configured SLD produce identical maps');
check(response(array_replace($params, ['STYLES'=>'','SLD'=>'https://example.org/styles/places.sld']))[1] === response($params)[1], 'Registered SLD URL resolves locally');
$legend = response(['SERVICE'=>'WMS','VERSION'=>'1.3.0','REQUEST'=>'GetLegendGraphic','LAYER'=>'places','STYLE'=>'places_sld','FORMAT'=>'image/png'])[1];
check(getimagesizefromstring($legend)[1] === 120, 'SLD legend row for each rule');
rejects(fn () => response(array_replace($params, ['SLD'=>'http://localhost/private'])));
rejects(fn () => response(array_replace($inline, ['SLD'=>'https://example.org/styles/places.sld'])));
rejects(fn () => $config->sldStyle('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]>' . $xml,'places'));
rejects(fn () => $config->sldStyle(str_repeat(' ',131073),'places'));
rejects(fn () => $config->sldStyle(str_replace('stroke-width','stroke-dasharray',$xml),'places'));
rejects(fn () => $config->sldStyle($xml,'not_published'));
rejects(fn () => $config->sldStyle($xml,'places','missing_style'));

$prefix = '<StyledLayerDescriptor xmlns="http://www.opengis.net/sld" xmlns:ogc="http://www.opengis.net/ogc" version="1.0.0"><NamedLayer><Name>places</Name><UserStyle><FeatureTypeStyle>';
$suffix = '</FeatureTypeStyle></UserStyle></NamedLayer></StyledLayerDescriptor>';
$point_symbol = '<PointSymbolizer><Graphic><Mark><Fill><CssParameter name="fill">#ff0000</CssParameter></Fill></Mark><Size>20</Size></Graphic></PointSymbolizer>';
$filter = '<ogc:Filter><ogc:PropertyIsEqualTo><ogc:PropertyName>kind</ogc:PropertyName><ogc:Literal>station</ogc:Literal></ogc:PropertyIsEqualTo></ogc:Filter>';
$filtered = $prefix . '<Rule>' . $filter . $point_symbol . '</Rule>' . $suffix;
$filtered_info = array_replace($params,['STYLES'=>'','SLD_BODY'=>$filtered,'REQUEST'=>'GetFeatureInfo','QUERY_LAYERS'=>'places','INFO_FORMAT'=>'application/json','I'=>100,'J'=>100]);
check(json_decode(response($filtered_info)[1],true)['features'] === [], 'SLD-hidden feature is not returned by GetFeatureInfo');
$twice = $config->sldStyle($prefix . '<Rule>'.$point_symbol.'</Rule><Rule>'.$point_symbol.'</Rule>'.$suffix,'places');
check(count($renderer->symbolizers($twice,[])) === 2, 'All matching SLD rules apply');
$scaled = $config->sldStyle($prefix . '<Rule><MaxScaleDenominator>1</MaxScaleDenominator>'.$point_symbol.'</Rule>'.$suffix,'places');
check($renderer->symbolizers($scaled,[]) === [], 'SLD scale threshold');
$polygon_xml = $prefix . '<Rule><PolygonSymbolizer><Fill><CssParameter name="fill">#ff0000</CssParameter></Fill></PolygonSymbolizer></Rule>' . $suffix;
$polygon_style = $renderer->symbolizers($config->sldStyle($polygon_xml,'places'),[])[0];
$renderer->draw(['type'=>'Polygon','coordinates'=>[[[10,10],[80,10],[80,80],[10,80],[10,10]],[[30,30],[60,30],[60,60],[30,60],[30,30]]]],$polygon_style);
check($renderer->canvas->pixel(20,20) === [255,0,0,255] && $renderer->canvas->pixel(40,40)[3] === 0,'SLD polygon preserves holes');
check(!$renderer->applies(['type'=>'Point'], $polygon_style), 'Polygon symbolizer does not render point features');
$line_xml = $prefix . '<Rule><LineSymbolizer><Stroke><CssParameter name="stroke">#0000ff</CssParameter><CssParameter name="stroke-width">3</CssParameter></Stroke></LineSymbolizer></Rule>' . $suffix;
$line_style = $renderer->symbolizers($config->sldStyle($line_xml,'places'),[])[0];
$renderer->draw(['type'=>'LineString','coordinates'=>[[10,150],[190,150]]],$line_style);
check($renderer->canvas->pixel(100,150) === [0,0,255,255], 'SLD line symbolizer');
$external = $prefix . '<Rule><PointSymbolizer><Graphic><ExternalGraphic><OnlineResource xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="https://localhost/secret.png"/><Format>image/png</Format></ExternalGraphic></Graphic></PointSymbolizer></Rule>' . $suffix;
rejects(fn () => $config->sldStyle($external,'places'));
$se_xml = str_replace(['version="1.0.0"','<PointSymbolizer>','</PointSymbolizer>','CssParameter'], ['version="1.1.0"','<PointSymbolizer xmlns="http://www.opengis.net/se">','</PointSymbolizer>','SvgParameter'], $prefix . '<Rule>'.$point_symbol.'</Rule>'.$suffix);
check(count($config->sldStyle($se_xml,'places')['_sld_groups']) === 1, 'SE 1.1 symbolizer and SvgParameter');
$outline_xml = str_replace('<Name>places</Name>', '<Name>land</Name>', $line_xml);
$outline_info = array_replace($base, ['LAYERS'=>'land','STYLES'=>'','SLD_BODY'=>$outline_xml,'REQUEST'=>'GetFeatureInfo','QUERY_LAYERS'=>'land','INFO_FORMAT'=>'application/json','I'=>227,'J'=>538]);
check(json_decode(response($outline_info)[1],true)['features'] === [], 'Outline-only polygon excludes interior feature-info hit');
check($renderer->hitDistance(['type'=>'Polygon','coordinates'=>[[[0,0],[100,0],[100,100],[0,100],[0,0]]]],$line_style,[50,50]) === 50.0, 'Outline-only polygon hit distance uses rings');
check($renderer->hitDistance(['type'=>'Point','coordinates'=>[10,10]],['fill'=>'#ffffff00','stroke_width'=>0],[10,10]) === INF, 'Invisible point has no hit');
