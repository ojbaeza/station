# Station Docker Development Environment

## Quick Start

```bash
# Start all services
docker compose up -d

# Start with debug tools (Kafka UI, Beanstalkd Console)
docker compose --profile debug up -d

# Stop all services
docker compose down

# Stop and remove volumes
docker compose down -v
```

## Services

| Service | Port | Description |
|---------|------|-------------|
| PHP | 9000 | Application container |
| RabbitMQ | 5672, 15672 | Message broker + Management UI |
| Redis | 6379 | Cache and queue driver |
| LocalStack | 4566 | AWS SQS/SNS emulation |
| Beanstalkd | 11300 | Work queue |
| Kafka | 9092 | Event streaming |
| Zookeeper | 2181 | Kafka coordination |
| MySQL | 3306 | Job history storage |

### Debug Tools (Optional)

| Service | Port | Description |
|---------|------|-------------|
| Kafka UI | 8080 | Kafka cluster management |
| Beanstalkd Console | 2080 | Beanstalkd queue viewer |

## Running Tests

```bash
# All tests
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test"

# With coverage
docker exec station_php bash -c "XDEBUG_MODE=coverage php artisan test --coverage"

# Specific driver tests
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter RabbitMQ"
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter Redis"
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter SQS"
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter Beanstalkd"
docker exec station_php bash -c "XDEBUG_MODE=off php artisan test --filter Kafka"
```

## Service URLs

- **RabbitMQ Management**: http://localhost:15672 (station/station)
- **Kafka UI**: http://localhost:8080 (debug profile only)
- **Beanstalkd Console**: http://localhost:2080 (debug profile only)

## LocalStack AWS Endpoints

```bash
# SQS endpoint
AWS_ENDPOINT=http://localhost:4566

# List queues
aws --endpoint-url=http://localhost:4566 sqs list-queues

# List SNS topics
aws --endpoint-url=http://localhost:4566 sns list-topics
```

## Environment Variables

Configure your `.env` for each driver:

```env
# RabbitMQ
RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=station
RABBITMQ_PASSWORD=station
RABBITMQ_VHOST=station

# Redis
REDIS_HOST=redis
REDIS_PORT=6379

# AWS SQS (LocalStack)
AWS_ACCESS_KEY_ID=test
AWS_SECRET_ACCESS_KEY=test
AWS_DEFAULT_REGION=us-east-1
SQS_ENDPOINT=http://localstack:4566

# Beanstalkd
BEANSTALKD_HOST=beanstalkd
BEANSTALKD_PORT=11300

# Kafka
KAFKA_BROKERS=kafka:29092

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=station
DB_USERNAME=station
DB_PASSWORD=station
```

## Health Checks

Verify each service is running correctly:

```bash
# Check all container status
docker compose ps

# RabbitMQ health
docker exec station_rabbitmq rabbitmq-diagnostics check_running
docker exec station_rabbitmq rabbitmq-diagnostics check_port_connectivity

# Redis health
docker exec station_redis redis-cli ping
# Expected: PONG

# MySQL health
docker exec station_mysql mysqladmin -ustation -pstation ping
# Expected: mysqld is alive

# Kafka health (check broker is registered)
docker exec station_kafka kafka-broker-api-versions --bootstrap-server localhost:9092

# Beanstalkd health
docker exec station_beanstalkd sh -c "echo 'stats' | nc localhost 11300 | head -5"

# LocalStack health
curl http://localhost:4566/_localstack/health
```

### Service-Specific Commands

**RabbitMQ:**
```bash
# List queues
docker exec station_rabbitmq rabbitmqctl list_queues

# List exchanges
docker exec station_rabbitmq rabbitmqctl list_exchanges

# Check consumers
docker exec station_rabbitmq rabbitmqctl list_consumers
```

**Redis:**
```bash
# Check memory usage
docker exec station_redis redis-cli info memory

# List all keys (dev only)
docker exec station_redis redis-cli keys "station:*"

# Monitor commands in real-time
docker exec station_redis redis-cli monitor
```

**MySQL:**
```bash
# Connect to database
docker exec -it station_mysql mysql -ustation -pstation station

# Check table sizes
docker exec station_mysql mysql -ustation -pstation -e "SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = 'station';"
```

## Cleanup Commands

```bash
# Stop all services
docker compose down

# Stop and remove volumes (WARNING: deletes data)
docker compose down -v

# Remove unused images
docker image prune -f

# Full cleanup (removes everything)
docker compose down -v --rmi all
```

## Troubleshooting

### RabbitMQ not starting
Check if the delayed message exchange plugin is needed:
```bash
docker exec station_rabbitmq rabbitmq-plugins enable rabbitmq_delayed_message_exchange
```

### Kafka connection issues
Ensure Zookeeper is healthy first:
```bash
docker compose logs zookeeper
```

### LocalStack SQS queues missing
Re-run the init script:
```bash
docker exec station_localstack /etc/localstack/init/ready.d/init-aws.sh
```

### Container keeps restarting
Check logs for the specific container:
```bash
docker compose logs -f <service_name>
```

### Port already in use
Find and stop the conflicting process:
```bash
# Find process using port (e.g., 5672)
lsof -i :5672

# Or change the port in docker-compose.yml
```
