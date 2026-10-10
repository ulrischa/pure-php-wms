<?php

declare(strict_types=1);

namespace PureWms;

use PhpMyAdmin\ShapeFile\{ShapeRecord, ShapeType};

/** Stream records and validate allocation bounds before invoking the geometry decoder. */
final class ShpReader
{
    public static function records(string $path): \Generator
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Cannot open SHP.');
        }
        try {
            $size = fstat($file)['size'];
            $header = self::readExact($file, 100);
            $fileType = unpack('V', substr($header, 32, 4))[1];
            if ($size > 268435456 || unpack('N', substr($header, 0, 4))[1] !== 9994
                || unpack('N', substr($header, 24, 4))[1] * 2 !== $size
                || unpack('V', substr($header, 28, 4))[1] !== 1000
                || !in_array($fileType, [0,1,3,5,8,11,13,15,18,21,23,25,28], true)) {
                throw new \RuntimeException('Invalid SHP header or file exceeds 256 MiB.');
            }
            $number = 0;
            while (ftell($file) < $size) {
                $head = self::readExact($file, 8);
                $recordHeader = unpack('Nnumber/Nwords', $head);
                $length = $recordHeader['words'] * 2;
                if (++$number > 1000000 || $recordHeader['number'] !== $number
                    || $length < 4 || $length > 1048576 || $length > $size - ftell($file)) {
                    throw new \RuntimeException('Invalid SHP record number, length or record exceeds 1 MiB.');
                }
                $raw = self::readExact($file, $length);
                $type = unpack('V', substr($raw, 0, 4))[1];
                if ($type !== 0 && $type !== $fileType) {
                    throw new \RuntimeException('SHP record type differs from file header.');
                }
                $xyLength = self::validateRecord($raw, $type);
                // Rendering is 2D. Validate Z/M storage, then decode only XY. This also
                // avoids allocating unused Z/M arrays in the upstream record decoder.
                $xy = pack('V', $type % 10) . substr($raw, 4, $xyLength - 4);
                $buffer = new ShpRecordBuffer(pack('NN', $number, strlen($xy) / 2) . $xy);
                $record = new ShapeRecord(ShapeType::Null);
                $record->loadFromFile($buffer, false);
                if ($record->lastError !== '') {
                    throw new \RuntimeException('Malformed SHP record: ' . $record->lastError);
                }
                yield $record;
                unset($record, $buffer, $raw, $xy);
            }
        } finally {
            fclose($file);
        }
    }

    private static function validateRecord(string $raw, int $type): int
    {
        $length = strlen($raw);
        $base = $type % 10;
        if ($base === 0) {
            if ($length !== 4) {
                throw new \RuntimeException('Invalid null SHP record.');
            }
            return 4;
        }
        if ($base === 1) {
            $allowed = match ($type) { 1 => [20], 11 => [28,36], 21 => [28] };
            if (!in_array($length, $allowed, true)) {
                throw new \RuntimeException('Invalid SHP point length.');
            }
            return 20;
        }
        $prefix = $base === 8 ? 40 : 44;
        if ($length < $prefix) {
            throw new \RuntimeException('Truncated SHP geometry.');
        }
        $parts = $base === 8 ? 0 : unpack('V', substr($raw, 36, 4))[1];
        $points = unpack('V', substr($raw, $base === 8 ? 36 : 40, 4))[1];
        $xyLength = $prefix + 4 * $parts + 16 * $points;
        if ($points < 1 || $points > Dataset::MAX_FEATURE_VERTICES || $parts > 1000
            || ($base !== 8 && ($parts < 1 || $parts > $points)) || $xyLength > $length) {
            throw new \RuntimeException('SHP feature exceeds 20000 vertices, 1000 parts or has invalid lengths.');
        }
        $minimum = $xyLength + ($type >= 10 && $type < 20 ? 16 + 8 * $points : 0);
        $allowed = $type < 10 ? [$minimum] : [$minimum, $minimum + 16 + 8 * $points];
        if (!in_array($length, $allowed, true)) {
            throw new \RuntimeException('Truncated or invalid SHP Z/M array.');
        }
        $previous = -1;
        for ($i = 0; $i < $parts; ++$i) {
            $start = unpack('V', substr($raw, 44 + $i * 4, 4))[1];
            if ($start <= $previous || $start >= $points || ($i === 0 && $start !== 0)) {
                throw new \RuntimeException('Invalid SHP part index.');
            }
            if ($previous >= 0 && $start - $previous < ($base === 5 ? 4 : 2)) {
                throw new \RuntimeException('SHP part contains too few points.');
            }
            $previous = $start;
        }
        if ($parts && $points - $previous < ($base === 5 ? 4 : 2)) {
            throw new \RuntimeException('SHP part contains too few points.');
        }
        return $xyLength;
    }

    private static function readExact(mixed $file, int $length): string
    {
        $bytes = fread($file, $length);
        if ($bytes === false || strlen($bytes) !== $length) {
            throw new \RuntimeException('Truncated SHP file.');
        }
        return $bytes;
    }
}
