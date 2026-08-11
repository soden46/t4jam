<?php

namespace App\Exceptions;

use RuntimeException;

class MetaAdsException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?int $metaCode = null,
        public readonly ?string $metaType = null,
    ) {
        parent::__construct($message);
    }
}
