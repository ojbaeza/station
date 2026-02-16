<?php

declare(strict_types=1);

namespace Station\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Station\Contracts\CheckpointRepositoryInterface;

final class DatabaseCheckpointRepository implements CheckpointRepositoryInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
        private readonly bool $encrypt,
        private readonly Encrypter $encrypter,
    ) {}

    public function save(string $jobId, array $data): void
    {
        $now = CarbonImmutable::now();
        $encodedData = json_encode($data);

        if ($this->encrypt) {
            $encodedData = $this->encrypter->encrypt($encodedData);
        }

        $this->connection->table($this->table)->updateOrInsert(
            ['job_id' => $jobId],
            [
                'data' => $encodedData,
                'encrypted' => $this->encrypt,
                'created_at' => $now->toDateTimeString(),
                'updated_at' => $now->toDateTimeString(),
            ],
        );
    }

    public function get(string $jobId): ?array
    {
        $row = $this->connection->table($this->table)
            ->where('job_id', $jobId)
            ->first();

        if ($row === null) {
            return null;
        }

        $data = $row->data;

        if ($row->encrypted) {
            $data = $this->encrypter->decrypt($data);
        }

        return json_decode($data, true);
    }

    public function delete(string $jobId): void
    {
        $this->connection->table($this->table)
            ->where('job_id', $jobId)
            ->delete();
    }

    public function exists(string $jobId): bool
    {
        return $this->connection->table($this->table)
            ->where('job_id', $jobId)
            ->exists();
    }

    public function prune(int $hours): int
    {
        $threshold = CarbonImmutable::now()->subHours($hours);

        return $this->connection->table($this->table)
            ->where('updated_at', '<', $threshold->toDateTimeString())
            ->delete();
    }
}
