<?php

declare(strict_types=1);

namespace Elevate\Http;

final readonly class Response
{
    /**
     * @var array<string, string> the value of each header, by name in lower case
     */
    public array $headers;

    /**
     * @param array<string, string> $headers the value of each header, by name in any case
     */
    public function __construct(
        public int $statusCode,
        array $headers,
        #[\SensitiveParameter]
        public string $body,
    ) {
        $this->headers = array_change_key_case($headers, \CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Omits the headers and the body, which can carry tokens: var_dump and print_r print what this method returns.
     *
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['statusCode' => $this->statusCode];
    }
}
