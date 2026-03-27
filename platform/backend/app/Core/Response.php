<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON HTTP response helper.
 */
final class Response
{
    private function __construct(
        private readonly mixed  $body,
        private readonly int    $status = 200,
        private readonly array  $headers = [],
    ) {}

    // ------------------------------------------------------------------
    // Factory Methods
    // ------------------------------------------------------------------

    /**
     * Create a JSON response.
     */
    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        return new self($data, $status, $headers);
    }

    /**
     * 200 OK with data payload.
     */
    public static function ok(mixed $data, string $message = 'Success'): self
    {
        return self::json(['message' => $message, 'data' => $data]);
    }

    /**
     * 201 Created.
     */
    public static function created(mixed $data, string $message = 'Created'): self
    {
        return self::json(['message' => $message, 'data' => $data], 201);
    }

    /**
     * 204 No Content.
     */
    public static function noContent(): self
    {
        return self::json(null, 204);
    }

    /**
     * Error response with structured error payload.
     */
    public static function error(string $message, int $status = 400, array $errors = []): self
    {
        $payload = ['error' => $message];
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }
        return self::json($payload, $status);
    }

    /**
     * 422 Unprocessable Entity (validation failure).
     */
    public static function validationError(array $errors): self
    {
        return self::json([
            'error'  => 'Validation failed',
            'errors' => $errors,
        ], 422);
    }

    /**
     * Paginated list response.
     */
    public static function paginated(
        array $items,
        int   $total,
        int   $page,
        int   $perPage,
    ): self {
        return self::json([
            'data' => $items,
            'meta' => [
                'total'        => $total,
                'page'         => $page,
                'per_page'     => $perPage,
                'total_pages'  => (int) ceil($total / max($perPage, 1)),
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Send
    // ------------------------------------------------------------------

    /**
     * Emit the response (status, headers, body) and terminate.
     */
    public function send(): void
    {
        http_response_code($this->status);

        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->status === 204) {
            return;
        }

        echo json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    // ------------------------------------------------------------------
    // Accessors (useful for testing)
    // ------------------------------------------------------------------

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
