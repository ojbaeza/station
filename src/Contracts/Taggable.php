<?php

declare(strict_types=1);

namespace Station\Contracts;

/**
 * Interface for jobs that provide tags for monitoring and filtering.
 */
interface Taggable
{
    /**
     * Get the tags for the job.
     *
     * Tags can be used for filtering in the dashboard and for
     * tag-based alerting rules.
     *
     * Common patterns:
     * - Entity type: 'order', 'user', 'invoice'
     * - Entity with ID: 'order:12345', 'customer:42'
     * - Key-value: 'priority:high', 'region:us-east'
     * - Action type: 'import', 'export', 'sync'
     *
     * @return array<int, string>
     */
    public function tags(): array;
}
