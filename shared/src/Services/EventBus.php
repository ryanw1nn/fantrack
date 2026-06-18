<?php

namespace SynergyERP\Shared\Services;

use Illuminate\Support\Facades\Log;
// Import PhpAmqpLib classes
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class EventBus
{
    protected $connection;
    protected $channel;
    protected $host;
    protected $port;
    protected $user;
    protected $password;
    protected $vhost;

    public function __construct(
        $host = 'event-bus',
        $port = 5672,
        $user = 'guest',
        $password = 'guest',
        $vhost = '/'
    ) {
        Log::info('[EventBus]::__construct() EventBus constructed');
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->vhost = $vhost;
    }

    public function connect()
    {
        try {
            $this->connection = new AMQPStreamConnection(
                $this->host,
                $this->port,
                $this->user,
                $this->password,
                $this->vhost,
                false,
                'AMQPLAIN',
                null,
                'en_US',
                10.0,
                10.0,
                null,
                false,
                30
            );
            $this->channel = $this->connection->channel();

            // Declare standard exchanges
            $this->declareStandardExchanges();
        } catch (\Exception $e) {
            Log::error('Failed to connect to EventBus', [
                'error' => $e->getMessage(),
                'host' => $this->host,
                'port' => $this->port
            ]);
            throw $e;
        }
    }

    /**
     * Declare standard exchanges used in the system
     */
    protected function declareStandardExchanges(): void
    {
        // Declare command exchange
        $this->channel->exchange_declare('command.exchange', 'topic', false, true, false);

        // Declare event exchange
        $this->channel->exchange_declare('event.exchange', 'topic', false, true, false);

        // Declare query exchange
        $this->channel->exchange_declare('query.exchange', 'topic', false, true, false);
    
        // Configure dead letter queue
        $this->channel->exchange_declare('dead.letter.exchange', 'topic', false, true, false);
    }

    /**
     * Tear down and re-establish the RabbitMQ connection
     */
    public function reconnect(): void
    {
        $this->disconnect();
        $this->connect();
    }

    /**
     * Close the channel and connection, nulling both properties.
     */
    public function disconnect(): void
    {
        try {
            if ($this->channel) $this->channel->close();
            if ($this->connection) $this->connection->close();
        } catch (\Exception $e) {
            // Silently handle already-closed connections
        }
        $this->channel = null;
        $this->connection = null;
    }

    /**
     * Returns true when the underlying TCP socket is still open
     */
    public function isConnected(): bool
    {
        return $this->connection && $this->connection->isConnected();
    }

    /**
     * Connect with retry logic
     */
    public function connectWithRetry(int $maxAttempts = 10, int $sleepSeconds = 5): void
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->connect();
                Log::info('[EventBus] Connected on attempt ' . $attempt);
                return;
            } catch (\Exception $e) {
                Log::warning("[EventBus] Connection attempt {$attempt}/{$maxAttempts}: " . $e->getMessage());
                if ($attempt < $maxAttempts) sleep($sleepSeconds);
            }
        }
        throw new \RuntimeException("[EventBus] Failed to connect after {$maxAttempts} attempts");
    }

    /**
     * Set QoS prefetch for consumer-side flow control.
     */
    public function setQoS(int $prefetchCount = 10): void
    {
        $this->channel->basic_qos(0, $prefetchCount, false);
    }

    /**
     * Enable AMQP publisher confirms on the current channel.
     * Must be called before publishWithConfirm()
     */
    public function enablePublisherConfirms(): void
    {
        $this->channel->confirm_select();

        $this->channel->set_nack_handler(function ($message) {
            throw new \RuntimeException(
                '[EventBus] Broker NACKed message - delivery_tag: '
                . $message->delivery_info['delivery_tag']
            );
        });
    }

    /**
     * Publish a message and wait for broker ACK (publisher confirm).
     * Throws on NACK or timeout - caller decides whether to retry.
     * 
     * @param string $exchangeName
     * @param string $routingKey
     * @param array  $message
     * @param int    $timeout  Seconds to wait for broker ACK
     * @throws \RuntimeException on NACK or timeout
     */
    public function publishWithConfirm(string $exchangeName, string $routingKey, array $message, int $timeout = 5): void
    {
        $msg = new AMQPMessage(json_encode($message), [
            'content_type'  => 'application/json',
            'delivery_mode' => 2, // persistent
            'message_id'    => uniqid('msg_'),
            'timestamp'     => time(),
        ]);

        $this->channel->basic_publish($msg, $exchangeName, $routingKey);
        $this->channel->wait_for_pending_acks($timeout);

        Log::info('Message published with confirm', [
            'exchange'      => $exchangeName,
            'routing_key'   => $routingKey,
        ]);
    }

    /**
     * Setup a queue and bind it to an exchange
     */
    public function setup_queue($queueName, $exchangeName, $routingKey = '#'): void
    {
        // Declare a durable queue
        $this->channel->queue_declare($queueName, false, true, false, false, false, new \PhpAmqpLib\Wire\AMQPTable([
            'x-dead-letter-exchange' => 'dead.letter.exchange',
            'x-dead-letter-routing-key' => 'dead.' . $queueName,
            'x-message-ttl' => 86400000,
        ]));
        // Bind the queue to the exchange
        $this->channel->queue_bind($queueName, $exchangeName, $routingKey);
    }

    /**
     * Publish a message to an exchange
     * Deprecated? We will see.
     */
    public function publish($exchangeName, $routingKey, $message, int $timeout = 5): array
    {
        $msg = new AMQPMessage(json_encode($message), [
            'content_type' => 'application/json',
            'delivery_mode' => 2, // persistent
            'message_id' => uniqid('msg_'),
            'timestamp' => time()
        ]);

        $this->channel->basic_publish($msg, $exchangeName, $routingKey);
        $this->channel->wait_for_pending_acks($timeout);

        Log::info('[EventBus] Message published with confirm', [
            'exchange' => $exchangeName,
            'routing_key' => $routingKey
        ]);

        return ['confirmed' => true];
    }

    /**
     * Publish a command to the command.exchange
     * Deprecated? We will see.
     */
    public function publish_command($routingKey, $message): array
    {
        return $this->publish('command.exchange', $routingKey, $message);
    }

    /**
     * Publish an event to the event.exchange
     * Deprecated? We will see.
     */
    public function publish_event($routingKey, $message): array
    {
        return $this->publish('event.exchange', $routingKey, $message);
    }

    /**
     * Register a consumer callback on a queue without blocking.
     * Call waitForMessages() after registering all consumers.
     */
    public function registerConsumer(string $queueName, callable $callback): void
    {
        $this->channel->basic_consume(
            $queueName,        // queue
            '',                // consumer tag
            false,             // no local
            false,             // no ack
            false,             // exclusive
            false,             // no wait
            $callback          // callback
        );
    }

    /**
     * Block and wait for messages on all registered consumers.
     * Call this after registering all consumers via registerConsumer().
     */
    public function waitForMessages(float $timeout = 0): void
    {
        while ($this->channel->is_consuming()) {
            $this->channel->wait(null, false, $timeout);
        }
    }

    /**
     * Consume messages from a queue (legacy — blocks on first call).
     * Prefer registerConsumer() + waitForMessages() for multi-queue setups.
     */
    public function consume($queueName, $callback): void
    {
        $this->registerConsumer($queueName, $callback);
        $this->waitForMessages();
    }

    /**
     * Get the underlying AMQP channel.
     */
    public function getChannel()
    {
        return $this->channel;
    }

    public function __destruct()
    {
        try {
            if ($this->channel) {
                $this->channel->close();
            }
        } catch (\Throwable) {
            // Connection already dead, ignore
        }
        try {
            if ($this->connection) {
                $this->connection->close();
            }
        } catch (\Throwable) {
            // Connection already dead, ignore
        }
    }
}