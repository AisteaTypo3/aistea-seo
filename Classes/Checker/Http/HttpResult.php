<?php
declare(strict_types=1);

namespace Aistea\AisteaSeo\Checker\Http;

final class HttpResult
{
    /**
     * @param array<string, list<string>> $headers lower-cased header names
     * @param list<array{url: string, status: int}> $redirects hops before the final URL
     */
    public function __construct(
        public readonly string $url,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $error = '',
        public readonly int $ttfbMs = 0,
        public readonly int $totalMs = 0,
        public readonly string $httpVersion = '',
        public readonly ?int $certExpires = null,
        public readonly array $redirects = [],
        public readonly bool $truncated = false,
    ) {}

    public function header(string $name): string
    {
        return implode(', ', $this->headers[strtolower($name)] ?? []);
    }

    public function ok(): bool
    {
        return $this->error === '' && $this->status >= 200 && $this->status < 300;
    }

    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function contentType(): string
    {
        return strtolower(trim(explode(';', $this->header('content-type'))[0]));
    }

    public function withRedirects(array $redirects, string $originalUrl): self
    {
        return new self(
            $originalUrl, $this->finalUrl, $this->status, $this->headers, $this->body, $this->error,
            $this->ttfbMs, $this->totalMs, $this->httpVersion, $this->certExpires, $redirects, $this->truncated,
        );
    }
}
