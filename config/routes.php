<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Sendproof\Core\JourneyFiles;
use Sendproof\Symfony\Controller\WorkbenchController;

/**
 * Workbench routes. Import them from `config/routes/sendproof.yaml`:
 *
 *     sendproof:
 *         resource: '@SendproofBundle/config/routes.php'
 *
 * The routes always exist, but each action answers 404 unless `sendproof.enabled` is true for the
 * current environment, so route caching never needs to know the environment.
 */
return static function (RoutingConfigurator $routes): void {
    // One route serves `/sendproof` (redirects to `/sendproof/`) and `/sendproof/` (the UI), so Symfony's own
    // trailing-slash redirect, which uses absolute URLs, never takes over.
    $routes->add('sendproof_index', '/sendproof{slash}')
        ->controller([WorkbenchController::class, 'index'])->methods(['GET'])
        ->defaults(['slash' => ''])->requirements(['slash' => '/?']);
    $routes->add('sendproof_signin', '/sendproof/signin.html')->controller([WorkbenchController::class, 'index'])->methods(['GET']);
    $routes->add('sendproof_capabilities', '/sendproof/_api/capabilities')->controller([WorkbenchController::class, 'capabilities'])->methods(['GET']);
    $routes->add('sendproof_trace', '/sendproof/_api/traces/{id}')
        ->controller([WorkbenchController::class, 'trace'])->methods(['GET'])->requirements(['id' => '[0-9a-fA-F-]{32,36}']);
    $routes->add('sendproof_journey', '/sendproof/journeys/{file}')
        ->controller([WorkbenchController::class, 'journey'])->methods(['GET'])->requirements(['file' => JourneyFiles::ROUTE_FILE]);
    $routes->add('sendproof_asset', '/sendproof/{asset}')
        ->controller([WorkbenchController::class, 'asset'])->methods(['GET'])->requirements(['asset' => '.+']);
};
