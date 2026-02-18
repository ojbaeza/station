# Changelog

All notable changes to Station will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2026-02-18

### Added
- Prometheus metrics endpoint (`GET /metrics/prometheus`)
- Auto-scaling supervisor integration (dynamic worker pool based on queue metrics)
- Payload masking for job detail display (via `ShouldMaskPayload` interface and global config)
- Alert channel shortcode resolution (use `'slack'` instead of `'Default Slack'` in config)
- Email alert channel implementation (`StationEmailChannel`)
- 22 new test files covering dashboard controllers, workflows, scaling, telemetry, and recovery (3303 tests total)

### Changed
- Upgraded Laravel Framework to 12.52.0, PHPUnit to 12.5.14
- Upgraded Vue to 3.5, Tailwind CSS to 4.2, Vite to 7.3, Inertia.js to 2.3
- Rebuilt frontend assets with upgraded dependencies

### Fixed
- Telemetry config documentation now matches actual config structure
- Scaling strategies table corrected (removed non-existent cpu/memory/custom)
- SQS_PREFIX default value corrected in driver documentation
- RabbitMQ default credentials added to config (matches Docker Compose)
- Dashboard pages table now lists all pages (alerts, tags, audit-log, queue metrics)
- Alert API endpoints added to architecture docs and OpenAPI spec
- Auto-scaling section no longer contradicts Horizon comparison
- Documentation gaps: `station:alerts:check` command, `AlertTriggered` event, driver-time-series endpoint, alert database tables, `process_management` config, and circuit breaker `success_threshold`

## [0.1.0] - 2026-02-16

### Added
- Multi-driver queue support (RabbitMQ, Redis, SQS, Beanstalkd, Kafka)
- Worker supervisor with process management
- Job checkpointing for long-running jobs
- Stuck job detection and recovery
- Batch processing with progress tracking
- Workflow orchestration (DAG-based)
- Real-time dashboard (Inertia.js + Vue 3)
- REST API for monitoring and management
- Artisan commands for queue management
- Rate limiting and timeout middleware
- Circuit breaker pattern for resilience
- Auto-scaling support
- OpenTelemetry integration
- Comprehensive test suite (3000+ tests, 89%+ coverage)

### Security
- API authentication (Bearer token)
- Security headers middleware
- Sensitive data protection

---

## Version Compatibility

| Station | Laravel | PHP |
|---------|---------|-----|
| 0.x     | 11.x, 12.x | 8.3+ |

---

## Breaking Changes Policy

- **Major versions (x.0.0)**: May contain breaking changes. Migration guides provided.
- **Minor versions (0.x.0)**: New features, backward compatible.
- **Patch versions (0.0.x)**: Bug fixes, backward compatible.
