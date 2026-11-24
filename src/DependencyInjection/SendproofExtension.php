<?php

declare(strict_types=1);

namespace Sendproof\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Sendproof\Core\Capture\Statements;
use Sendproof\Core\Contracts\PreviewProvider;
use Sendproof\Symfony\Doctrine\DoctrinePreviewProvider;
use Sendproof\Symfony\Identity\DefaultIdentityResolver;

/** Loads the bundle services and chooses the preview provider and identity resolver. */
final class SendproofExtension extends Extension
{
    public function getAlias(): string
    {
        return 'sendproof';
    }

    /** @param array<int,array<string,mixed>> $configs */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter('sendproof.settings', $config);
        $container->setParameter('sendproof.connections', $config['connections']);
        $container->setParameter('sendproof.database_label', $config['database_label']);
        $container->setParameter('sendproof.sql_values', $config['include_sql_parameter_values']);
        // Checked here, so a wildcard or quoted name fails the container build rather than a Preview.
        $container->setParameter('sendproof.safe_procedures', Statements::normalizeProcedures($config['safe_procedures']));

        (new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../config')))->load('services.php');
        if ($config['enabled'] && $config['journey_provider'] !== null) {
            $container->setAlias('sendproof.journey_provider', $config['journey_provider'])->setPublic(true);
            $container->setAlias('sendproof.journey_manager', 'sendproof.manager')->setPublic(true);
        }

        $provider = $config['provider'];
        if ($provider === 'doctrine') {
            $container->setAlias(PreviewProvider::class, DoctrinePreviewProvider::class);
        } elseif ($provider !== null) {
            $container->setAlias(PreviewProvider::class, (string) $provider);
        } else {
            $container->removeAlias(PreviewProvider::class);
        }

        $container->setAlias('sendproof.identity', $config['identity'] ?? DefaultIdentityResolver::class);
        $container->getAlias('sendproof.identity')->setPublic(false);
        $container->getDefinition('sendproof.manager')->replaceArgument(5,$provider === null ? null : new Reference(PreviewProvider::class));
    }
}
