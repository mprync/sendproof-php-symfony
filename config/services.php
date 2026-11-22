<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Symfony\Capture\LogProcessor;
use Sendproof\Symfony\Capture\MailListener;
use Sendproof\Symfony\Controller\WorkbenchController;
use Sendproof\Symfony\Doctrine\CaptureMiddleware;
use Sendproof\Symfony\Doctrine\DoctrinePreviewProvider;
use Sendproof\Symfony\Doctrine\OrmChangeCollector;
use Sendproof\Symfony\Http\ExecutionListener;
use Sendproof\Symfony\Http\PreviewListener;
use Sendproof\Symfony\Http\RouteCatalog;
use Sendproof\Symfony\Identity\DefaultIdentityResolver;
use Sendproof\Symfony\SendproofManager;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(Recorder::class)->tag('kernel.reset', ['method' => 'forget']);
    $services->set(OrmChangeCollector::class)
        ->tag('doctrine.event_listener', ['event' => 'onFlush'])
        ->tag('doctrine.event_listener', ['event' => 'postFlush']);
    $services->set(DefaultIdentityResolver::class);

    $services->set(CaptureMiddleware::class)
        ->args([service(Recorder::class), param('sendproof.sql_values'), param('kernel.environment'), param('sendproof.safe_procedures')])
        ->tag('doctrine.middleware');

    $services->set(DoctrinePreviewProvider::class)
        ->args([service('doctrine'), service(OrmChangeCollector::class), param('sendproof.connections'), param('sendproof.database_label')]);

    $services->set('sendproof.manager', SendproofManager::class)
        ->args([
            param('sendproof.settings'),
            param('kernel.environment'),
            param('kernel.project_dir'),
            service(Recorder::class),
            service('sendproof.identity'),
            null, // PreviewProvider: replaced by the extension according to `sendproof.provider`
            service('security.token_storage')->nullOnInvalid(),
            service('security.authorization_checker')->nullOnInvalid(),
            null, // HookedStores: set by DataStorePass when data stores are configured and enabled
        ]);
    $services->alias(SendproofManager::class, 'sendproof.manager');

    $services->set(RouteCatalog::class)->args([service('router')]);

    $services->set(PreviewListener::class)
        ->args([service('http_kernel'), service('sendproof.manager'), service(RouteCatalog::class), service('security.token_storage')->nullOnInvalid(), service('logger')->nullOnInvalid()])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'onRequest', 'priority' => 4096]);

    $services->set(ExecutionListener::class)
        ->args([service('sendproof.manager')])
        ->tag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'onController', 'priority' => -4096])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'onException', 'priority' => 4096])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'onResponse', 'priority' => 4096]);

    $services->set(WorkbenchController::class)
        ->args([service('sendproof.manager'), service(RouteCatalog::class)])
        ->public()
        ->tag('controller.service_arguments');

    if (class_exists(\Monolog\LogRecord::class)) {
        $services->set(LogProcessor::class)->args([service(Recorder::class)])->tag('monolog.processor');
    }
    if (class_exists(\Symfony\Component\Mailer\Event\MessageEvent::class)) {
        $services->set(MailListener::class)
            ->args([service(Recorder::class)])
            ->tag('kernel.event_listener', ['event' => \Symfony\Component\Mailer\Event\MessageEvent::class, 'method' => 'onMessage', 'priority' => 4096]);
    }
};
