<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/vendor/autoload.php';

use PureWms\{Config,Dataset};

try {
    $command = $argv[1] ?? 'help';
    $config = new Config(dirname(__DIR__));
    switch ($command) {
        case 'doctor':
            $report = PureWms\Health::report($config, in_array('--deep', $argv, true));
            if (in_array('--json', $argv, true)) { echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; }
            else {
                foreach ($report['checks'] as $check) { echo ($check['ok'] ? 'OK ' : 'FAIL ') . $check['name'] . ': ' . (is_string($check['detail']) ? $check['detail'] : json_encode($check['detail'])) . "\n"; }
                echo $report['note'] . "\n";
            }
            if (!$report['ok']) { exit(1); }
            break;
        case 'validate':
            (new PureWms\Projection($config->crs))->validate();
            foreach (glob($config->root.'/config/services/*.json') as $file) {
                $name = basename($file, '.json');
                $service = $config->service($name);
                echo $name.': '.count($service['layers'])." layer(s)\n";
            }
            foreach ($config->sources as $name => $source) {
                Config::id($name);
                $config->sourcePath($source['file']);
                $config->crs->get($source['crs']);
                echo $name.': '.$source['crs']."\n";
            }
            break;
        case 'import':
            $id = $argv[2] ?? '';
            $names = $id === '--all' ? array_keys($config->sources) : [$id];
            foreach ($names as $name) {
                $meta = Dataset::import($config, $name);
                echo $name.': '.$meta['count'].' features, '.$meta['vertices']." vertices\n";
            }
            break;
        case 'list':
            foreach ($config->sources as $name => $source) {
                $file = $config->root.'/data/'.Config::id($name).'.sqlite';
                echo $name.': '.(is_file($file) ? json_encode((new Dataset($file))->metadata, JSON_UNESCAPED_SLASHES) : 'not imported')."\n";
            }
            break;
        case 'clear-cache':
            foreach (glob($config->root.'/cache/map-*.bin') as $file) {
                unlink($file);
            } echo "Map cache cleared.\n";
            break;
        default: echo "Commands:\n  php bin/console.php doctor [--json] [--deep]\n  php bin/console.php validate\n  php bin/console.php import --all\n  php bin/console.php import DATASET\n  php bin/console.php list\n  php bin/console.php clear-cache\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
