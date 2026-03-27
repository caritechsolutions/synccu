<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight HTTP router with middleware support and route grouping.
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable, middleware: string[]}>> */
    private array $routes = [];

    /** @var string[] Current group prefix stack. */
    private array $prefixStack = [];

    /** @var string[][] Current middleware stack. */
    private array $middlewareStack = [];

    // ------------------------------------------------------------------
    // Route Registration
    // ------------------------------------------------------------------

    public function get(string $uri, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('GET', $uri, $handler, $middleware);
    }

    public function post(string $uri, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('POST', $uri, $handler, $middleware);
    }

    public function put(string $uri, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('PUT', $uri, $handler, $middleware);
    }

    public function delete(string $uri, callable|array $handler, array $middleware = []): self
    {
        return $this->addRoute('DELETE', $uri, $handler, $middleware);
    }

    /**
     * Group routes under a common prefix and/or shared middleware.
     */
    public function group(array $attributes, callable $callback): self
    {
        $prefix     = $attributes['prefix'] ?? '';
        $middleware  = $attributes['middleware'] ?? [];

        $this->prefixStack[]     = $prefix;
        $this->middlewareStack[] = $middleware;

        $callback($this);

        array_pop($this->prefixStack);
        array_pop($this->middlewareStack);

        return $this;
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    /**
     * Match the request to a registered route and execute it.
     */
    public function dispatch(Request $request): Response
    {
        $method = $request->method();
        $uri    = $this->normalizeUri($request->uri());

        // Attempt exact match first, then parameter matching
        foreach ($this->routes as $routeUri => $methods) {
            $params = $this->matchUri($routeUri, $uri);
            if ($params === null) {
                continue;
            }

            // URI matched – check method
            if (!isset($methods[$method])) {
                $allowed = implode(', ', array_keys($methods));
                return Response::json(
                    ['error' => 'Method not allowed', 'allowed' => $allowed],
                    405,
                    ['Allow' => $allowed],
                );
            }

            $route = $methods[$method];

            // Inject route params into request
            foreach ($params as $key => $value) {
                $request->setRouteParam($key, $value);
            }

            // Execute middleware chain then handler
            return $this->runMiddleware($route['middleware'], $request, function (Request $req) use ($route) {
                return $this->callHandler($route['handler'], $req);
            });
        }

        return Response::json(['error' => 'Not found'], 404);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function addRoute(string $method, string $uri, callable|array $handler, array $middleware): self
    {
        $fullUri       = $this->currentPrefix() . '/' . ltrim($uri, '/');
        $fullUri       = $this->normalizeUri($fullUri);
        $allMiddleware = array_merge($this->currentMiddleware(), $middleware);

        $this->routes[$fullUri][$method] = [
            'handler'    => $handler,
            'middleware'  => $allMiddleware,
        ];

        return $this;
    }

    private function currentPrefix(): string
    {
        return implode('', $this->prefixStack);
    }

    private function currentMiddleware(): array
    {
        return array_merge(...($this->middlewareStack ?: [[]]));
    }

    /**
     * Normalize a URI: lowercase, strip trailing slash, ensure leading slash.
     */
    private function normalizeUri(string $uri): string
    {
        $uri = parse_url($uri, PHP_URL_PATH) ?: '/';
        $uri = '/' . trim($uri, '/');
        return $uri === '' ? '/' : $uri;
    }

    /**
     * Match a registered route pattern against the actual URI.
     *
     * Patterns use `{name}` for parameters. Returns an associative array of
     * matched parameters or null if no match.
     */
    private function matchUri(string $pattern, string $uri): ?array
    {
        // Convert {param} to named regex groups
        $regex = preg_replace_callback('/\{(\w+)\}/', function (array $m) {
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $pattern);

        $regex = '#^' . $regex . '$#i';

        if (!preg_match($regex, $uri, $matches)) {
            return null;
        }

        // Extract only named groups
        return array_filter($matches, fn($k) => is_string($k), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Run middleware stack then the final handler.
     */
    private function runMiddleware(array $middleware, Request $request, callable $final): Response
    {
        if (empty($middleware)) {
            return $final($request);
        }

        $middlewareClass = array_shift($middleware);

        // Support "Class:arg1,arg2" notation for parameterised middleware
        $args = [];
        if (str_contains($middlewareClass, ':')) {
            [$middlewareClass, $argStr] = explode(':', $middlewareClass, 2);
            $args = explode(',', $argStr);
        }

        if (!class_exists($middlewareClass)) {
            throw new \RuntimeException("Middleware class not found: {$middlewareClass}");
        }

        $instance = new $middlewareClass();

        return $instance->handle($request, function (Request $req) use ($middleware, $final) {
            return $this->runMiddleware($middleware, $req, $final);
        }, ...$args);
    }

    /**
     * Invoke a route handler. Supports [ClassName, method] arrays and callables.
     */
    private function callHandler(callable|array $handler, Request $request): Response
    {
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            if (is_string($class)) {
                $class = new $class();
            }
            $result = $class->{$method}($request);
        } else {
            $result = call_user_func($handler, $request);
        }

        if ($result instanceof Response) {
            return $result;
        }

        // If handler returned an array, wrap it
        if (is_array($result)) {
            return Response::json($result);
        }

        return Response::json(['data' => $result]);
    }
}
