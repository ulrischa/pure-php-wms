<?php

declare(strict_types=1);

namespace PureWms;

final class Cache
{
    public function __construct(private readonly Config $config)
    {
    }

    private function path(string $key): string
    {
        return $this->config->root . '/cache/map-' . $key . '.bin';

    }

    public function read(string $key): ?string
    {
        if (!$this->config->server['cache_slots'] || !$this->config->server['cache_ttl']) {
            return null;
        }
        // Open first: an atomic slot replacement or clear-cache must not turn
        // an optional cache miss into a failed WMS request.
        $handle = @fopen($this->path($key), 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $stat = fstat($handle);
            $maximum = $this->config->server['max_pixels'] * 5 + 1048576;
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['mtime'] < time() - $this->config->server['cache_ttl']
                || $stat['size'] > $maximum || fread($handle, 65) !== $key . "\n") {
                return null;
            }
            @touch($this->path($key), $stat['mtime'], time());
            $png = stream_get_contents($handle, $maximum);
            return $png !== false && str_starts_with($png, "\x89PNG\r\n\x1a\n") ? $png : null;
        } finally {
            fclose($handle);
        }
    }

    public function write(string $key, string $png): void
    {
        if (!$this->config->server['cache_slots'] || !$this->config->server['cache_ttl']) {
            return;
        }
        $size = strlen($png) + 65;
        if ($size > $this->config->server['cache_max_bytes']) {
            return;
        }
        $guard = @fopen($this->config->root . '/cache/maintenance.lock', 'c');
        if (!$guard || !flock($guard, LOCK_EX | LOCK_NB)) {
            if ($guard) { fclose($guard); }
            return; // Cache maintenance must never delay a rendered response.
        }
        $temporary = false;
        try {
            $path = $this->path($key);
            $files = [];
            $bytes = 0;
            foreach (glob($this->config->root . '/cache/map-*.bin') as $file) {
                clearstatcache(true, $file);
                $stat = @stat($file);
                if (!$stat || ($stat['mode'] & 0170000) !== 0100000) { continue; }
                if ($file === $path || $stat['mtime'] < time() - $this->config->server['cache_ttl']) {
                    if (@unlink($file)) { continue; }
                }
                $files[] = ['path' => $file, 'bytes' => $stat['size'], 'access' => $stat['atime']];
                $bytes += $stat['size'];
            }
            usort($files, static fn ($a, $b) => $a['access'] <=> $b['access'] ?: strcmp($a['path'], $b['path']));
            while ($files && (count($files) >= $this->config->server['cache_slots'] || $bytes + $size > $this->config->server['cache_max_bytes'])) {
                $old = array_shift($files);
                if (!@unlink($old['path'])) { return; }
                $bytes -= $old['bytes'];
            }
            $temporary = @tempnam($this->config->root . '/cache', 'render-');
            if (!$temporary || @file_put_contents($temporary, $key . "\n" . $png) !== $size || !@rename($temporary, $path)) {
                error_log('WMS cache: write failed; serving uncached response.');
            }
        } finally {
            if ($temporary && is_file($temporary)) { @unlink($temporary); }
            flock($guard, LOCK_UN);
            fclose($guard);
        }
    }

    /** Reserve a bounded slot, checking exact keys under a short metadata lock. */
    public function acquireKey(string $key): mixed
    {
        $guard = fopen($this->config->root . '/cache/requests.lock', 'c');
        if (!$guard || !flock($guard, LOCK_EX)) { throw new WmsException('Cannot lock request registry.', 'NoApplicableCode', 503); }
        $candidate = null;
        try {
            for ($i = 0; $i < $this->config->server['max_parallel']; ++$i) {
                $handle = fopen($this->config->root . '/cache/request-' . $i . '.lock', 'c+');
                if (!$handle) { throw new WmsException('Cannot open request slot.', 'NoApplicableCode', 503); }
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    if ($candidate === null) { $candidate = $handle; }
                    else { flock($handle, LOCK_UN); fclose($handle); }
                } else {
                    $existing = fread($handle, 64);
                    fclose($handle);
                    if ($existing === $key) { throw new WmsException('Map already rendering. Please retry shortly.', 'NoApplicableCode', 503); }
                }
            }
            if ($candidate === null) { throw new WmsException('Server busy. Please retry shortly.', 'NoApplicableCode', 503); }
            rewind($candidate);
            if (fwrite($candidate, $key) !== 64 || !fflush($candidate)) { throw new WmsException('Cannot record request key.', 'NoApplicableCode', 503); }
            $result = $candidate;
            $candidate = null;
            return $result;
        } finally {
            if (is_resource($candidate)) { flock($candidate, LOCK_UN); fclose($candidate); }
            flock($guard, LOCK_UN); fclose($guard);
        }
    }

    public function acquire(): mixed
    {
        for ($i = 0;$i < $this->config->server['max_parallel'];++$i) {
            $handle = fopen($this->config->root . '/cache/worker-' . $i . '.lock', 'c');
            if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            if ($handle) {
                fclose($handle);
            }
        }
        throw new WmsException('Server busy. Please retry shortly.', 'NoApplicableCode', 503);
    }
}
