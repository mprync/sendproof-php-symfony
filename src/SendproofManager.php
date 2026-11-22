<?php

declare(strict_types=1);

namespace Sendproof\Symfony;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Sendproof\Core\Capture\Recorder;
use Sendproof\Core\Contracts\PreviewProvider;
use Sendproof\Core\Preview\NoSqlProvider;
use Sendproof\Core\Stores\DataStore;
use Sendproof\Core\Trace\FileTraceStore;
use Sendproof\Core\Trace\TraceOwner;
use Sendproof\Core\Trace\TraceStore;
use Sendproof\Core\Ui\UiBundle;
use Sendproof\Symfony\Identity\IdentityResolver;
use Sendproof\Symfony\Stores\HookedStores;

/**
 * Application-level services for Sendproof: opt-in, identity, authorization, provider and storage.
 */
final class SendproofManager
{
    private ?TraceStore $store = null;

    /**
     * @param array<string,mixed> $settings The processed `sendproof` configuration
     */
    public function __construct(
        private readonly array $settings,
        private readonly string $environment,
        private readonly string $projectDir,
        private readonly Recorder $recorder,
        private readonly IdentityResolver $identity,
        private readonly ?PreviewProvider $provider = null,
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?AuthorizationCheckerInterface $authorization = null,
        private readonly ?HookedStores $stores = null,
    ) {
    }

    /** True only when explicitly enabled and the kernel environment is allow-listed. */
    public function enabled(): bool
    {
        return $this->settings['enabled'] === true && in_array($this->environment, $this->settings['environments'], true);
    }

    /** Why Preview is off in this worker, shown in capabilities and in the 409 a Preview request gets. */
    public const CONCURRENT_WORKER_REASON = 'Preview unavailable: this worker runs requests concurrently (a Swoole or OpenSwoole runtime), '
        . 'so a write could not be told apart from another request\'s and rolled back with the right Preview.';

    /**
     * The reason Previews are refused in this worker, or null when they may run.
     *
     * The PHP binding attributes every write in the process to the Preview that is open, because PHP
     * normally runs one request per process at a time (php-fpm, `php -S`, RoadRunner and FrankenPHP
     * workers). A Swoole or OpenSwoole runtime (`runtime/swoole`, or any code inside a coroutine) runs
     * requests concurrently in one worker, so another request's write would be counted, guarded or
     * rolled back as the Preview's. Previews are refused there. The `concurrent_workers` setting
     * overrides the detection: true refuses, false allows (only for a server that runs one request per
     * worker at a time).
     */
    public function concurrencyReason(): ?string
    {
        $configured = $this->settings['concurrent_workers'] ?? null;
        if (is_bool($configured)) {
            return $configured ? self::CONCURRENT_WORKER_REASON : null;
        }
        $runtime = (string) ($_SERVER['APP_RUNTIME'] ?? $_ENV['APP_RUNTIME'] ?? getenv('APP_RUNTIME') ?: '');
        if (stripos($runtime, 'swoole') !== false) {
            return self::CONCURRENT_WORKER_REASON;
        }
        foreach (['Swoole\\Coroutine', 'OpenSwoole\\Coroutine'] as $coroutine) {
            if (class_exists($coroutine, false) && method_exists($coroutine, 'getCid') && $coroutine::getCid() > 0) {
                return self::CONCURRENT_WORKER_REASON;
            }
        }

        return null;
    }

    public function setting(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }

    public function environment(): string
    {
        return $this->environment;
    }

    public function provider(): ?PreviewProvider
    {
        return $this->provider;
    }

    /**
     * The provider a Preview runs on: the SQL provider, or when the application has none but hooked
     * data stores, a stand-in that rolls nothing back, so a MongoDB-only or guard-only application
     * can still Preview.
     */
    public function previewProvider(): ?PreviewProvider
    {
        return $this->provider ?? ($this->dataStores() !== [] ? new NoSqlProvider() : null);
    }

    /** @return list<DataStore> The hooked data stores; building them builds (and so hooks) every listed client */
    public function dataStores(): array
    {
        return $this->stores?->all() ?? [];
    }

    public function recorder(): Recorder
    {
        return $this->recorder;
    }

    /** @return array{name:?string,role?:?string}|null */
    public function identity(Request $request): ?array
    {
        return $this->identity->identify($request, $this->user());
    }

    /** Key that scopes traces to the authenticated subject, or null when there is no stable subject. */
    public function ownerKey(Request $request): ?string
    {
        $token = $this->tokens?->getToken();
        $user = $token?->getUser();

        if (!$user instanceof UserInterface) {
            return null;
        }
        // The firewall separates user namespaces: "alice" on an admin firewall is not "alice" on the API one.
        $firewall = method_exists($token, 'getFirewallName') ? (string) $token->getFirewallName() : '';

        return TraceOwner::key($token::class . '#' . $firewall, $user->getUserIdentifier(), $request->getBaseUrl());
    }

    /** Whether the current user may use the workbench. */
    public function authorized(): bool
    {
        $attribute = $this->settings['authorize'] ?? null;

        return $attribute === null || ($this->authorization?->isGranted($attribute) ?? false);
    }

    public function traces(): TraceStore
    {
        $trace = $this->settings['trace'];

        return $this->store ??= new FileTraceStore(
            $trace['path'] ?? $this->projectDir . '/var/sendproof-traces',
            $trace['ttl'],
            $trace['per_owner'],
            $trace['total'],
        );
    }

    public function ui(): UiBundle
    {
        $path = $this->settings['ui_path'] ?? null;

        return $path === null ? UiBundle::embedded() : new UiBundle($path);
    }

    /** Whether SQL parameter values may be shown, defaulting to dev and test only. */
    public function showSqlValues(): bool
    {
        return $this->settings['include_sql_parameter_values'] ?? in_array($this->environment, ['dev', 'test'], true);
    }

    private function user(): ?UserInterface
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }
}
