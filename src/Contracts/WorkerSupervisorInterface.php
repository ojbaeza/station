<?php

declare(strict_types=1);

namespace Station\Contracts;

interface WorkerSupervisorInterface
{
    /**
     * Get the supervisor ID.
     */
    public function getId(): string;

    /**
     * Get the supervisor name.
     */
    public function getName(): string;

    /**
     * Start the supervisor.
     *
     * @param array<int, string> $queues
     * @param array<string, mixed> $options
     */
    public function start(array $queues, array $options = []): void;

    /**
     * Pause the supervisor.
     */
    public function pause(): void;

    /**
     * Resume the supervisor.
     */
    public function resume(): void;

    /**
     * Terminate the supervisor gracefully.
     */
    public function terminate(): void;

    /**
     * Check if the supervisor is paused.
     */
    public function isPaused(): bool;

    /**
     * Get the number of active workers.
     */
    public function getWorkerCount(): int;
}
