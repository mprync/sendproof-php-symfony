<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Stores;

use Psr\Container\ContainerInterface;
use Sendproof\Core\Stores\DataStore;
use Sendproof\Core\Stores\Guard;
use Sendproof\Core\Stores\GuardOnlyStore;
use Sendproof\Core\Stores\MongoDb\GuardedClient;
use Sendproof\Core\Stores\MongoDb\MongoStore;

/**
 * The data stores named in `sendproof.data_stores`, for the Preview runner and discovery.
 *
 * Each listed client service is hooked when the container builds it (a service configurator for
 * Predis and DynamoDB clients, a decorator for MongoDB clients). {@see all()} builds every listed
 * client, so a Preview starts with the hooks in place even when the handler would use a client
 * lazily.
 */
final class HookedStores
{
    /** @var list<DataStore>|null */
    private ?array $stores = null;

    /**
     * @param ContainerInterface $clients The listed client services, by service id
     * @param array<string,array<string,mixed>> $config The `data_stores` configuration
     */
    public function __construct(private readonly ContainerInterface $clients, private readonly array $config)
    {
    }

    /** @return list<DataStore> */
    public function all(): array
    {
        if ($this->stores !== null) {
            return $this->stores;
        }
        $stores = [];
        foreach ($this->config as $system => $entry) {
            $name = Guard::storeLabel($entry['name'] ?? null);
            foreach ((array) ($entry['clients'] ?? []) as $id) {
                $client = $this->clients->get($id);
                if ($system === 'mongodb') {
                    if (!$client instanceof GuardedClient) {
                        throw new \LogicException(sprintf('The MongoDB client service "%s" was not guarded; list it under sendproof.data_stores.mongodb.clients before it is built.', $id));
                    }
                    $stores[] = new MongoStore($client->hook()->engine, $name);
                }
            }
            if ($system !== 'mongodb' && ($entry['clients'] ?? []) !== []) {
                $stores[] = new GuardOnlyStore($system, $name);
            }
        }

        return $this->stores = $stores;
    }
}
