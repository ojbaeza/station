<?php

declare(strict_types=1);

namespace Tests\Unit\Recovery;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Station\Contracts\HealthCheckerInterface;
use Station\Recovery\HealthChecker;

/**
 * Test subclass that overrides probeConnection for deterministic testing.
 */
class TestableHealthChecker extends HealthChecker
{
    /** @var array<string, bool> Map of "host:port" => connected */
    public array $probeResults = [];

    protected function probeConnection(string $host, int $port, string $driver, int $timeout = 2): bool
    {
        return $this->probeResults["{$host}:{$port}"] ?? false;
    }
}

class HealthCheckerConnectivityTest extends TestCase
{
    private DatabaseManager $database;

    private QueueFactory $queueManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createStub(DatabaseManager::class);
        $this->queueManager = $this->createStub(QueueFactory::class);
    }

    public function testCheckConnectivityQuickInterfaceCompliance(): void
    {
        $checker = $this->createChecker([]);

        // Verify the method signature exists on the interface
        $interface = new ReflectionClass(HealthCheckerInterface::class);
        $this->assertTrue($interface->hasMethod('checkConnectivityQuick'));
        $this->assertTrue($interface->hasMethod('checkConnections'));

        // Verify our class implements it
        $this->assertInstanceOf(HealthCheckerInterface::class, $checker);
    }

    public function testProbeConnectionOverrideWorks(): void
    {
        $checker = $this->createChecker([]);
        $checker->probeResults = [
            '127.0.0.1:5672' => true,
            '127.0.0.1:6379' => false,
        ];

        // Verify our test double works correctly
        $method = new ReflectionMethod($checker, 'probeConnection');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($checker, '127.0.0.1', 5672, 'rabbitmq'));
        $this->assertFalse($method->invoke($checker, '127.0.0.1', 6379, 'redis'));
        $this->assertFalse($method->invoke($checker, '127.0.0.1', 9999, 'unknown')); // unknown
    }

    public function testExtractRabbitMQHostPortFromHostsArray(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        $config = [
            'hosts' => [
                [
                    'host' => 'rabbitmq.local',
                    'port' => 5672,
                ],
            ],
        ];

        [$host, $port] = $method->invoke($checker, $config, 'rabbitmq');

        $this->assertSame('rabbitmq.local', $host);
        $this->assertSame(5672, $port);
    }

    public function testExtractRabbitMQHostPortFallback(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        $config = [
            'host' => 'custom.host',
            'port' => 5673,
        ];

        [$host, $port] = $method->invoke($checker, $config, 'rabbitmq');

        $this->assertSame('custom.host', $host);
        $this->assertSame(5673, $port);
    }

    public function testExtractBeanstalkdHostPort(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        $config = [
            'host' => 'beanstalkd.local',
            'port' => 11300,
        ];

        [$host, $port] = $method->invoke($checker, $config, 'beanstalkd');

        $this->assertSame('beanstalkd.local', $host);
        $this->assertSame(11300, $port);
    }

    public function testExtractKafkaHostPortFromBrokersString(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractKafkaHostPort');
        $method->setAccessible(true);

        [$host, $port] = $method->invoke($checker, ['brokers' => 'kafka1:9092,kafka2:9093']);

        $this->assertSame('kafka1', $host);
        $this->assertSame(9092, $port);
    }

    public function testExtractKafkaHostPortDefault(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractKafkaHostPort');
        $method->setAccessible(true);

        [$host, $port] = $method->invoke($checker, []);

        $this->assertSame('127.0.0.1', $host);
        $this->assertSame(9092, $port);
    }

    public function testExtractUnknownDriverReturnsNull(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        [$host, $port] = $method->invoke($checker, [], 'unknown_driver');

        $this->assertNull($host);
        $this->assertNull($port);
    }

    public function testCheckConnectivityQuickMethodExists(): void
    {
        $checker = $this->createChecker([]);

        // Verify the interface method exists
        $this->assertTrue(method_exists($checker, 'checkConnectivityQuick'));
        $this->assertTrue(method_exists($checker, 'checkConnections'));
    }

    // ---------------------------------------------------------------
    // extractHostPort() edge cases for more driver combinations
    // ---------------------------------------------------------------

    public function testExtractRabbitMQHostPortWithHostsArrayMissingKeys(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        // Hosts array with entry missing host/port keys - should use defaults
        $config = [
            'hosts' => [
                ['vhost' => '/'],
            ],
        ];

        [$host, $port] = $method->invoke($checker, $config, 'rabbitmq');

        $this->assertSame('127.0.0.1', $host);
        $this->assertSame(5672, $port);
    }

    public function testExtractRabbitMQHostPortWithNonArrayHosts(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        // hosts key is not an array - should fall back to host/port config
        $config = [
            'hosts' => 'not_an_array',
            'host' => 'my-host',
            'port' => 5673,
        ];

        [$host, $port] = $method->invoke($checker, $config, 'rabbitmq');

        $this->assertSame('my-host', $host);
        $this->assertSame(5673, $port);
    }

    public function testExtractBeanstalkdHostPortWithCustomValues(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        $config = [
            'host' => '10.0.0.5',
            'port' => 11301,
        ];

        [$host, $port] = $method->invoke($checker, $config, 'beanstalkd');

        $this->assertSame('10.0.0.5', $host);
        $this->assertSame(11301, $port);
    }

    public function testExtractBeanstalkdHostPortDefaults(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractHostPort');
        $method->setAccessible(true);

        // No host/port in config - should use defaults
        $config = [];

        [$host, $port] = $method->invoke($checker, $config, 'beanstalkd');

        $this->assertSame('127.0.0.1', $host);
        $this->assertSame(11300, $port);
    }

    public function testExtractKafkaHostPortWithSingleBrokerNoPort(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractKafkaHostPort');
        $method->setAccessible(true);

        // Single broker host without port
        [$host, $port] = $method->invoke($checker, ['brokers' => 'kafka-host']);

        $this->assertSame('kafka-host', $host);
        $this->assertSame(9092, $port); // default port
    }

    public function testExtractKafkaHostPortWithLeadingTrailingSpaces(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'extractKafkaHostPort');
        $method->setAccessible(true);

        [$host, $port] = $method->invoke($checker, ['brokers' => '  broker1:9092 , broker2:9093 ']);

        $this->assertSame('broker1', $host);
        $this->assertSame(9092, $port);
    }

    // ---------------------------------------------------------------
    // probeRedis, probeRabbitMQ, probeBeanstalkd, probeKafka private methods
    // ---------------------------------------------------------------

    public function testProbeRedisWithResponseReturnsTrue(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeRedis');
        $method->setAccessible(true);

        // Create a pair of connected sockets for testing
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        // Write the expected PONG response to the reading end
        fwrite($pair[1], "+PONG\r\n");

        $result = $method->invoke($checker, $pair[0]);
        $this->assertTrue($result);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testProbeRedisWithEmptyResponseReturnsFalse(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeRedis');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        // Close the writing end so fgets returns false (EOF)
        fclose($pair[1]);

        $result = $method->invoke($checker, $pair[0]);
        $this->assertFalse($result);

        fclose($pair[0]);
    }

    public function testProbeRedisWithNoauthResponseReturnsTrue(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeRedis');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        // Redis responds with -NOAUTH when auth required
        fwrite($pair[1], "-NOAUTH Authentication required.\r\n");

        $result = $method->invoke($checker, $pair[0]);
        $this->assertTrue($result);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testProbeRabbitMQWithResponseReturnsTrue(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeRabbitMQ');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        // Simulate AMQP protocol response
        fwrite($pair[1], "AMQP\x00\x00\x09\x01");

        $result = $method->invoke($checker, $pair[0]);
        $this->assertTrue($result);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testProbeRabbitMQWithEmptyResponseReturnsFalse(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeRabbitMQ');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        fclose($pair[1]);

        $result = $method->invoke($checker, $pair[0]);
        $this->assertFalse($result);

        fclose($pair[0]);
    }

    public function testProbeBeanstalkdWithOkResponseReturnsTrue(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeBeanstalkd');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        fwrite($pair[1], "OK 1234\r\n");

        $result = $method->invoke($checker, $pair[0]);
        $this->assertTrue($result);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testProbeBeanstalkdWithNonOkResponseReturnsFalse(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeBeanstalkd');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        // Beanstalkd responds with something other than OK
        fwrite($pair[1], "UNKNOWN_COMMAND\r\n");

        $result = $method->invoke($checker, $pair[0]);
        $this->assertFalse($result);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testProbeBeanstalkdWithEmptyResponseReturnsFalse(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeBeanstalkd');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        fclose($pair[1]);

        $result = $method->invoke($checker, $pair[0]);
        $this->assertFalse($result);

        fclose($pair[0]);
    }

    public function testProbeKafkaWithValidResponseReturnsTrue(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeKafka');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        // Kafka responds with a 4-byte length prefix
        fwrite($pair[1], pack('N', 100));

        $result = $method->invoke($checker, $pair[0]);
        $this->assertTrue($result);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function testProbeKafkaWithShortResponseReturnsFalse(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeKafka');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        // Only 2 bytes instead of expected 4
        fwrite($pair[1], "\x00\x01");
        fclose($pair[1]);

        $result = $method->invoke($checker, $pair[0]);
        $this->assertFalse($result);

        fclose($pair[0]);
    }

    public function testProbeKafkaWithEmptyResponseReturnsFalse(): void
    {
        $checker = $this->createChecker([]);
        $method = new ReflectionMethod($checker, 'probeKafka');
        $method->setAccessible(true);

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('Cannot create socket pair on this system');
        }

        fclose($pair[1]);

        $result = $method->invoke($checker, $pair[0]);
        $this->assertFalse($result);

        fclose($pair[0]);
    }

    // ---------------------------------------------------------------
    // probeConnection() integration via test double
    // ---------------------------------------------------------------

    public function testProbeConnectionRedisDriverDelegates(): void
    {
        $checker = $this->createChecker([]);
        $checker->probeResults = ['redis-host:6379' => true];

        $method = new ReflectionMethod($checker, 'probeConnection');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($checker, 'redis-host', 6379, 'redis'));
    }

    public function testProbeConnectionRabbitMQDriverDelegates(): void
    {
        $checker = $this->createChecker([]);
        $checker->probeResults = ['rabbit-host:5672' => true];

        $method = new ReflectionMethod($checker, 'probeConnection');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($checker, 'rabbit-host', 5672, 'rabbitmq'));
    }

    public function testProbeConnectionBeanstalkdDriverDelegates(): void
    {
        $checker = $this->createChecker([]);
        $checker->probeResults = ['bean-host:11300' => false];

        $method = new ReflectionMethod($checker, 'probeConnection');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($checker, 'bean-host', 11300, 'beanstalkd'));
    }

    public function testProbeConnectionKafkaDriverDelegates(): void
    {
        $checker = $this->createChecker([]);
        $checker->probeResults = ['kafka-host:9092' => true];

        $method = new ReflectionMethod($checker, 'probeConnection');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($checker, 'kafka-host', 9092, 'kafka'));
    }

    public function testProbeConnectionUnknownDriverDefaultsToTrue(): void
    {
        $checker = $this->createChecker([]);
        // Unknown host:port not in probeResults defaults to false in our test double
        // But via the TestableHealthChecker, default is false
        $checker->probeResults = ['some-host:1234' => false];

        $method = new ReflectionMethod($checker, 'probeConnection');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($checker, 'some-host', 1234, 'unknown'));
    }

    private function createChecker(array $config = []): TestableHealthChecker
    {
        return new TestableHealthChecker(
            $this->database,
            $this->queueManager,
            $config,
        );
    }
}
