<?php

declare(strict_types=1);

namespace Station\Contracts;

/**
 * Interface for jobs that contain sensitive data in their payload.
 *
 * Implement this interface to define which fields should be masked
 * when displaying the job payload in the dashboard or logs.
 */
interface ShouldMaskPayload
{
    /**
     * Get the fields that should be masked in the payload.
     *
     * Return an array of field names (supports dot notation for nested fields).
     *
     * @return array<int, string>
     */
    public function maskedFields(): array;
}
