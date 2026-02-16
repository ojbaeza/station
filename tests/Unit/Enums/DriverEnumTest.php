<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Station\Enums\Driver;

class DriverEnumTest extends TestCase
{
    public static function connectorProvider(): array
    {
        return [
            'rabbitmq' => [Driver::RabbitMQ, 'rabbitmq'],
            'redis' => [Driver::Redis, 'station-redis'],
            'sqs' => [Driver::Sqs, 'station-sqs'],
            'beanstalkd' => [Driver::Beanstalkd, 'station-beanstalkd'],
            'kafka' => [Driver::Kafka, 'station-kafka'],
        ];
    }

    public static function labelProvider(): array
    {
        return [
            'rabbitmq' => [Driver::RabbitMQ, 'RabbitMQ'],
            'redis' => [Driver::Redis, 'Redis'],
            'sqs' => [Driver::Sqs, 'Amazon SQS'],
            'beanstalkd' => [Driver::Beanstalkd, 'Beanstalkd'],
            'kafka' => [Driver::Kafka, 'Apache Kafka'],
        ];
    }

    public static function isStationDriverProvider(): array
    {
        return [
            'rabbitmq value' => ['rabbitmq', true],
            'redis value' => ['redis', true],
            'sqs value' => ['sqs', true],
            'beanstalkd value' => ['beanstalkd', true],
            'kafka value' => ['kafka', true],
            'station-redis connector' => ['station-redis', true],
            'station-sqs connector' => ['station-sqs', true],
            'station-beanstalkd connector' => ['station-beanstalkd', true],
            'station-kafka connector' => ['station-kafka', true],
            'sync not station' => ['sync', false],
            'database not station' => ['database', false],
            'unknown not station' => ['unknown', false],
            'empty not station' => ['', false],
        ];
    }

    public function testValuesReturnsAllDriverStrings(): void
    {
        $values = Driver::values();

        $this->assertCount(5, $values);
        $this->assertContains('rabbitmq', $values);
        $this->assertContains('redis', $values);
        $this->assertContains('sqs', $values);
        $this->assertContains('beanstalkd', $values);
        $this->assertContains('kafka', $values);
    }

    public function testConnectorsReturnsAllConnectorNames(): void
    {
        $connectors = Driver::connectors();

        $this->assertCount(5, $connectors);
        $this->assertContains('rabbitmq', $connectors);
        $this->assertContains('station-redis', $connectors);
        $this->assertContains('station-sqs', $connectors);
        $this->assertContains('station-beanstalkd', $connectors);
        $this->assertContains('station-kafka', $connectors);
    }

    public function testValidationInReturnsCommaSeparatedValues(): void
    {
        $result = Driver::validationIn();

        $this->assertSame('rabbitmq,redis,sqs,beanstalkd,kafka', $result);
    }

    #[DataProvider('connectorProvider')]
    public function testConnectorReturnsExpectedValue(Driver $driver, string $expected): void
    {
        $this->assertSame($expected, $driver->connector());
    }

    #[DataProvider('labelProvider')]
    public function testLabelReturnsHumanReadable(Driver $driver, string $expected): void
    {
        $this->assertSame($expected, $driver->label());
    }

    #[DataProvider('isStationDriverProvider')]
    public function testIsStationDriver(string $driver, bool $expected): void
    {
        $this->assertSame($expected, Driver::isStationDriver($driver));
    }
}
