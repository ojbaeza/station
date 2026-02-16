# Contributing to Station

Thank you for your interest in contributing to Station! This document provides guidelines and instructions for contributing.

## Requirements

- **PHP 8.3+** (8.4 recommended)
- **Laravel 11.x** or **12.x**
- **Docker** (for development environment)
- **Node.js 18+** (for dashboard assets)

## Development Environment Setup

### 1. Clone and Install

```bash
git clone https://github.com/ojbaeza/station.git
cd station
composer install
npm install
```

### 2. Start Docker Services

```bash
# Start all services
docker compose up -d

# Start with debug tools (Kafka UI, Beanstalkd Console)
docker compose --profile debug up -d
```

See [`docker/README.md`](docker/README.md) for full Docker environment documentation including service details and debug tools.

### 3. Verify Setup

```bash
# Run tests to verify everything works
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test"
```

## Code Style

Station follows **PER-CS 3.0** coding standards.

### Checking Code Style

```bash
composer cs-check
```

### Fixing Code Style

```bash
composer cs-fix
```

**Note:** Do not use Laravel Pint. Use `composer cs-fix` (PHP-CS-Fixer) instead. See `.php-cs-fixer.php` for the full configuration.

## Static Analysis

Station uses **PHPStan at level 8** with Larastan for Laravel-specific rules.

```bash
docker exec station_php bash -c "./vendor/bin/phpstan analyse"
```

## Testing

### Requirements

- **95% minimum code coverage** is required
- All new features must include tests
- All bug fixes must include regression tests

### Running Tests

```bash
# All tests
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test"

# With coverage report
docker exec station_php bash -c "XDEBUG_MODE=coverage php artisan test --coverage"

# Specific test
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter TestClassName"

# Driver-specific tests
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter RabbitMQ"
```

### Test Naming Convention

Use camelCase pattern: `test{Feature}{Scenario}{ExpectedResult}`

```php
public function testDispatchWithValidJobReturnsJobId(): void
public function testRecoveryWithStuckJobResumesSuccessfully(): void
public function testApiAuthenticationWithExpiredTokenReturns401(): void
```

### Test Structure

- **Feature tests:** Extend Laravel's `TestCase`, use `DatabaseTransactions` trait
- **Unit tests:** Extend PHPUnit's `TestCase`, use SUT pattern
- **Security tests:** Include XSS, SQL injection, CSRF, rate limiting coverage
- **Data providers:** Use descriptive dataset keys

## Pull Request Process

### Before Submitting

1. **Create a feature branch** from `main`
   ```bash
   git checkout -b feature/my-feature
   ```

2. **Write tests** for your changes

3. **Ensure all checks pass:**
   ```bash
   composer cs-fix
   docker exec station_php bash -c "./vendor/bin/phpstan analyse"
   docker exec station_php bash -c "XDEBUG_MODE=off php artisan test"
   ```

4. **Update documentation** if your changes affect public APIs

### Submitting

1. Push your branch to your fork
2. Open a Pull Request against `main`
3. Fill out the PR template completely
4. Wait for CI checks to pass
5. Request review from maintainers

### PR Guidelines

- Keep PRs focused and reasonably sized
- One feature or fix per PR
- Write clear commit messages
- Reference related issues (e.g., "Fixes #123")
- Update CHANGELOG.md for user-facing changes

## Commit Messages

Follow conventional commit format:

```
type(scope): description

[optional body]

[optional footer]
```

Types:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `style`: Code style (formatting, no logic change)
- `refactor`: Code refactoring
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

Examples:
```
feat(driver): add Kafka consumer group support
fix(recovery): handle checkpoint corruption gracefully
docs(readme): add troubleshooting section
```

## Architecture Guidelines

### Directory Structure

```
src/
├── Commands/           # Artisan commands
├── Contracts/          # Interfaces
├── Core/               # JobManager, WorkerSupervisor, etc.
├── Drivers/            # Queue driver implementations
├── Facades/            # Laravel facades
├── Middleware/         # Job middleware
├── Recovery/           # Stuck job detection, checkpointing
├── Dashboard/          # Controllers, Pages, API
└── Testing/            # Test utilities
```

### Key Principles

- **Dependency Injection:** Use Laravel's service container
- **Interface-First:** Define contracts before implementations
- **Driver Abstraction:** All drivers implement `DriverInterface`
- **Best-Effort Compatibility:** Features may vary by driver capability

### Adding a New Driver

1. Create driver class in `src/Drivers/`
2. Implement `Station\Contracts\DriverInterface`
3. Register in `StationServiceProvider`
4. Add configuration schema
5. Write comprehensive tests
6. Update Feature Matrix documentation

## Getting Help

- **Questions:** Open a GitHub Discussion
- **Bug Reports:** Open a GitHub Issue with reproduction steps
- **Feature Requests:** Open a GitHub Issue with use case description

## Code of Conduct

This project follows our [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to uphold this code.

**In short:** Be respectful, inclusive, and constructive. We're building something together.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.
