<?php

declare(strict_types=1);

class HaptiqueHttpResponse
{
    private int $statusCode;
    private string $body;
    private array $headers;

    public function __construct(int $statusCode, string $body, array $headers = [])
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->headers = $headers;
    }

    public static function json(int $statusCode, $payload): self
    {
        $body = json_encode($payload);
        if ($body === false) {
            $body = json_encode(['error' => 'Failed to encode JSON response']);
            $statusCode = 500;
        }

        return new self($statusCode, (string)$body, ['Content-Type' => 'application/json']);
    }

    public static function error(int $statusCode, string $message): self
    {
        return self::json($statusCode, ['error' => $message]);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
