<?php

declare(strict_types=1);

$get_styles = ['SERVICE'=>'WMS','VERSION'=>'1.3.0','REQUEST'=>'GetStyles','LAYERS'=>'land,routes,places'];
[$mime, $exported] = response($get_styles);
check($mime === 'application/vnd.ogc.sld+xml', 'GetStyles returns SLD MIME');
$document = new DOMDocument();
check($document->loadXML($exported, LIBXML_NONET), 'Style export is well-formed XML');
check($document->getElementsByTagName('UserStyle')->length === 4, 'GetStyles includes all published styles');
foreach (['land','routes','places'] as $name) {
    $compiled = $config->sldStyle($exported, $name, $name);
    check(count($compiled['_sld_groups']) === 1, 'Exported native style accepted: ' . $name);
}
check(count($config->sldStyle($exported,'land','land')['_sld_groups'][0][0]['symbols']) === 4, 'Legend geometry does not restrict exported feature types');
$exported_sld = $config->sldStyle($exported,'places','places_sld');
check(count($exported_sld['_sld_groups']) === 2, 'Export preserves SLD paint groups');
check(PureWms\StyleRules::matching($config->sldStyle($exported,'places','places'),['kind'=>'station'],1000)[0]['title'] === 'station', 'Export preserves first-match category');
rejects(fn () => response(array_replace($get_styles,['LAYERS'=>'missing'])));
rejects(fn () => response(array_replace($get_styles,['SLD_VERSION'=>'1.1.0'])));
$legend_parameters = ['SERVICE'=>'WMS','VERSION'=>'1.3.0','REQUEST'=>'GetLegendGraphic','LAYER'=>'places','STYLE'=>'places_sld','FORMAT'=>'image/png'];
check(getimagesizefromstring(response(array_replace($legend_parameters,['SCALE'=>1000000]))[1])[1] === 80, 'Legend excludes labels outside scale');
check(getimagesizefromstring(response(array_replace($legend_parameters,['RULE'=>'stations']))[1])[1] === 40, 'Legend selects named rule');
check(getimagesizefromstring(response(array_replace($legend_parameters,['WIDTH'=>400,'HEIGHT'=>50]))[1])[1] === 150, 'Legend output dimensions');
$context_legend = array_replace($legend_parameters,['CRS'=>'CRS:84','BBOX'=>'11.332,48.222,11.334,48.224','SRCWIDTH'=>256,'SRCHEIGHT'=>256]);
check(getimagesizefromstring(response($context_legend)[1])[1] === 80, 'Town-only context includes ElseFilter and label, not station');
check(getimagesizefromstring(response(array_replace($context_legend,['BBOX'=>'11.3331,48.221,11.3381,48.225']))[1])[1] >= 40,'Context includes point footprint entering viewport');
$edge_image = imagecreatefromstring(response(array_replace($context_legend,['BBOX'=>'11.3331,48.221,11.3381,48.225','STYLE'=>'places']))[1]);
check(imagecolorsforindex($edge_image,imagecolorat($edge_image,23,20))['alpha'] !== 127,'Visible edge marker produces legend entry');
$empty_png = response(array_replace($context_legend,['RULE'=>'stations']))[1];
$empty_image = imagecreatefromstring($empty_png);
check(imagecolorsforindex($empty_image,imagecolorat($empty_image,23,20))['alpha'] === 127, 'Absent legend rule yields transparent result');
rejects(fn () => response(array_replace($legend_parameters,['RULE'=>'does_not_exist'])));
rejects(fn () => response(array_replace($legend_parameters,['SCALE'=>'nan'])));
rejects(fn () => response(array_replace($context_legend,['CRS'=>'EPSG:999999'])));
$polygon_with_hole = ['type'=>'Polygon','coordinates'=>[[[0,0],[100,0],[100,100],[0,100],[0,0]],[[30,30],[70,30],[70,70],[30,70],[30,30]]]];
check(!PureWms\Geometry::intersectsBox($polygon_with_hole,[40,40,60,60]), 'Context inside polygon hole has no intersection');
check(PureWms\Geometry::intersectsBox($polygon_with_hole,[10,10,20,20]), 'Context inside filled polygon intersects');
check(!PureWms\Geometry::intersectsBox(['type'=>'LineString','coordinates'=>[[0,0],[100,100]]],[0,90,10,100]), 'RTree envelope false positive rejected');
$dash_style = $config->sldStyle(str_replace('stroke-width','stroke-dasharray',$line_xml),'places');
$dash_symbol = $dash_style['_sld_groups'][0][0]['symbols'][0];
$dash_canvas = new PureWms\Canvas(60,20,'#ffffff00');
$dash_canvas->line([[0,10],[59,10]],'#ff0000',1,false,[4,4]);
check($dash_canvas->pixel(2,10)[3] === 255 && $dash_canvas->pixel(6,10)[3] === 0, 'Dash rendering alternates paint and gaps');
$like_xml = $prefix . '<Rule><ogc:Filter><ogc:PropertyIsLike wildCard="*" singleChar="?" escapeChar="!"><ogc:PropertyName>name</ogc:PropertyName><ogc:Literal>Olch*</ogc:Literal></ogc:PropertyIsLike></ogc:Filter>'.$point_symbol.'</Rule>'.$suffix;
$like_style = $config->sldStyle($like_xml,'places');
check(count(PureWms\StyleRules::matching($like_style,['name'=>'Olching'],1000)) === 1 && PureWms\StyleRules::matching($like_style,['name'=>'Munich'],1000) === [], 'SLD PropertyIsLike');
$placement_xml = str_replace('<Halo>', '<LabelPlacement><PointPlacement><AnchorPoint><AnchorPointX>0.5</AnchorPointX><AnchorPointY>0.5</AnchorPointY></AnchorPoint><Rotation>30</Rotation></PointPlacement></LabelPlacement><Halo>', $xml);
$placement_style = $config->sldStyle($placement_xml,'places');
check($placement_style['_sld_groups'][1][0]['symbols'][0]['label_angle'] === -30.0, 'SLD clockwise rotation translated for GD');
check(response(array_replace($params,['STYLES'=>'','SLD_BODY'=>$placement_xml]))[0] === 'image/png','Rotated SLD labels render');

$temp_ext = sys_get_temp_dir() . '/wms-ext-' . bin2hex(random_bytes(6));
foreach (['config/services','config/styles','data','cache','resources'] as $directory) { mkdir($temp_ext.'/'.$directory,0700,true); }
try {
    foreach (['config/server.json','config/sources.json','config/services/demo.json','resources/DejaVuSans.ttf','resources/places.sld','data/places.sqlite'] as $file) { copy($config->root.'/'.$file,$temp_ext.'/'.$file); }
    foreach (glob($config->root.'/config/styles/*.json') as $file) { copy($file,$temp_ext.'/config/styles/'.basename($file)); }
    $settings = json_decode(file_get_contents($temp_ext.'/config/server.json'),true);
    $settings['cache_max_bytes'] = 1048576;
    file_put_contents($temp_ext.'/config/server.json',json_encode($settings));
    $temporary_config = new PureWms\Config($temp_ext);
    $temporary_cache = new PureWms\Cache($temporary_config);
    $payload = "\x89PNG\r\n\x1a\n" . str_repeat('x',600000);
    $key_a = hash('sha256','quota-a'); $key_b = hash('sha256','quota-b');
    $temporary_cache->write($key_a,$payload); $temporary_cache->write($key_b,$payload);
    check($temporary_cache->read($key_a) === null && $temporary_cache->read($key_b) === $payload, 'Byte quota evicts old cache entry');
    check(array_sum(array_map('filesize',glob($temp_ext.'/cache/map-*.bin'))) <= 1048576,'Cache stays inside byte quota');
    $hot = hash('sha256','hot'); $cold = hash('sha256','cold'); $fresh = hash('sha256','fresh');
    foreach (glob($temp_ext.'/cache/map-*.bin') as $file) { unlink($file); }
    $small_payload = "\x89PNG\r\n\x1a\n" . str_repeat('x',400000);
    $temporary_cache->write($hot,$small_payload); $temporary_cache->write($cold,$small_payload);
    touch($temp_ext.'/cache/map-'.$hot.'.bin',time()-60,time()-60);
    touch($temp_ext.'/cache/map-'.$cold.'.bin',time()-30,time()-30);
    $temporary_cache->read($hot);
    $temporary_cache->write($fresh,$small_payload);
    check($temporary_cache->read($hot) !== null && $temporary_cache->read($cold) === null, 'Cache eviction preserves recently accessed entry');
    $collision_one = $temporary_cache->acquireKey(str_repeat('0',64));
    $collision_two = $temporary_cache->acquireKey('0040'.str_repeat('0',60));
    fclose($collision_one); fclose($collision_two);
    check(true,'Distinct keys sharing hash prefix do not block each other');
    $request_lock = $temporary_cache->acquireKey($key_a);
    try { rejects(fn () => $temporary_cache->acquireKey($key_a)); }
    finally { flock($request_lock,LOCK_UN); fclose($request_lock); }
    $request_lock = $temporary_cache->acquireKey($key_a); fclose($request_lock);
    check(true,'Request lock reusable after release');
    $hidden_style = json_decode(file_get_contents($temp_ext.'/config/styles/places.json'),true);
    $hidden_style['max_scale'] = 1;
    file_put_contents($temp_ext.'/config/styles/places.json',json_encode($hidden_style));
    rename($temp_ext.'/data/places.sqlite',$temp_ext.'/data/overview.sqlite');
    $temporary_server = new PureWms\Server(new PureWms\Config($temp_ext));
    $hidden_request = PureWms\Request::fromQuery(http_build_query(array_replace($base,['LAYERS'=>'places'])));
    check($temporary_server->handle($hidden_request)[0] === 'image/png','Scale-hidden layer does not open missing dataset');
    $hidden_info = PureWms\Request::fromQuery(http_build_query(array_replace($base,['LAYERS'=>'places','REQUEST'=>'GetFeatureInfo','QUERY_LAYERS'=>'places','I'=>1,'J'=>1,'INFO_FORMAT'=>'application/json'])));
    check(json_decode($temporary_server->handle($hidden_info)[1],true)['features'] === [],'Scale-hidden layer has no feature-info query');
    unset($hidden_style['max_scale']);
    file_put_contents($temp_ext.'/config/styles/places.json',json_encode($hidden_style));
    $source_definitions = json_decode(file_get_contents($temp_ext.'/config/sources.json'),true);
    $source_definitions['overview'] = $source_definitions['places'];
    file_put_contents($temp_ext.'/config/sources.json',json_encode($source_definitions));
    $service_definition = json_decode(file_get_contents($temp_ext.'/config/services/demo.json'),true);
    $service_definition['layers']['places']['overviews'] = [['min_scale'=>1,'dataset'=>'overview']];
    file_put_contents($temp_ext.'/config/services/demo.json',json_encode($service_definition));
    $temporary_config = new PureWms\Config($temp_ext);
    $temporary_server = new PureWms\Server($temporary_config);
    check($temporary_server->handle($hidden_request)[0] === 'image/png','Prepared overview substitutes missing detailed database');
    check(PureWms\LayerPlan::dataset($service_definition['layers']['places'],0) === 'places','Detail source below overview threshold');
    $temporary_config->service('demo');
    $report = PureWms\Health::report($temporary_config,true);
    check(!$report['ok'],'Doctor reports missing published detail datasets');
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp_ext,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($temp_ext);
}
$displaced_xml = $prefix . '<Rule><Name>label</Name><TextSymbolizer><Label><ogc:PropertyName>name</ogc:PropertyName></Label><LabelPlacement><PointPlacement><AnchorPoint><AnchorPointX>0</AnchorPointX><AnchorPointY>0.5</AnchorPointY></AnchorPoint><Displacement><DisplacementX>40</DisplacementX><DisplacementY>0</DisplacementY></Displacement></PointPlacement></LabelPlacement></TextSymbolizer></Rule>' . $suffix;
$displaced_legend = response(array_replace($legend_parameters,['STYLE'=>'','SLD_BODY'=>$displaced_xml,'RULE'=>'label','BBOX'=>'11.33339,48.221,11.33839,48.225','CRS'=>'CRS:84']))[1];
$displaced_image = imagecreatefromstring($displaced_legend);
$opaque_pixels = 0;
for ($y=0;$y<imagesy($displaced_image);++$y) { for ($x=0;$x<imagesx($displaced_image);++$x) { if (imagecolorsforindex($displaced_image,imagecolorat($displaced_image,$x,$y))['alpha'] < 127) { ++$opaque_pixels; } } }
check($opaque_pixels > 0,'Context legend conservatively includes displaced label from outside viewport');
$metadata_service = $config->service('demo');
$metadata_service['keywords'] = ['regional','test & metadata'];
$metadata_service['contact'] = ['person'=>'Map administration','organization'=>'Example','email'=>'maps@example.org'];
$metadata_service['layers']['places']['keywords'] = ['places'];
$metadata_service['layers']['places']['attribution'] = 'Synthetic sample';
$metadata_service['layers']['places']['metadata_url'] = 'https://example.org/metadata.xml';
$metadata_datasets = [];
foreach ($metadata_service['layers'] as $layer) { $metadata_datasets[$layer['dataset']] = new PureWms\Dataset($config->root.'/data/'.$layer['dataset'].'.sqlite'); }
$metadata_xml = PureWms\Capabilities::build($config,'demo',$metadata_service,$metadata_datasets);
$metadata_document = new DOMDocument(); $metadata_document->loadXML($metadata_xml);
check($metadata_document->schemaValidate(__DIR__.'/schemas/capabilities.xsd'),'Extended contact, keywords, attribution and style links validate against WMS schema');
