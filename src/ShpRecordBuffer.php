<?php

declare(strict_types=1);

namespace PureWms;

use PhpMyAdmin\ShapeFile\{ShapeFile, ShapeType};

/** Bounded input adapter for the upstream public ShapeRecord::loadFromFile API. */
final class ShpRecordBuffer extends ShapeFile
{
    private int $offset = 0;

    public function __construct(private readonly string $bytes)
    {
        parent::__construct(ShapeType::Null);
    }

    public function readSHP(int $bytes): string|false
    {
        if ($bytes < 0 || $bytes > strlen($this->bytes) - $this->offset) {
            throw new \RuntimeException('SHP decoder exceeded its record boundary.');
        }
        $value = substr($this->bytes, $this->offset, $bytes);
        $this->offset += $bytes;
        return $value;
    }
}
