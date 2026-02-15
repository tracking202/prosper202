<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * Lightweight declarative router.
 *
 * Routes are registered as (METHOD, pattern, handler) tuples.
 * Patterns use `{name}` placeholders that match one path segment.
 * A middleware stack can be attached per-group and is executed before the handler.
 *
 * Usage:
 *   $router->add('GET', '/campaigns', fn($ctx) => $ctrl->list($ctx['params']));
 *   $router->add('GET', '/campaigns/{id}', fn($ctx) => $ctrl->get((int)$ctx['id']));
 *   $router->group('/system', [Auth::class, 'requireAdmin'], function (Router $r) { ... });
 */
final class Router
{
    /** @var array{method: string, regex: string, paramNames: string[], handler: callable, middleware: callable[]}[] */
    private array $routes = [];

    /** @var callable[] */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    public function get(string $pattern, callable $handler): self
    {
        return $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): self
    {
        return $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): self
    {
        return $this->add('PUT', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): self
    {
        return $this->add('PATCH', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): self
    {
        return $this->add('DELETE', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): self
    {
        $full = $this->groupPrefix . $pattern;

        // Convert {param} placeholders to named regex groups.
        $paramNames = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $m) use (&$paramNames): string {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $full);

        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => '#^' . $regex . '$#',
            'paramNames' => $paramNames,
            'handler'    => $handler,
            'middleware'  => $this->groupMiddleware,
        ];

        return $this;
    }

    /**
     * Register a group of routes that share a prefix and middleware stack.
     *
     * @param callable(Router): void $define
     * @param callable[]             $middleware  Callables invoked before the handler; throw to abort.
     */
    public function group(string $prefix, callable $define, array $middleware = []): self
    {
        $prevPrefix = $this->groupPrefix;
        $prevMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $prevPrefix . $prefix;
        $this->groupMiddleware = array_merge($prevMiddleware, $middleware);

        $define($this);

        $this->groupPrefix = $prevPrefix;
        $this->groupMiddleware = $prevMiddleware;

        return $this;
    }

    /**
     * Attempt to dispatch a request. Returns null if no route matches.
     *
     * @param array<string, mixed> $context  Shared context passed to the handler (params, payload, etc.)
     * @return array{handler: callable, middleware: callable[], pathParams: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        // Support PUT/PATCH on same handler by matching both when registered via put().
        foreach ($this->routes as $route) {
            $methodMatch = $route['method'] === $method
                || ($route['method'] === 'PUT' && $method === 'PATCH')
                || ($route['method'] === 'PATCH' && $method === 'PUT');

            if (!$methodMatch) {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $pathParams = [];
            foreach ($route['paramNames'] as $i => $name) {
                $pathParams[$name] = $matches[$i + 1];
            }

            return [
                'handler'    => $route['handler'],
                'middleware'  => $route['middleware'],
                'pathParams' => $pathParams,
            ];
        }

        return null;
    }
}
