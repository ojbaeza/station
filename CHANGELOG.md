# Changelog

All notable changes to Station will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
