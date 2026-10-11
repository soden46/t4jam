<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function metaResults(string $conversion, int|string $count): array
    {
        return [['indicator' => 'actions:'.$conversion, 'values' => [['value' => (string) $count]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }
}
