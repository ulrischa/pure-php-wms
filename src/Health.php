<?php

declare(strict_types=1);

namespace PureWms;

/** CLI-only deployment diagnostics; never expose these details as a public endpoint. */
final class Health
{
    public static function report(Config $config, bool $deep = false): array
    {
        $checks = [];
        $check = static function (string $name, callable $action) use (&$checks): void {
            try { $detail = $action(); $checks[] = ['name' => $name, 'ok' => true, 'detail' => $detail]; }
            catch (\Throwable $error) { $checks[] = ['name' => $name, 'ok' => false, 'detail' => $error->getMessage()]; }
        };
        $check('PHP', static function () {
            if (PHP_VERSION_ID < 80200 || PHP_INT_SIZE !== 8) { throw new \RuntimeException('PHP 8.2+ with 64-bit integers required.'); }
            foreach (['dom','gd','pdo_sqlite','xmlwriter','mbstring','iconv'] as $extension) {
                if (!extension_loaded($extension)) { throw new \RuntimeException('Missing extension: ' . $extension); }
            }
            if (!(gd_info()['FreeType Support'] ?? false)) { throw new \RuntimeException('FreeType support required.'); }
            return PHP_VERSION;
        });
        $check('SQLite RTree', static function () { (new \PDO('sqlite::memory:'))->exec('CREATE VIRTUAL TABLE test USING rtree(id,x1,x2,y1,y2)'); return 'available'; });
        $check('CRS', static function () use ($config) { (new Projection($config->crs))->validate(); return count($config->crs->codes()) . ' definitions initialized'; });
        $check('Cache filesystem', static function () use ($config) {
            $file = tempnam($config->root . '/cache', 'doctor-');
            if ($file === false) { throw new \RuntimeException('Cannot create cache file.'); }
            $renamed = $file . '.renamed'; $handle = null;
            try {
                $handle = fopen($file, 'r+');
                if (!$handle || !flock($handle, LOCK_EX | LOCK_NB) || fwrite($handle, 'ok') !== 2 || !rename($file, $renamed)) { throw new \RuntimeException('Cache write, locking or rename failed.'); }
            } finally {
                if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); }
                @unlink($file); @unlink($renamed);
            }
            return 'write, lock and rename succeeded';
        });
        $sources = [];
        foreach (glob($config->root . '/config/services/*.json') as $file) {
            $id = basename($file, '.json');
            $check('Service ' . $id, static function () use ($config, $id, &$sources) {
                $service = $config->service($id);
                foreach ($service['layers'] as $layer) {
                    $sources[$layer['dataset']] = true;
                    foreach ($layer['overviews'] ?? [] as $overview) { $sources[$overview['dataset']] = true; }
                }
                return count($service['layers']) . ' layers validated';
            });
        }
        foreach (array_keys($sources) as $source) {
            $check('Dataset ' . $source, static function () use ($config, $source, $deep) {
                $path = $config->root . '/data/' . $source . '.sqlite';
                $dataset = new Dataset($path);
                if ($deep) {
                    $pdo = new \PDO('sqlite:file:' . str_replace('%2F', '/', rawurlencode($path)) . '?mode=ro');
                    if ($pdo->query('PRAGMA quick_check')->fetchColumn() !== 'ok') { throw new \RuntimeException('SQLite integrity check failed.'); }
                    if ((int)$pdo->query('SELECT COUNT(*) FROM features')->fetchColumn() !== (int)$pdo->query('SELECT COUNT(*) FROM spatial')->fetchColumn()) { throw new \RuntimeException('Feature/index count mismatch.'); }
                }
                return ['features' => $dataset->metadata['count'], 'bytes' => filesize($path), 'deep_check' => $deep];
            });
        }
        $files = glob($config->root . '/cache/map-*.bin');
        $bytes = 0;
        foreach ($files as $file) { $stat = @stat($file); if ($stat && is_file($file)) { $bytes += $stat['size']; } }
        return ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks,
            'cache' => ['files' => count($files), 'bytes' => $bytes, 'max_bytes' => $config->server['cache_max_bytes']],
            'limits' => ['memory_limit' => ini_get('memory_limit'), 'max_parallel' => $config->server['max_parallel']],
            'note' => 'CLI checks do not prove Apache permissions, web-PHP configuration or OGC conformance.'];
    }
}
