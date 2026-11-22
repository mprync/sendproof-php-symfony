<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Stores;

use Sendproof\Core\Stores\MongoDb\MongoEngine;

/**
 * Kills the MongoDB sessions of Previews whose abort was lost, on `kernel.terminate`, after the
 * response is sent. The engine's shutdown function does this under PHP-FPM, but a long-lived worker
 * (RoadRunner, FrankenPHP, a kept kernel) runs no shutdown function between requests, so without this
 * the transaction would hold its locks until the server's lifetime limit. A kill that fails is a
 * warning in the application's logger, which {@see ClientHooks::mongodb()} sets when the client is hooked.
 *
 * {@see \Sendproof\Symfony\DependencyInjection\DataStorePass} registers it when a MongoDB client is listed.
 */
final class MongoLostSessions
{
    public function onTerminate(): void
    {
        MongoEngine::endLostTransactions();
    }
}
