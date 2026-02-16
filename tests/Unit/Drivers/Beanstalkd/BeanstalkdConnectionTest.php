<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Drivers\Beanstalkd;

use Pheanstalk\Contract\PheanstalkPublisherInterface;
use Pheanstalk\Pheanstalk;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Station\Drivers\Beanstalkd\BeanstalkdConnection;

/**
 * Unit tests for BeanstalkdConnection.
 *
 * Note: Pheanstalk is final and cannot be mocked with Mockery.
 * These tests use reflection to verify internal behavior without
 * requiring a real Beanstalkd server.
 */
class BeanstalkdConnectionTest extends TestCase
{
    public function testGetDefaultTubeReturnsDefault(): void
    {
        $connection = new BeanstalkdConnection([]);

        $this->assertSame('default', $connection->getDefaultTube());
    }

    public function testGetDefaultTubeReturnsConfiguredValue(): void
    {
        $connection = new BeanstalkdConnection([
            'queue' => 'custom-tube',
        ]);

        $this->assertSame('custom-tube', $connection->getDefaultTube());
    }

    public function testGetTtrReturnsDefault(): void
    {
        $connection = new BeanstalkdConnection([]);

        $this->assertSame(60, $connection->getTtr());
    }

    public function testGetTtrReturnsConfiguredValue(): void
    {
        $connection = new BeanstalkdConnection([
            'ttr' => 120,
        ]);

        $this->assertSame(120, $connection->getTtr());
    }

    public function testGetReserveTimeoutReturnsDefault(): void
    {
        $connection = new BeanstalkdConnection([]);

        $this->assertSame(5, $connection->getReserveTimeout());
    }

    public function testGetReserveTimeoutReturnsConfiguredValue(): void
    {
        $connection = new BeanstalkdConnection([
            'reserve_timeout' => 10,
        ]);

        $this->assertSame(10, $connection->getReserveTimeout());
    }

    public function testGetDefaultPriorityReturnsPheanstalkDefault(): void
    {
        $connection = new BeanstalkdConnection([]);

        $this->assertSame(PheanstalkPublisherInterface::DEFAULT_PRIORITY, $connection->getDefaultPriority());
    }

    public function testGetDefaultPriorityReturnsConfiguredValue(): void
    {
        $connection = new BeanstalkdConnection([
            'priority' => 512,
        ]);

        $this->assertSame(512, $connection->getDefaultPriority());
    }

    public function testGetRetryDelayReturnsDefault(): void
    {
        $connection = new BeanstalkdConnection([]);

        $this->assertSame(60, $connection->getRetryDelay());
    }

    public function testGetRetryDelayReturnsConfiguredValue(): void
    {
        $connection = new BeanstalkdConnection([
            'retry_delay' => 120,
        ]);

        $this->assertSame(120, $connection->getRetryDelay());
    }

    public function testIsConnectedReturnsFalseWhenNotConnected(): void
    {
        $connection = new BeanstalkdConnection([]);

        $this->assertFalse($connection->isConnected());
    }

    public function testDisconnectClearsClient(): void
    {
        $connection = new BeanstalkdConnection([]);

        // Verify disconnect sets client to null via reflection
        $reflection = new ReflectionClass($connection);
        $property = $reflection->getProperty('client');

        // Initially null
        $this->assertNull($property->getValue($connection));

        // Disconnect should keep it null
        $connection->disconnect();
        $this->assertNull($property->getValue($connection));

        // isConnected should return false
        $this->assertFalse($connection->isConnected());
    }

    public function testGetClientMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'getClient');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame(Pheanstalk::class, $reflection->getReturnType()?->getName());
    }

    public function testIsConnectedMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'isConnected');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('bool', $reflection->getReturnType()?->getName());
    }

    public function testDisconnectMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'disconnect');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('void', $reflection->getReturnType()?->getName());
    }

    public function testGetDefaultTubeMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'getDefaultTube');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('string', $reflection->getReturnType()?->getName());
    }

    public function testGetTtrMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'getTtr');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testGetReserveTimeoutMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'getReserveTimeout');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testGetDefaultPriorityMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'getDefaultPriority');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testGetRetryDelayMethodExists(): void
    {
        $reflection = new ReflectionMethod(BeanstalkdConnection::class, 'getRetryDelay');

        $this->assertTrue($reflection->isPublic());
        $this->assertSame('int', $reflection->getReturnType()?->getName());
    }

    public function testConstructorAcceptsConfig(): void
    {
        $reflection = new ReflectionClass(BeanstalkdConnection::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertCount(1, $constructor->getParameters());
        $this->assertSame('config', $constructor->getParameters()[0]->getName());
    }
}
