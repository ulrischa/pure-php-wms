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
        $slot = hexdec(substr($key, 0, 7)) % max(1, $this->config->server['cache_slots']);

        return $this->config->root . '/cache/map-' . $slot . '.bin';
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
        $path = $this->path($key);
        $temporary = @tempnam($this->config->root . '/cache', 'render-');
        if ($temporary === false) {
            error_log('WMS cache: cannot create temporary file.');
            return;
        }
        try {
            if (@file_put_contents($temporary, $key . "\n" . $png) !== strlen($png) + 65 || !@rename($temporary, $path)) {
                error_log('WMS cache: write failed; serving uncached response.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
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
