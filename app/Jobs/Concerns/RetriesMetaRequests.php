<?php

namespace App\Jobs\Concerns;

use App\Exceptions\MetaAdsException;

trait RetriesMetaRequests
{
    protected function retryOrFail(MetaAdsException $exception): void
    {
        if ($exception->retryable() && $this->attempts() < $this->tries) {
            $this->release($exception->retryDelay($this->attempts()));

            return;
        }

        $this->fail($exception);
    }
}
