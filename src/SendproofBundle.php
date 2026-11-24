<?php

declare(strict_types=1);

namespace Sendproof\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Sendproof\Symfony\DependencyInjection\DataStorePass;
use Sendproof\Symfony\DependencyInjection\SendproofExtension;

/**
 * Sendproof for Symfony: an in-app API workbench with rollback previews and observed sends.
 *
 * Register the bundle, set `sendproof.enabled`, import the workbench routes, and mark
 * operations with {@see Attribute\AllowPreview}.
 */
final class SendproofBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        // The extension alias is `sendproof`, not the `sendproof` Symfony would derive from the class name.
        return $this->extension ??= new SendproofExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DataStorePass());
    }

    public function boot(): void
    {
        if ($this->container?->has('sendproof.journey_provider')) {
            $manager = $this->container->get('sendproof.journey_manager');
            if ($manager->enabled()) {
                $this->container->get('sendproof.journey_provider')($manager);
            }
        }
    }

    public function getPath(): string
    {
        return dirname(__DIR__);
    }
}
