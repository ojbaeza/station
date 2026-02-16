<?php

declare(strict_types=1);

namespace Station\Enums;

enum Driver: string
{
    case RabbitMQ = 'rabbitmq';
    case Redis = 'redis';
    case Sqs = 'sqs';
    case Beanstalkd = 'beanstalkd';
    case Kafka = 'kafka';

    /**
     * All driver string values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * All connector names (for filtering queue connections).
     *
     * @return array<int, string>
     */
    public static function connectors(): array
    {
        return array_map(static fn(self $driver) => $driver->connector(), self::cases());
    }

    /**
     * Comma-separated values for Laravel validation rules.
     */
    public static function validationIn(): string
    {
        return implode(',', self::values());
    }

    /**
     * Check if a queue driver string corresponds to a Station driver.
     */
    public static function isStationDriver(string $driver): bool
    {
        return \in_array($driver, self::connectors(), true)
            || \in_array($driver, self::values(), true);
    }

    /**
     * The internal Laravel queue driver/connector name.
     */
    public function connector(): string
    {
        return match ($this) {
            self::RabbitMQ => 'rabbitmq',
            self::Redis => 'station-redis',
            self::Sqs => 'station-sqs',
            self::Beanstalkd => 'station-beanstalkd',
            self::Kafka => 'station-kafka',
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::RabbitMQ => 'RabbitMQ',
            self::Redis => 'Redis',
            self::Sqs => 'Amazon SQS',
            self::Beanstalkd => 'Beanstalkd',
            self::Kafka => 'Apache Kafka',
        };
    }
}
