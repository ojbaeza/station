<?php

declare(strict_types=1);

namespace Station\Tests\Fixtures;

class TestJobWithOptions
{
    public ?string $queue = 'emails';

    public ?string $stationJobId = null;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public readonly string $message = 'test',
    ) {}

    public function handle(): void
    {
        // Do nothing
    }

    /** @return array<string> */
    public function tags(): array
    {
        return ['tag1', 'tag2'];
    }
}
