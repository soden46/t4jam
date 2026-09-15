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
        public readonly ?int $retryAfter = null,
        public readonly bool $transient = false,
        public readonly bool $outcomeUnknown = false,
        public readonly ?int $metaSubcode = null,
        public readonly ?string $providerMessage = null,
    ) {
        parent::__construct($message);
    }

    public function retryable(): bool
    {
        if ($this->metaCode === 190 || in_array($this->httpStatus, [401, 403], true) || in_array($this->metaCode, [10, 200], true)) {
            return false;
        }

        return $this->transient || $this->httpStatus === 429 || $this->httpStatus >= 500
            || in_array($this->metaCode, [4, 17, 613, 80000, 80001, 80002, 80003, 80004], true);
    }

    public function retryDelay(int $attempt): int
    {
        return min(3600, max($this->retryAfter ?? 0, [60, 180, 300][min(2, max(0, $attempt - 1))]));
    }
}
