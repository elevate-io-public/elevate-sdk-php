<?php

declare(strict_types=1);

namespace Elevate\Http;

final readonly class Request
{
    /**
     * @param array<string, string> $headers the value of each header, by name
     */
    public function __construct(
        public string $method,
        public string $url,
        #[\SensitiveParameter]
        public array $headers = [],
        #[\SensitiveParameter]
        public string $body = '',
    ) {
    }

    public function withHeader(string $name, #[\SensitiveParameter] string $value): self
    {
        return new self($this->method, $this->url, [$name => $value] + $this->headers, $this->body);
    }

    /**
     * Omits the headers and the body, which carry credentials: var_dump and print_r print what this method returns.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['method' => $this->method, 'url' => $this->url];
    }
}
