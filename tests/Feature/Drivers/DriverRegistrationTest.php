<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Drivers;

use Illuminate\Queue\QueueManager;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use Station\Drivers\Beanstalkd\BeanstalkdConnector;
use Station\Drivers\Kafka\KafkaConnector;
use Station\Drivers\RabbitMQ\RabbitMQConnector;
use Station\Drivers\Redis\RedisConnector;
use Station\Drivers\Sqs\SqsConnector;
use Station\StationServiceProvider;

class DriverRegistrationTest extends TestCase
{
    public function testRabbitMQConnectorIsRegistered(): void
    {
        $connector = $this->getRegisteredConnector('rabbitmq');

        $this->assertNotNull($connector, 'RabbitMQ connector should be registered');
        $this->assertInstanceOf(RabbitMQConnector::class, $connector());
    }

    public function testStationConnectorIsRegistered(): void
    {
        $connector = $this->getRegisteredConnector('station');

        $this->assertNotNull($connector, 'Station connector should be registered');
        $this->assertInstanceOf(RabbitMQConnector::class, $connector());
    }

    public function testRedisConnectorIsRegistered(): void
    {
        $connector = $this->getRegisteredConnector('station-redis');

        $this->assertNotNull($connector, 'Redis connector should be registered');
        $this->assertInstanceOf(RedisConnector::class, $connector());
    }

    public function testSqsConnectorIsRegistered(): void
    {
        $connector = $this->getRegisteredConnector('station-sqs');

        $this->assertNotNull($connector, 'SQS connector should be registered');
        $this->assertInstanceOf(SqsConnector::class, $connector());
    }

    public function testBeanstalkdConnectorIsRegistered(): void
    {
        $connector = $this->getRegisteredConnector('station-beanstalkd');

        $this->assertNotNull($connector, 'Beanstalkd connector should be registered');
        $this->assertInstanceOf(BeanstalkdConnector::class, $connector());
    }

    public function testKafkaConnectorIsRegistered(): void
    {
        $connector = $this->getRegisteredConnector('station-kafka');

        $this->assertNotNull($connector, 'Kafka connector should be registered');
        $this->assertInstanceOf(KafkaConnector::class, $connector());
    }

    public function testAllConnectorsHaveConnectMethod(): void
    {
        $connectors = [
            'rabbitmq' => RabbitMQConnector::class,
            'station' => RabbitMQConnector::class,
            'station-redis' => RedisConnector::class,
            'station-sqs' => SqsConnector::class,
            'station-beanstalkd' => BeanstalkdConnector::class,
            'station-kafka' => KafkaConnector::class,
        ];

        foreach ($connectors as $name => $expectedClass) {
            $connector = $this->getRegisteredConnector($name);
            $this->assertNotNull($connector, "Connector '{$name}' should be registered");

            $instance = $connector();

            $this->assertTrue(
                method_exists($instance, 'connect'),
                "Connector '{$name}' should have a connect method",
            );
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            StationServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('station.dashboard.enabled', false);
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * Get a registered connector from the QueueManager using reflection.
     */
    private function getRegisteredConnector(string $name): ?callable
    {
        $manager = $this->app->make(QueueManager::class);

        $reflection = new ReflectionClass($manager);
        $property = $reflection->getProperty('connectors');
        $property->setAccessible(true);

        $connectors = $property->getValue($manager);

        return $connectors[$name] ?? null;
    }
}
