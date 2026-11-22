<?php

declare(strict_types=1);

namespace Sendproof\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Symfony\Stores\ClientHooks;
use Sendproof\Symfony\Stores\HookedStores;
use Sendproof\Symfony\Stores\MongoLostSessions;

/**
 * Hooks the client services named in `sendproof.data_stores`, but only when Sendproof is enabled
 * for this kernel environment, so a disabled application never touches a data store client.
 *
 * Predis, DynamoDB, Elasticsearch, OpenSearch and Firestore clients get a service configurator;
 * MongoDB clients are decorated by the guarded client. A client service that is not listed is not covered.
 */
final class DataStorePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('sendproof.settings')) {
            return;
        }
        $settings = $container->getParameter('sendproof.settings');
        $stores = array_filter((array) ($settings['data_stores'] ?? []), static fn ($entry): bool => ($entry['clients'] ?? []) !== []);
        if ($stores === [] || $settings['enabled'] !== true || !in_array($container->getParameter('kernel.environment'), $settings['environments'], true)) {
            return;
        }

        $container->setDefinition(ClientHooks::class, (new Definition(ClientHooks::class, [new Reference(Recorder::class), $stores, new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)]))->setPublic(false));
        $locator = [];
        foreach ($stores as $system => $entry) {
            foreach ($entry['clients'] as $id) {
                if (!$container->hasDefinition($id) && !$container->hasAlias($id)) {
                    throw new \InvalidArgumentException(sprintf('sendproof.data_stores.%s.clients names "%s", which is not a service.', $system, $id));
                }
                $locator[$id] = new Reference($id);
                if ($system === 'mongodb') {
                    // The decorator takes the original's id, so everything that uses the client gets the guarded one.
                    $container->setDefinition($id . '.sendproof_guard', (new Definition(\Sendproof\Core\Stores\MongoDb\GuardedClient::class))
                        ->setFactory([new Reference(ClientHooks::class), 'mongodb'])
                        ->setArguments([new Reference($id . '.sendproof_guard.inner')])
                        ->setDecoratedService($id));
                } else {
                    $container->findDefinition($id)->setConfigurator([new Reference(ClientHooks::class), $system]);
                }
            }
        }
        if (isset($stores['mongodb'])) {
            // Kills the sessions of Previews whose abort was lost once each request is done. (A failed
            // kill's warning goes to the application's logger, which ClientHooks sets with the client.)
            $container->setDefinition(MongoLostSessions::class, (new Definition(MongoLostSessions::class))
                ->setPublic(false)
                ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onTerminate']));
        }
        $container->setDefinition(HookedStores::class, (new Definition(HookedStores::class, [
            new \Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument($locator), $stores,
        ]))->setPublic(false));
        $container->getDefinition('sendproof.manager')->replaceArgument(8, new Reference(HookedStores::class));
    }
}
