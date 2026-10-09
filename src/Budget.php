<?php

declare(strict_types=1);

namespace PureWms;

final class Budget
{
    private float $deadline;
    private int $features = 0;
    private int $vertices = 0;
    private int $work = 0;

    public function __construct(private readonly array $limits)
    {
        $this->deadline = microtime(true) + $limits['max_seconds'];
    }

    public function check(int $features = 0, int $vertices = 0, int $work = 0): void
    {
        $this->features += $features;
        $this->vertices += $vertices;
        $this->work += $work;
        if ($this->features > $this->limits['max_features'] || $this->vertices > $this->limits['max_vertices'] || $this->work > 20000000 || microtime(true) > $this->deadline) {
            throw new WmsException('Rendering budget exceeded. Zoom in, simplify data or reduce image size.', 'NoApplicableCode', 503);
        }
    }
}
