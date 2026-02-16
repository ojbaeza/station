<?php

declare(strict_types=1);

namespace Station\DTOs;

use stdClass;

/**
 * Represents a raw database row from the station_workflows table.
 */
final readonly class WorkflowInstanceRow
{
    public function __construct(
        public string $id,
        public string $definition_id,
        public string $definition_name,
        public ?string $connection,
        public string $status,
        public ?string $current_step,
        public string $input,
        public string $context,
        public string $results,
        public string $step_statuses,
        public ?string $definition_steps,
        public ?string $error,
        public ?string $created_at,
        public ?string $started_at,
        public ?string $completed_at,
    ) {}

    public static function fromObject(stdClass $data): self
    {
        return new self(
            id: (string) $data->id,
            definition_id: (string) $data->definition_id,
            definition_name: (string) $data->definition_name,
            connection: isset($data->connection) ? (string) $data->connection : null,
            status: (string) $data->status,
            current_step: isset($data->current_step) ? (string) $data->current_step : null,
            input: (string) ($data->input ?? '[]'),
            context: (string) ($data->context ?? '[]'),
            results: (string) ($data->results ?? '[]'),
            step_statuses: (string) ($data->step_statuses ?? '[]'),
            definition_steps: isset($data->definition_steps) ? (string) $data->definition_steps : null,
            error: isset($data->error) ? (string) $data->error : null,
            created_at: isset($data->created_at) ? (string) $data->created_at : null,
            started_at: isset($data->started_at) ? (string) $data->started_at : null,
            completed_at: isset($data->completed_at) ? (string) $data->completed_at : null,
        );
    }
}
