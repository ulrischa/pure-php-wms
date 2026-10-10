<?php

declare(strict_types=1);
// Optional second argument selects a baseline checkout with its own vendor directory.
$project_root = $argv[2] ?? dirname(__DIR__);
require $project_root . '/vendor/autoload.php';
$count = (int)($argv[1] ?? 2048);
if ($count < 2 || $count > 4000) {
    throw new RuntimeException('Use 2..4000 synthetic line records.');
}
$root = sys_get_temp_dir() . '/wms-stream-' . bin2hex(random_bytes(6));
foreach (['config', 'sources', 'data', 'cache'] as $directory) {
    mkdir($root . '/' . $directory, 0700, true);
}
try {
    copy($project_root . '/config/server.json', $root . '/config/server.json');
    file_put_contents($root . '/config/sources.json', json_encode(['lines' => ['file' => 'lines.shp', 'crs' => 'EPSG:4326']]));
    $vertices = 1024;
    $record_bytes = 48 + 16 * $vertices;
    $shp = fopen($root . '/sources/lines.shp', 'wb');
    fwrite($shp, pack('N7', 9994, 0, 0, 0, 0, 0, (100 + ($record_bytes + 8) * $count) / 2) . pack('V2e8', 1000, 3, 11, 48, 12, 49, 0, 0, 0, 0));
    for ($i = 0; $i < $count; ++$i) {
        $y = 48 + $i / $count;
        fwrite($shp, pack('N2', $i + 1, $record_bytes / 2) . pack('Ve4V3', 3, 11, $y, 12, $y, 1, $vertices, 0));
        for ($j = 0; $j < $vertices; ++$j) {
            fwrite($shp, pack('e2', 11 + $j / ($vertices - 1), $y));
        }
    }
    fclose($shp);
    $dbf = fopen($root . '/sources/lines.dbf', 'wb');
    fwrite($dbf, "\x03\x7e\x0a\x09" . pack('Vvv', $count, 65, 41) . str_repeat("\0", 20));
    fwrite($dbf, str_pad('NAME', 11, "\0") . 'C' . str_repeat("\0", 4) . chr(40) . str_repeat("\0", 15) . "\r");
    for ($i = 0; $i < $count; ++$i) {
        fwrite($dbf, ' ' . str_pad('Line ' . $i, 40));
    }
    fwrite($dbf, "\x1a");
    fclose($dbf);
    $start = microtime(true);
    $meta = PureWms\Dataset::import(new PureWms\Config($root), 'lines');
    if ($meta['count'] !== $count || $meta['vertices'] !== $count * $vertices) {
        throw new RuntimeException('Imported counts differ.');
    }
    $dataset = new PureWms\Dataset($root . '/data/lines.sqlite');
    $selected = iterator_to_array($dataset->query([11.5, 48, 11.6, 48.000001], 10));
    if (count($selected) !== 1 || $selected[0]['properties']['NAME'] !== 'Line 0') {
        throw new RuntimeException('Imported spatial selection or DBF alignment differs.');
    }
    $dataset = null;
    $elapsed = microtime(true) - $start;
    $published_hash = hash_file('sha256', $root . '/data/lines.sqlite');
    $broken = fopen($root . '/sources/lines.shp', 'r+b');
    fseek($broken, 100 + $record_bytes + 8 + 8);
    fwrite($broken, pack('V', 31));
    fclose($broken);
    try {
        PureWms\Dataset::import(new PureWms\Config($root), 'lines');
        throw new RuntimeException('Malformed SHP unexpectedly imported.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'record type differs')) {
            throw $error;
        }
    }
    if (hash_file('sha256', $root . '/data/lines.sqlite') !== $published_hash || glob($root . '/data/*.tmp-*')) {
        throw new RuntimeException('Failed SHP import changed the published database or left temporary files.');
    }
    echo json_encode(['features' => $count, 'vertices' => $meta['vertices'], 'shp_bytes' => filesize($root . '/sources/lines.shp'), 'seconds' => $elapsed, 'php_peak_bytes' => memory_get_peak_usage(true)], JSON_PRETTY_PRINT), "\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
