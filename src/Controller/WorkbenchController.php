<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Sendproof\Core\Capabilities;
use Sendproof\Core\Contracts\DescribesDatabaseSystem;
use Sendproof\Core\Protocol;
use Sendproof\Symfony\Http\RouteCatalog;
use Sendproof\Symfony\SendproofManager;

/** Serves the embedded workbench UI, discovery and observed-send traces under `/sendproof`. */
final class WorkbenchController
{
    public function __construct(private readonly SendproofManager $manager, private readonly RouteCatalog $catalog)
    {
    }

    /** `/sendproof/` serves the UI; `/sendproof` redirects to it, keeping the query string. */
    public function index(Request $request): Response
    {
        if (!str_ends_with($request->getPathInfo(), '/') && $request->getPathInfo() !== '/sendproof/signin.html') {
            if (!$this->open()) {
                return $this->notFound();
            }
            $query = (string) $request->server->get('QUERY_STRING', '');

            return new Response('', 302, ['Location' => $request->getBaseUrl() . '/sendproof/' . ($query === '' ? '' : '?' . $query)]);
        }

        return $this->asset('index.html');
    }

    public function asset(string $asset): Response
    {
        if (!$this->open()) {
            return $this->notFound();
        }
        $bundle = $this->manager->ui();
        if (!$bundle->isAvailable()) {
            return $this->problem(503, 'The Sendproof UI bundle is not embedded. Run `composer embed-ui` in sendproof/core.');
        }
        $found = $bundle->asset($asset);
        if ($found === null) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'X-Content-Type-Options' => 'nosniff']);
        }

        return new Response((string) file_get_contents($found->path), 200, [
            'Content-Type' => $found->contentType,
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function capabilities(Request $request): Response
    {
        if (!$this->open()) {
            return $this->notFound();
        }
        $base = $request->getBaseUrl();
        $provider = $this->manager->previewProvider();
        $available = $provider !== null && $provider->isAvailable();
        $withBase = static fn (string $url): string => Capabilities::withBase($base, $url);
        // Under a concurrent worker every opted-in operation is listed as unavailable, with the reason.
        $concurrency = $this->manager->concurrencyReason();
        $operations = $available && $concurrency === null ? $this->catalog->operations() : [];
        $unavailable = $available ? $this->catalog->unavailable() : [];
        if ($available && $concurrency !== null) {
            $unavailable = [...array_map(static fn (array $operation): array => $operation + ['reason' => $concurrency], $this->catalog->operations()), ...$unavailable];
        }

        $capabilities = Capabilities::build(
            (string) ($this->manager->setting('api_name') ?? 'API'),
            $this->manager->environment(),
            $withBase((string) $this->manager->setting('openapi_url')),
            array_map(static fn (array $document): array => ['name' => $document['name'], 'url' => $withBase((string) $document['url'])], $this->manager->setting('documents')),
            array_map($withBase, $this->manager->setting('field_modules')),
            $available ? $provider->database() : null,
            $this->manager->identity($request),
            array_map(static fn (array $entry): array => [
                'label' => $entry['label'], 'kind' => $entry['kind'], 'url' => $entry['url'] === null ? null : $withBase((string) $entry['url']),
            ], $this->manager->setting('auth_providers')),
            $operations,
            ['language' => 'php', 'framework' => 'Symfony'],
            array_map(static fn (\Sendproof\Core\Stores\DataStore $store): array => $store->describe(), $this->manager->dataStores()),
            $unavailable,
            $provider instanceof DescribesDatabaseSystem ? $provider->databaseSystem() : null,
        );

        $journeys = $this->manager->journeyFiles()->discovery($base);
        if ($journeys !== []) {
            $capabilities['journeys'] = $journeys;
        }

        return $this->json($capabilities);
    }

    public function journey(Request $request, string $file): Response
    {
        if (!$this->open()) {
            return $this->notFound();
        }
        $json = $this->manager->journeyFiles()->json($file, $request->getBaseUrl());

        return $json === null ? $this->notFound() : new Response($json, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function trace(Request $request, string $id): Response
    {
        $owner = $this->manager->ownerKey($request);
        if (!$this->open() || $owner === null
            || !Protocol::isSameOrigin($request->headers->get('Sec-Fetch-Site'), $request->headers->all('origin'), $request->getSchemeAndHttpHost())) {
            return $this->notFound();
        }
        $trace = $this->manager->traces()->get($id, $owner);

        return $trace === null ? $this->notFound() : $this->json($trace);
    }

    /** The workbench exists only when enabled for this environment and the user may use it. */
    private function open(): bool
    {
        return $this->manager->enabled() && $this->manager->authorized();
    }

    /** @param array<string,mixed> $data */
    private function json(array $data): JsonResponse
    {
        $response = new JsonResponse(null, 200, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'], false);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $response->setData($data);
    }

    private function notFound(): Response
    {
        return new Response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function problem(int $status, string $title): JsonResponse
    {
        return new JsonResponse(['title' => $title, 'status' => $status], $status, ['Cache-Control' => 'no-store']);
    }
}
