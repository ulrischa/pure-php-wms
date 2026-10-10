<?php

declare(strict_types=1);

namespace PureWms;

/** Compatibility fix for proj4php 2.0.19's inverse at the LAEA origin. */
final class Laea extends \proj4php\projCode\Laea
{
    public function inverse($point)
    {
        // Upstream's zero-radius branch returns 0/phi0 instead of long0/lat0.
        if (hypot($point->x - $this->x0, $point->y - $this->y0) / $this->a < 1e-10) {
            $point->x = $this->long0;
            $point->y = $this->lat0;
            return $point;
        }
        return parent::inverse($point);
    }
}
