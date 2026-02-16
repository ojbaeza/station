# Upgrade Guide

This document provides instructions for upgrading Station.

## Version Compatibility

| Station | Laravel | PHP |
|---------|---------|-----|
| 0.x     | 11.x, 12.x | 8.3+ |

---

## Migrating from Laravel Horizon

See [Migrating from Horizon](docs/migration.md) for a complete migration guide.

### Quick Summary

1. Install Station alongside Horizon
2. Configure your queue connection (RabbitMQ, Redis, SQS, etc.)
3. Map Horizon supervisors to Station supervisors
4. Test with a subset of queues
5. Gradually migrate remaining queues
6. Remove Horizon

---

## Troubleshooting Upgrades

### Configuration Cache Issues

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

### Migration Conflicts

If you've modified published migrations:

```bash
# Check migration status
php artisan migrate:status

# Run specific migration
php artisan migrate --path=database/migrations/xxxx_create_station_tables.php
```

### Composer Dependency Conflicts

```bash
# Update all dependencies
composer update

# Or update only Station
composer update ojbaeza/station --with-dependencies
```

### Dashboard Not Loading After Upgrade

The dashboard requires `inertiajs/inertia-laravel`. If you use Station without the dashboard (API-only), this dependency is optional. To enable the dashboard:

```bash
composer require inertiajs/inertia-laravel

# Reinstall dashboard assets
php artisan station:install

# Rebuild frontend
npm install && npm run build
```

---

## Getting Help

- **Documentation:** [README.md](README.md)
- **Issues:** [GitHub Issues](https://github.com/ojbaeza/station/issues)
- **Discussions:** [GitHub Discussions](https://github.com/ojbaeza/station/discussions)
