<?php

declare(strict_types=1);

namespace Station\Core;

use Illuminate\Support\Facades\Bus;
use Ramsey\Uuid\Uuid;

/**
 * Represents a chain of jobs with dependency management.
 *
 * Usage:
 * Chain::create([
 *     new FirstJob(),
 *     new SecondJob(),
 *     new ThirdJob(),
 * ])->dispatch();
 */
final class Chain
{
    private string $id;

    private ?string $name = null;

    private ?string $connection = null;

    private ?string $queue = null;

    /** @var array<object> */
    private array $jobs;

    /** @var callable|null */
    private mixed $catchCallback = null;

    /** @var callable|null */
    private mixed $finallyCallback = null;

    /**
     * @param array<object> $jobs
     */
    public function __construct(array $jobs)
    {
        $this->id = Uuid::uuid7()->toString();
        $this->jobs = $jobs;
    }

    /**
     * Create a new chain.
     *
     * @param array<object> $jobs
     */
    public static function create(array $jobs): self
    {
        return new self($jobs);
    }

    /**
     * Set the chain name.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the connection for all jobs.
     */
    public function onConnection(string $connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /**
     * Set the queue for all jobs.
     */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Set a callback to be called if the chain fails.
     */
    public function catch(callable $callback): self
    {
        $this->catchCallback = $callback;

        return $this;
    }

    /**
     * Set a callback to be called after the chain completes (success or failure).
     */
    public function finally(callable $callback): self
    {
        $this->finallyCallback = $callback;

        return $this;
    }

    /**
     * Dispatch the chain.
     */
    public function dispatch(): string
    {
        $jobs = $this->prepareJobs();

        // Use Laravel's Bus chain
        $chain = Bus::chain($jobs);

        if ($this->connection !== null) {
            $chain->onConnection($this->connection);
        }

        if ($this->queue !== null) {
            $chain->onQueue($this->queue);
        }

        if ($this->catchCallback !== null) {
            $chain->catch($this->catchCallback);
        }

        if ($this->finallyCallback !== null && method_exists($chain, 'finally')) {
            $chain->finally($this->finallyCallback);
        }

        $chain->dispatch();

        return $this->id;
    }

    /**
     * Get the chain ID.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Get all jobs in the chain.
     *
     * @return array<object>
     */
    public function getJobs(): array
    {
        return $this->jobs;
    }

    /**
     * Prepare jobs for dispatch.
     *
     * @return array<object>
     */
    private function prepareJobs(): array
    {
        $prepared = [];

        foreach ($this->jobs as $index => $job) {
            // Add chain metadata to job
            if (property_exists($job, 'chainId')) {
                $job->chainId = $this->id;
            }

            if (property_exists($job, 'chainIndex')) {
                $job->chainIndex = $index;
            }

            if (property_exists($job, 'chainName')) {
                $job->chainName = $this->name;
            }

            $prepared[] = $job;
        }

        return $prepared;
    }
}
