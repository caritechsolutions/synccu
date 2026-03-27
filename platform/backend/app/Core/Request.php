<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP Request wrapper.
 *
 * Parses JSON body, query parameters, and headers. Provides input
 * sanitisation and CSRF verification for non-API requests.
 */
final class Request
{
    private string $method;
    private string $uri;
    private array  $query;
    private array  $body;
    private array  $headers;
    private array  $server;
    private array  $routeParams = [];
    private array  $attributes  = [];

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $this->query   = $_GET;
        $this->server  = $_SERVER;
        $this->headers = $this->parseHeaders();
        $this->body    = $this->parseBody();
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function path(): string
    {
        return parse_url($this->uri, PHP_URL_PATH) ?: '/';
    }

    /**
     * Retrieve a query-string parameter.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($this->query[$key] ?? $default);
    }

    /**
     * Retrieve all query parameters.
     */
    public function queryAll(): array
    {
        return array_map([$this, 'sanitize'], $this->query);
    }

    /**
     * Retrieve a value from the parsed request body.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($this->body[$key] ?? $default);
    }

    /**
     * Retrieve the entire request body.
     */
    public function all(): array
    {
        return array_map([$this, 'sanitize'], $this->body);
    }

    /**
     * Retrieve only the specified keys from the body.
     */
    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->body)) {
                $result[$key] = $this->sanitize($this->body[$key]);
            }
        }
        return $result;
    }

    /**
     * Check whether a body key exists.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    /**
     * Get raw (unsanitized) input for cases where HTML is intentional.
     */
    public function raw(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    // ------------------------------------------------------------------
    // Headers
    // ------------------------------------------------------------------

    public function header(string $name, mixed $default = null): mixed
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function contentType(): string
    {
        return $this->header('content-type', '');
    }

    // ------------------------------------------------------------------
    // Route Parameters
    // ------------------------------------------------------------------

    public function setRouteParam(string $key, mixed $value): void
    {
        $this->routeParams[$key] = $value;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->sanitize($this->routeParams[$key] ?? $default);
    }

    public function routeParams(): array
    {
        return $this->routeParams;
    }

    // ------------------------------------------------------------------
    // Arbitrary Attributes (set by middleware)
    // ------------------------------------------------------------------

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    // ------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------

    /**
     * Verify CSRF token for non-API requests.
     *
     * API routes (prefixed with /api/) rely on bearer tokens and are exempt.
     */
    public function verifyCsrf(): bool
    {
        if (str_starts_with($this->path(), '/api/')) {
            return true;
        }

        if (in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $token = $this->header('x-csrf-token')
              ?? $this->input('_token');

        if ($token === null) {
            return false;
        }

        return verifyCsrfToken((string) $token);
    }

    // ------------------------------------------------------------------
    // Server / Client Info
    // ------------------------------------------------------------------

    public function ip(): string
    {
        return $this->server['HTTP_X_FORWARDED_FOR']
            ?? $this->server['HTTP_X_REAL_IP']
            ?? $this->server['REMOTE_ADDR']
            ?? '0.0.0.0';
    }

    public function userAgent(): string
    {
        return $this->server['HTTP_USER_AGENT'] ?? '';
    }

    public function isJson(): bool
    {
        return str_contains($this->contentType(), 'application/json');
    }

    // ------------------------------------------------------------------
    // Internal Helpers
    // ------------------------------------------------------------------

    private function parseHeaders(): array
    {
        $headers = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            foreach ($this->server as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = $value;
                }
            }
        }
        return $headers;
    }

    private function parseBody(): array
    {
        if (in_array($this->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return [];
        }

        $contentType = $this->contentType();

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if ($raw === '' || $raw === false) {
                return [];
            }
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        }

        // Fallback to $_POST for form submissions
        return $_POST;
    }

    /**
     * Sanitise a value to prevent XSS when echoed in non-API contexts.
     */
    private function sanitize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        if (is_array($value)) {
            return array_map([$this, 'sanitize'], $value);
        }

        return $value;
    }
}
