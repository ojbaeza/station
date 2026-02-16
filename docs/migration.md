# Migrating from Horizon

## Prerequisites

- Ensure your jobs don't depend on Horizon-specific features (Redis Lua scripts, Horizon tags via `$job->tags()`)
- Batch jobs must use Laravel's `Batchable` trait (Station enforces this)

---

## Step-by-Step

1. **Install Station alongside Horizon:**

```bash
composer require ojbaeza/station
php artisan vendor:publish --provider="Station\StationServiceProvider"
php artisan migrate
```

2. **Enable coexistence mode** (optional, for gradual migration):

```php
// config/station.php
'coexistence' => [
    'enabled' => true,
    'horizon_queues' => ['legacy-queue'],   // Keep on Horizon
    'station_queues' => ['default', 'high'], // Move to Station
],
```

3. **Configure your driver.** If staying on Redis, use `station-redis`. For other drivers, configure the appropriate connection in `config/station.php`.

4. **Update your supervisor configuration.** Replace `php artisan horizon` with `php artisan station:work` in your process manager (Supervisor, systemd, etc.).

5. **Test with a single queue first.** Start Station on one queue while keeping Horizon on others. Verify jobs are tracked in the Station dashboard at `/station`.

6. **Migrate remaining queues** and remove Horizon:

```bash
composer remove laravel/horizon
```

---

## Feature Mapping

| Horizon | Station Equivalent |
|---|---|
| `config/horizon.php` supervisors | `config/station.php` supervisors |
| `php artisan horizon` | `php artisan station:work` |
| `php artisan horizon:pause` | `php artisan station:pause {queue}` (requires queue name) |
| `php artisan horizon:continue` | `php artisan station:resume {queue}` (requires queue name) |
| `php artisan horizon:terminate` | `php artisan station:terminate` |
| `php artisan horizon:status` | `php artisan station:status` |
| `php artisan horizon:forget` | `php artisan station:flush` |
| `Horizon::auth()` | `station.dashboard.authorization` config |
| `$job->tags()` | `Station::job($job)->tags([...])` |
| `/horizon` dashboard | `/station` dashboard |
| Horizon Redis metrics | `station_metrics` table + Prometheus export |
| `HorizonServiceProvider` | `StationServiceProvider` |

---

## What Changes

- **Queue events**: No change. Station listens to the same Laravel queue events as Horizon.
- **Job classes**: No change required. Standard `dispatch()` works unchanged.
- **Batch jobs**: Must use `Batchable` trait (Laravel requirement, not Station-specific).
- **Dashboard URL**: Changes from `/horizon` to `/station` (configurable via `STATION_DASHBOARD_PATH`).
- **API**: Station provides a full REST API at `/api/station/` (Horizon has a limited internal API).
- **Metrics storage**: Moves from Redis to database (or Redis, configurable via `STATION_STORAGE_DRIVER`).
