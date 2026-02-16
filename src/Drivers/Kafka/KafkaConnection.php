<?php

declare(strict_types=1);

namespace Station\Drivers\Kafka;

use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Producer;
use Station\Exceptions\ConnectionException;
use Throwable;

final class KafkaConnection
{
    private ?Producer $producer = null;

    private ?KafkaConsumer $consumer = null;

    /** @var array<string, bool> */
    private array $subscribedTopics = [];

    public function __construct(
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {}

    /**
     * Get the Kafka producer.
     */
    public function getProducer(): Producer
    {
        if ($this->producer === null) {
            $this->connectProducer();
        }

        if ($this->producer === null) {
            throw new ConnectionException('Failed to initialize Kafka producer');
        }

        return $this->producer;
    }

    /**
     * Get the Kafka consumer.
     */
    public function getConsumer(): KafkaConsumer
    {
        if ($this->consumer === null) {
            $this->connectConsumer();
        }

        if ($this->consumer === null) {
            throw new ConnectionException('Failed to initialize Kafka consumer');
        }

        return $this->consumer;
    }

    /**
     * Subscribe to a topic.
     */
    public function subscribe(string $topic): void
    {
        if (isset($this->subscribedTopics[$topic])) {
            return;
        }

        $consumer = $this->getConsumer();
        $currentTopics = array_keys($this->subscribedTopics);
        $currentTopics[] = $topic;

        $consumer->subscribe($currentTopics);
        $this->subscribedTopics[$topic] = true;
    }

    /**
     * Check if connected.
     */
    public function isConnected(): bool
    {
        if ($this->producer === null && $this->consumer === null) {
            return false;
        }

        try {
            if ($this->producer !== null) {
                // Flush to test connection
                $this->producer->flush(1000);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Disconnect.
     */
    public function disconnect(): void
    {
        if ($this->consumer !== null) {
            $this->consumer->unsubscribe();
        }

        $this->producer = null;
        $this->consumer = null;
        $this->subscribedTopics = [];
    }

    /**
     * Get the consumer group ID.
     */
    public function getGroupId(): string
    {
        return $this->config['group_id'] ?? 'station';
    }

    /**
     * Get the default topic.
     */
    public function getDefaultTopic(): string
    {
        return $this->config['queue'] ?? 'default';
    }

    /**
     * Get consume timeout in milliseconds.
     */
    public function getConsumeTimeout(): int
    {
        return $this->config['consume_timeout'] ?? 5000;
    }

    /**
     * Get flush timeout in milliseconds.
     */
    public function getFlushTimeout(): int
    {
        return $this->config['flush_timeout'] ?? 10000;
    }

    /**
     * Get auto commit setting.
     */
    public function isAutoCommit(): bool
    {
        return $this->config['auto_commit'] ?? false;
    }

    /**
     * Get the brokers string.
     */
    public function getBrokers(): string
    {
        return $this->config['brokers'] ?? '127.0.0.1:9092';
    }

    /**
     * Connect the producer.
     */
    private function connectProducer(): void
    {
        try {
            $conf = new Conf();
            $conf->set('metadata.broker.list', $this->getBrokers());

            // Security settings
            if (isset($this->config['security_protocol'])) {
                $conf->set('security.protocol', $this->config['security_protocol']);
            }

            if (isset($this->config['sasl_mechanisms'])) {
                $conf->set('sasl.mechanisms', $this->config['sasl_mechanisms']);
            }

            if (isset($this->config['sasl_username'])) {
                $conf->set('sasl.username', $this->config['sasl_username']);
            }

            if (isset($this->config['sasl_password'])) {
                $conf->set('sasl.password', $this->config['sasl_password']);
            }

            // Log level (0-7, default 6). Set to 0 to suppress connection errors in tests.
            $conf->set('log_level', (string) ($this->config['log_level'] ?? 6));

            // Producer settings
            $conf->set('request.required.acks', (string) ($this->config['acks'] ?? -1));
            $conf->set('message.timeout.ms', (string) ($this->config['message_timeout'] ?? 30000));

            // Idempotence for exactly-once semantics
            if ($this->config['idempotent'] ?? false) {
                $conf->set('enable.idempotence', 'true');
            }

            $this->producer = new Producer($conf);
        } catch (Throwable $e) {
            throw new ConnectionException(
                'Failed to connect Kafka producer: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Connect the consumer.
     */
    private function connectConsumer(): void
    {
        try {
            $conf = new Conf();
            $conf->set('metadata.broker.list', $this->getBrokers());
            $conf->set('group.id', $this->getGroupId());
            $conf->set('auto.offset.reset', $this->config['auto_offset_reset'] ?? 'earliest');
            $conf->set('enable.auto.commit', $this->isAutoCommit() ? 'true' : 'false');

            // Security settings
            if (isset($this->config['security_protocol'])) {
                $conf->set('security.protocol', $this->config['security_protocol']);
            }

            if (isset($this->config['sasl_mechanisms'])) {
                $conf->set('sasl.mechanisms', $this->config['sasl_mechanisms']);
            }

            if (isset($this->config['sasl_username'])) {
                $conf->set('sasl.username', $this->config['sasl_username']);
            }

            if (isset($this->config['sasl_password'])) {
                $conf->set('sasl.password', $this->config['sasl_password']);
            }

            // Log level (0-7, default 6). Set to 0 to suppress connection errors in tests.
            $conf->set('log_level', (string) ($this->config['log_level'] ?? 6));

            // Consumer settings
            $conf->set('session.timeout.ms', (string) ($this->config['session_timeout'] ?? 30000));
            $conf->set('heartbeat.interval.ms', (string) ($this->config['heartbeat_interval'] ?? 3000));

            $this->consumer = new KafkaConsumer($conf);
        } catch (Throwable $e) {
            throw new ConnectionException(
                'Failed to connect Kafka consumer: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }
}
