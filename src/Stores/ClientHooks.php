<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Stores;

use Aws\DynamoDb\DynamoDbClient;
use MongoDB\Client as MongoClient;
use Predis\Client as PredisClient;
use Psr\Log\LoggerInterface;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Core\Stores\DynamoDb\DynamoGuard;
use Sendproof\Core\Stores\Firestore\FirestoreGuard;
use Sendproof\Core\Stores\Guard;
use Sendproof\Core\Stores\MongoDb\GuardedClient;
use Sendproof\Core\Stores\MongoDb\MongoEngine;
use Sendproof\Core\Stores\MongoDb\MongoHook;
use Sendproof\Core\Stores\Redis\PredisGuard;
use Sendproof\Core\Stores\Redis\RedisHook;
use Sendproof\Core\Stores\Search\ElasticsearchGuard;
use Sendproof\Core\Stores\Search\OpenSearchGuard;

/**
 * Installs the write guard on the client services listed in `sendproof.data_stores`. The container
 * calls these when it builds a client: {@see redis()} and {@see dynamodb()} as service
 * configurators, which hook the client in place, and {@see mongodb()} as the factory of a
 * decorator, which returns the guarded client in the original's place.
 *
 * The Elasticsearch, OpenSearch and Firestore clients are hooked in place by service configurators,
 * like DynamoDB's.
 *
 * Only commands sent through these clients are covered. A client built elsewhere, a second client
 * for the same store, and phpredis `\Redis` objects (which Symfony Cache builds itself) are not.
 */
final class ClientHooks
{
    private readonly Guard $guard;

    /**
     * @param array<string,array<string,mixed>> $config The `data_stores` configuration
     * @param ?LoggerInterface $logger The application's logger, which gets a failed MongoDB session kill's warning
     */
    public function __construct(Recorder $recorder, private readonly array $config, private readonly ?LoggerInterface $logger = null)
    {
        $this->guard = new Guard(static fn (): Recorder => $recorder);
    }

    /** Service configurator for a Predis client. */
    public function redis(PredisClient $client): void
    {
        PredisGuard::install($client, new RedisHook($this->guard, Guard::readSet($this->config['redis']['treat_as_read'] ?? null, strtoupper(...)), '0'));
    }

    /** Service configurator for an AWS SDK DynamoDB client. */
    public function dynamodb(DynamoDbClient $client): void
    {
        DynamoGuard::install($client, $this->guard, Guard::readSet($this->config['dynamodb']['treat_as_read'] ?? null));
    }

    /** Service configurator for an Elasticsearch client. */
    public function elasticsearch(\Elastic\Elasticsearch\Client $client): void
    {
        $config = $this->config['elasticsearch'] ?? [];
        ElasticsearchGuard::install($client, $this->guard, Guard::readSet($config['treat_as_read'] ?? null), $config['path_prefix'] ?? null);
    }

    /**
     * Service configurator for an OpenSearch client.
     *
     * @throws \LogicException when the client sends through a transport Sendproof can't guard, which fails the container build of that service
     */
    public function opensearch(\OpenSearch\Client $client): void
    {
        $config = $this->config['opensearch'] ?? [];
        OpenSearchGuard::install($client, $this->guard, Guard::readSet($config['treat_as_read'] ?? null), $config['path_prefix'] ?? null);
    }

    /** Service configurator for a Firestore client. */
    public function firestore(\Google\Cloud\Firestore\FirestoreClient $client): void
    {
        FirestoreGuard::install($client, $this->guard, Guard::readSet($this->config['firestore']['treat_as_read'] ?? null));
    }

    /**
     * Factory for the decorator of a MongoDB client: returns the guarded client. It also gives the
     * engine the application's logger, here and not on the first `kernel.terminate`, so a warning from
     * any kill of a lost session (the engine's shutdown function included) reaches it.
     */
    public function mongodb(MongoClient $client): GuardedClient
    {
        if ($this->logger !== null) {
            MongoEngine::useLogger($this->logger);
        }
        $config = $this->config['mongodb'] ?? [];
        $engine = new MongoEngine(
            $client->getManager(),
            $this->guard,
            Guard::readSet($config['treat_as_read'] ?? null, strtolower(...)),
            (int) ($config['preview_timeout_ms'] ?? MongoEngine::DEFAULT_TIMEOUT_MS),
        );

        return new GuardedClient($client, new MongoHook($engine));
    }
}
