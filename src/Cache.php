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
        $path = $this->path($key);
        if (!is_file($path) || filemtime($path) < time() - $this->config->server['cache_ttl']) {
            return null;
        }
        $bytes = file_get_contents($path);
        if ($bytes === false || substr($bytes, 0, 65) !== $key . "\n") {
            return null;
        }

        return substr($bytes, 65);
    }

    public function write(string $key, string $png): void
    {
        if (!$this->config->server['cache_slots'] || !$this->config->server['cache_ttl']) {
            return;
        }
        $path = $this->path($key);
        $temporary = tempnam($this->config->root . '/cache', 'render-');
        if ($temporary === false) {
            throw new \RuntimeException('Cache is not writable.');
        }
        try {
            if (file_put_contents($temporary, $key . "\n" . $png) !== strlen($png) + 65 || !rename($temporary, $path)) {
                throw new \RuntimeException('Cannot write cache.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
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
