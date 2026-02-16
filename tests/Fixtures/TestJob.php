<?php

declare(strict_types=1);

namespace Station\Tests\Fixtures;

class TestJob
{
    /**  */
    public static bool $handled = false;

    public ?string $queue = null;

    public ?string $stationJobId = null;

    public function __construct(
        public readonly string $message = 'test',
    ) {}

    public function handle(): void
    {
        self::$handled = true;
    }
}
