# Station Documentation

This directory contains additional documentation for the Station package.

## Contents

- **[architecture.md](architecture.md)** — Internal architecture with Mermaid diagrams: jobs, batches, workflows, workers, recovery, metrics, database schema, Station vs Horizon
- **[drivers.md](drivers.md)** — Feature matrix, driver-specific capabilities, per-driver configs, known limitations
- **[facades.md](facades.md)** — All 7 facades with every public method
- **[security.md](security.md)** — API auth, encryption, masking, audit logging, alerting, circuit breaker, rate limiting
- **[configuration.md](configuration.md)** — Full config reference and environment variables
- **[migration.md](migration.md)** — Migrating from Horizon: step-by-step guide and feature mapping
- **[openapi.yaml](openapi.yaml)** — OpenAPI 3.1 specification for the Station REST API

## Viewing the API Documentation

You can view the OpenAPI specification using any OpenAPI-compatible tool:

### Online Viewers
- [Swagger Editor](https://editor.swagger.io/) - Paste the contents or import the file
- [Redoc](https://redocly.github.io/redoc/) - Beautiful API documentation

### Local Development
```bash
# Using Swagger UI via Docker
docker run -p 8081:8080 -e SWAGGER_JSON=/docs/openapi.yaml -v $(pwd)/docs:/docs swaggerapi/swagger-ui

# Using Redoc via npx
npx @redocly/cli preview-docs docs/openapi.yaml
```

## Main Documentation

For complete documentation, see:
- [README.md](../README.md) - Main documentation
- [CONTRIBUTING.md](../CONTRIBUTING.md) - Contribution guidelines
- [CHANGELOG.md](../CHANGELOG.md) - Version history
- [UPGRADE.md](../UPGRADE.md) - Upgrade guides
- [SECURITY.md](../SECURITY.md) - Security policy
