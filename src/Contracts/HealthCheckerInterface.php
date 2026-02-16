<?php

declare(strict_types=1);

namespace Station\Contracts;

use Station\DTOs\ConnectionStatus;
use Station\DTOs\HealthCheckResult;

interface HealthCheckerInterface
{
    /**
     * Check if health checking is enabled.
     */
    public function isEnabled(): bool;

    /**
     * Run all health checks.
     */
    public function check(): HealthCheckResult;

    /**
     * Check connectivity to all configured queue drivers (deep check via driver ->size()).
     *
     * @return array<string, ConnectionStatus>
     */
    public function checkConnections(): array;

    /**
     * Lightweight TCP connectivity check for dashboard polling.
     *
     * Uses fsockopen with short timeout (~2s) per driver. Indicates network
     * reachability only (port open), NOT that the queue service is healthy.
     * SQS is skipped (cloud service, no TCP check).
     *
     * @return array<string, ConnectionStatus>
     */
    public function checkConnectivityQuick(): array;

    /**
     * Check database connectivity.
     *
     * @return array{status: string, latency_ms: int, last_check: string, message?: string}
     */
    public function checkDatabase(): array;

    /**
     * Check disk space.
     *
     * @return array{status: string, used_percent: float, last_check: string}
     */
    public function checkDisk(): array;

    /**
     * Get the health check endpoint response.
     */
    public function getResponse(): HealthCheckResult;
}
