<?php

declare(strict_types=1);

namespace PureWms;

/** Read-only DBF III/IV attributes; no dbase extension is needed. */
final class Dbf
{
    public static function rows(string $path, string $encoding): \Generator
    {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Cannot read DBF.');
        }
        try {
            $header = fread($handle, 32);
            if (strlen($header) !== 32) {
                throw new \RuntimeException('Truncated DBF.');
            }
            $meta = unpack('Vcount/vheader/vrecord', substr($header, 4, 8));
            if ($meta['header'] < 33 || $meta['record'] < 1 || $meta['count'] > 1000000 || filesize($path) < $meta['header'] + $meta['record'] * $meta['count']) {
                throw new \RuntimeException('Invalid DBF lengths.');
            }
            $fields = [];
            $length = 1;
            while (ftell($handle) + 32 < $meta['header']) {
                $raw = fread($handle, 32);
                if (strlen($raw) !== 32) {
                    throw new \RuntimeException('Truncated DBF descriptor.');
                }
                $name = rtrim(strtok(substr($raw, 0, 11), "\0") ?: '');
                $fields[] = [$name,$raw[11],ord($raw[16])];
                $length += ord($raw[16]);
            }
            if ($length !== $meta['record']) {
                throw new \RuntimeException('DBF field lengths do not match record size.');
            }
            fseek($handle, $meta['header']);
            for ($i = 1;$i <= $meta['count'];++$i) {
                $record = fread($handle, $meta['record']);
                if (strlen($record) !== $meta['record']) {
                    throw new \RuntimeException('Truncated DBF row.');
                }
                if ($record[0] === '*') {
                    yield $i => null;
                    continue;
                }
                $offset = 1;
                $properties = [];
                foreach ($fields as [$name,$type,$size]) {
                    $value = trim(substr($record, $offset, $size));
                    $offset += $size;
                    $properties[$name] = match ($type) {
                        'C' => self::convert($value, $encoding),
                        'N','F' => $value === '' ? null : (is_numeric($value) ? (float)$value : null),
                        'L' => in_array(strtoupper($value), ['Y','T'], true) ? true : (in_array(strtoupper($value), ['N','F'], true) ? false : null),
                        'D' => preg_match('/^\d{8}$/D', $value) ? substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2) : null,
                        default => throw new \RuntimeException('Unsupported DBF field type: ' . $type . '. Export plain DBF attributes from your GIS.'),
                    };
                }
                yield $i => $properties;
            }
        } finally {
            fclose($handle);
        }
    }

    private static function convert(string $value, string $encoding): string
    {
        $converted = iconv($encoding, 'UTF-8', $value);
        if ($converted === false) {
            throw new \RuntimeException('Invalid DBF encoding; configure encoding explicitly.');
        }

        return $converted;
    }
}
