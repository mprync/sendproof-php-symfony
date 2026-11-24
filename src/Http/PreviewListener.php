<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Sendproof\Core\Preview\CapturedResponse;
use Sendproof\Core\Preview\ObservedRunner;
use Sendproof\Core\Preview\PreviewResult;
use Sendproof\Core\Preview\PreviewRunner;
use Sendproof\Core\Preview\PreviewState;
use Sendproof\Core\Protocol;
use Sendproof\Symfony\SendproofManager;

/**
 * Implements the Preview and Observed Send transport as the first `kernel.request` listener.
 *
 * A preview request `/sendproof/_preview/items/12` is rewritten to `/items/12` and handled by the
 * real HTTP kernel in the same process as a main request, so the firewall, access control,
 * listeners, argument resolvers and the controller all run exactly as for a normal request. The
 * result is buffered, the database transaction is rolled back, and only then is a confirmed
 * envelope built. Observed Send runs the original request the same way and adds a trace header.
 */
final class PreviewListener
{
    public const STATE = '_sendproof_state';
    public const MODE = '_sendproof_mode';

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly SendproofManager $manager,
        private readonly RouteCatalog $catalog,
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->has(self::MODE)) {
            return;
        }

        $response = $this->transport($request);
        if ($response !== null) {
            $event->setResponse($response);
        }
    }

    private function transport(Request $request): ?Response
    {
        $started = hrtime(true);
        $path = $request->getPathInfo();
        $prefer = $request->headers->all('prefer');
        $offset = Protocol::reservedOffset($path);

        if ($offset === null) {
            if ($this->manager->enabled() && !$request->isMethod('HEAD') && Protocol::requestsTrace($prefer) && $this->sameOrigin($request)) {
                $observed = $this->observe($request, $started);
                if ($observed !== null) {
                    return $observed;
                }
            }

            return Protocol::requestsRollback($prefer)
                ? $this->problem(400, 'Use the Sendproof preview URL for rollback requests.')
                : null;
        }

        if (!$this->manager->enabled()) {
            return $this->problem(404, 'Sendproof previews are unavailable.');
        }
        if (!Protocol::hasExactPreference($prefer)) {
            return $this->problem(400, 'A Sendproof preview requires Prefer: tx=rollback.');
        }
        if ($request->headers->has(Protocol::SESSION_HEADER)) {
            return new JsonResponse(Protocol::sessionNotFound(), 404, [
                'Content-Type' => 'application/problem+json',
                'Cache-Control' => 'no-store',
            ]);
        }
        if ($request->isMethod('HEAD')) {
            return $this->problem(405, 'A preview requires a response body.');
        }

        $target = substr($path, $offset + strlen(Protocol::PREFIX));
        if (!Protocol::isValidTarget($target)) {
            return $this->problem(400, 'The Sendproof preview target is invalid.');
        }

        $inner = $this->rewrite($request, substr($path, 0, $offset) . $target);
        try {
            $parameters = $this->catalog->match($inner);
        } catch (MethodNotAllowedException) {
            return $this->problem(405, 'The preview target was not found for this method.');
        } catch (ResourceNotFoundException) {
            return $this->problem(404, 'The preview target was not found for this method.');
        }
        if (!$this->catalog->isMarked($parameters)) {
            return $this->problem(409, 'This operation is unavailable for preview.');
        }
        if (($reason = $this->manager->concurrencyReason()) !== null) {
            // Refused before the controller runs, so no write of this or any other request is touched.
            return $this->problem(409, $reason);
        }
        if (!$this->sameOrigin($request)) {
            return $this->problem(403, 'This preview is forbidden.');
        }
        $provider = $this->manager->previewProvider();
        if ($provider === null || !$provider->isAvailable()) {
            return $this->problem(503, 'Preview requires one available preview provider.');
        }

        $state = new PreviewState($started);
        $inner->attributes->set(self::STATE, $state);
        $inner->attributes->set(self::MODE, 'preview');
        $runner = new PreviewRunner($this->manager->recorder(), $this->reporter());

        return $this->respond($runner->run($provider, $state, fn (): CapturedResponse => $this->capture($inner), $inner, $this->manager->dataStores()));
    }

    /** Runs an ordinary request with tracing when its route opted in; null leaves it untouched. */
    private function observe(Request $request, int $started): ?Response
    {
        try {
            $parameters = $this->catalog->match($request);
        } catch (\Throwable) {
            return null;
        }
        $provider = $this->manager->previewProvider();
        if (!$this->catalog->isMarked($parameters) || $provider === null || !$provider->isAvailable()) {
            return null;
        }

        $inner = $request->duplicate(null, null, []);
        $state = new PreviewState($started);
        $inner->attributes->set(self::STATE, $state);
        $inner->attributes->set(self::MODE, 'observe');
        $runner = new ObservedRunner($this->manager->recorder(), $this->manager->traces(), $this->reporter());
        [$response, $headers] = $runner->run(
            $provider,
            $state,
            function () use ($inner, $state): Response {
                $response = $this->kernel->handle($inner, HttpKernelInterface::MAIN_REQUEST, true);
                if (ExecutionListener::failure($inner) !== null) {
                    $state->completed = false;
                }

                return $response;
            },
            $inner,
            static fn (): ?string => $state->owner,
        );
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /** Builds the in-process request for the preview target, keeping method, headers, query and body. */
    private function rewrite(Request $request, string $path): Request
    {
        $request->getContent();
        $query = (string) $request->server->get('QUERY_STRING', '');
        $server = array_merge($request->server->all(), [
            'REQUEST_URI' => $request->getBaseUrl() . $path . ($query === '' ? '' : '?' . $query),
        ]);

        return $request->duplicate(null, null, [], null, null, $server);
    }

    /** Handles the rewritten request as a main request and buffers its response. */
    private function capture(Request $inner): CapturedResponse
    {
        try {
            $response = $this->kernel->handle($inner, HttpKernelInterface::MAIN_REQUEST, true);
        } finally {
            // The inner request authenticated its own user; do not leave that token behind.
            $this->tokens?->setToken(null);
        }

        $content = $response->getContent();
        if ($content === false) {
            throw new \RuntimeException('Sendproof previews require a buffered response.');
        }
        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', (array) $values);
        }

        return new CapturedResponse($response->getStatusCode(), $headers, $content, ExecutionListener::failure($inner));
    }

    private function respond(PreviewResult $result): Response
    {
        if ($result->blocked !== null) {
            // The write guard stopped the Preview: 409 problem+json, never a confirmation.
            $response = new JsonResponse($result->problem(), 409, ['Cache-Control' => 'no-store']);
            $response->headers->set('Content-Type', 'application/problem+json');

            return $response;
        }
        if (!$result->isConfirmed()) {
            return $this->problem($result->status, $result->title, $result->headers);
        }

        $response = new JsonResponse(null, 200, [], false);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $response->setData($result->envelope);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Preference-Applied', Protocol::ROLLBACK_PREFERENCE);
        $response->headers->set('Server-Timing', (string) $result->timing);

        return $response;
    }

    /** @param array<string,string> $headers */
    private function problem(int $status, string $title, array $headers = []): Response
    {
        $response = new JsonResponse(['title' => $title, 'status' => $status], $status, $headers + ['Cache-Control' => 'no-store']);
        $response->headers->set('Content-Type', 'application/problem+json');

        return $response;
    }

    private function sameOrigin(Request $request): bool
    {
        return Protocol::isSameOrigin(
            $request->headers->get('Sec-Fetch-Site'),
            $request->headers->all('origin'),
            $request->getSchemeAndHttpHost(),
        );
    }

    /** @return callable(\Throwable): void */
    private function reporter(): callable
    {
        return function (\Throwable $error): void {
            $this->logger?->error('A Sendproof preview failed: ' . $error->getMessage(), ['exception' => $error]);
        };
    }
}
