<?php

declare(strict_types=1);

namespace Sendproof\Symfony\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Sendproof\Symfony\Attribute\AllowPreview;

/** Finds the routes that opted in to Preview and matches rewritten requests against them. */
final class RouteCatalog
{
    /** @var array<string,AllowPreview|null> */
    private array $attributes = [];

    public function __construct(private readonly RouterInterface $router)
    {
    }

    /**
     * Matches a request with the application's real routes without dispatching it.
     *
     * @return array<string,mixed> Route parameters including `_route` and `_controller`
     * @throws ResourceNotFoundException
     * @throws MethodNotAllowedException
     */
    public function match(Request $request): array
    {
        // The router listener has not seen this request yet, so give the matcher its host and method.
        $this->router->getContext()->fromRequest($request);

        return $this->router->matchRequest($request);
    }

    /** @param array<string,mixed> $parameters Result of {@see match()} */
    public function isMarked(array $parameters): bool
    {
        $route = isset($parameters['_route']) ? $this->router->getRouteCollection()->get((string) $parameters['_route']) : null;

        return $route !== null && $this->marked($route);
    }

    /** @return list<array{method:string,path:string}> */
    public function operations(): array
    {
        $operations = [];
        foreach ($this->router->getRouteCollection() as $route) {
            if (!$this->marked($route)) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[$method . ' ' . $route->getPath()] = ['method' => $method, 'path' => $route->getPath()];
                }
            }
        }
        uasort($operations, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return array_values($operations);
    }

    /**
     * Operations marked `preview: false` with `#[AllowPreview(false, reason: …)]`, with the reason the UI shows.
     *
     * @return list<array{method:string,path:string,reason:string,stores?:list<string>}>
     */
    public function unavailable(): array
    {
        $operations = [];
        foreach ($this->router->getRouteCollection() as $route) {
            $mark = $this->mark($route);
            if ($mark === null || $mark->enabled) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                if ($method !== 'HEAD') {
                    $operations[$method . ' ' . $route->getPath()] = ['method' => $method, 'path' => $route->getPath(), 'reason' => (string) $mark->reason]
                        + ($mark->stores === [] ? [] : ['stores' => array_values($mark->stores)]);
                }
            }
        }
        uasort($operations, static fn (array $a, array $b): int => [$a['path'], $a['method']] <=> [$b['path'], $b['method']]);

        return array_values($operations);
    }

    private function marked(Route $route): bool
    {
        return $this->mark($route)?->enabled === true;
    }

    /** The route's Preview mark: its default, or the attribute on its controller method or class. */
    private function mark(Route $route): ?AllowPreview
    {
        $default = $route->getDefault('_sendproof_preview');
        if ($default === true) {
            return new AllowPreview();
        }
        if ($default === false) {
            return new AllowPreview(false, $route->getDefault('_sendproof_reason'), (array) ($route->getDefault('_sendproof_stores') ?? []));
        }
        $controller = $route->getDefault('_controller');

        return is_string($controller) ? $this->attribute($controller) : null;
    }

    private function attribute(string $controller): ?AllowPreview
    {
        if (!array_key_exists($controller, $this->attributes)) {
            $this->attributes[$controller] = $this->reflect($controller);
        }

        return $this->attributes[$controller];
    }

    /** The {@see AllowPreview} on `Class::method` (or an invokable class); the method's wins over the class's. */
    private function reflect(string $controller): ?AllowPreview
    {
        [$class, $method] = array_pad(explode('::', $controller, 2), 2, '__invoke');
        if (!class_exists($class)) {
            return null;
        }
        $reflection = new \ReflectionClass($class);
        if ($reflection->hasMethod($method) && ($attributes = $reflection->getMethod($method)->getAttributes(AllowPreview::class)) !== []) {
            return $attributes[0]->newInstance();
        }

        return ($attributes = $reflection->getAttributes(AllowPreview::class)) !== [] ? $attributes[0]->newInstance() : null;
    }
}
