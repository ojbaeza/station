<?php

declare(strict_types=1);

namespace Station\Contracts;

use Illuminate\Contracts\Queue\Queue;

interface DriverInterface extends Queue
{
    /**
     * Get the connection name.
     */
    public function getConnectionName(): string;

    /**
     * Set the connection name.
     *
     * @param  string  $name
     * @return $this
     */
    public function setConnectionName($name);

    /**
     * Get the queue size.
     *
     * @param  string|null  $queue
     */
    public function size($queue = null): int;

    /**
     * Clear all jobs from a queue.
     */
    public function clear(string $queue): int;

    /**
     * Pause a queue.
     */
    public function pause(string $queue): void;

    /**
     * Resume a paused queue.
     */
    public function resume(string $queue): void;

    /**
     * Check if a queue is paused.
     */
    public function isPaused(string $queue): bool;

    /**
     * Get the dead letter queue contents.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDeadLetterQueue(string $queue, int $limit = 50): array;

    /**
     * Requeue a message from the dead letter queue.
     */
    public function requeueFromDeadLetter(string $queue, string $messageId): bool;

    /**
     * Get driver-specific health status.
     *
     * @return array{connected: bool, latency_ms: int, message?: string}
     */
    public function healthCheck(): array;

    /**
     * Get driver-specific detailed info for metrics dashboard.
     *
     * @return array<string, mixed>
     */
    public function getDriverInfo(string $queue): array;
}
