<?php

declare(strict_types=1);

namespace Station\Core;

use Carbon\CarbonImmutable;
use Closure;
use DateInterval;
use DateTimeInterface;
use Station\Contracts\JobManagerInterface;

/**
 * Fluent builder for dispatching jobs.
 */
final class PendingDispatch
{
    private ?string $queue = null;

    private ?string $connection = null;

    private ?CarbonImmutable $delay = null;

    private ?string $batchId = null;

    /** @var array<int, string> */
    private array $tags = [];

    /**  */
    private JobManagerInterface|Closure $manager;

    /**
     */
    public function __construct(
        private readonly object $job,
        JobManagerInterface|Closure $manager,
    ) {
        $this->manager = $manager;
    }

    /**
     * Set the queue for the job.
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Set the connection (driver) for the job.
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Set the delay for the job.
     */
    public function delay(DateTimeInterface|DateInterval|int $delay): self
    {
        if ($delay instanceof DateInterval) {
            $this->delay = CarbonImmutable::now()->add($delay);
        } elseif ($delay instanceof DateTimeInterface) {
            $this->delay = CarbonImmutable::instance($delay);
        } else {
            $this->delay = CarbonImmutable::now()->addSeconds($delay);
        }

        return $this;
    }

    /**
     * Add tags to the job.
     *
     * @param array<int, string> $tags
     */
    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);

        return $this;
    }

    /**
     * Set the batch ID for the job.
     */
    public function withBatchId(string $batchId): self
    {
        $this->batchId = $batchId;

        return $this;
    }

    /**
     * Dispatch the job.
     */
    public function dispatch(): string
    {
        if ($this->manager instanceof Closure) {
            return ($this->manager)(
                $this->job,
                $this->queue,
                $this->delay !== null ? $this->delay->diffInSeconds(CarbonImmutable::now()) : 0,
            );
        }

        return $this->manager->dispatch(
            $this->job,
            $this->queue,
            $this->delay,
            $this->batchId,
            $this->tags,
            $this->connection,
        );
    }

    /**
     * Dispatch the job synchronously (for testing).
     */
    public function dispatchSync(): void
    {
        if ($this->manager instanceof Closure) {
            ($this->manager)($this->job, $this->queue, 0);

            return;
        }

        $this->manager->dispatchSync($this->job);
    }
}
