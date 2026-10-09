<?php

declare(strict_types=1);

namespace PureWms;

final class WmsException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $wmsCode = 'InvalidParameterValue', public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
