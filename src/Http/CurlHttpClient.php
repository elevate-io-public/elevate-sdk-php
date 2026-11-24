<?php

declare(strict_types=1);

namespace Elevate\Http;

/**
 * Sends the requests with the curl extension.
 */
final readonly class CurlHttpClient implements HttpClient
{
    public function __construct(
        private int $timeoutSeconds = 30,
        private int $connectTimeoutSeconds = 10,
    ) {
    }

    public function send(#[\SensitiveParameter] Request $request): Response
    {
        if ('' === $request->method || '' === $request->url) {
            throw new HttpClientException('The request has no method or no URL.');
        }
        // Stops curl from waiting for "100 Continue" before it sends a body over 1024 bytes.
        $headers = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            // Refuses a line break: it would add a header to the request.
            if (1 === preg_match('/[\r\n]/', $name.$value)) {
                throw new HttpClientException(\sprintf('The request header "%s" contains a line break.', addcslashes($name, "\r\n")));
            }
            $headers[] = $name.': '.$value;
        }

        $responseHeaders = [];
        $handle = curl_init();
        curl_setopt_array($handle, [
            \CURLOPT_URL => $request->url,
            \CURLOPT_CUSTOMREQUEST => $request->method,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            \CURLOPT_TIMEOUT => $this->timeoutSeconds,
            \CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$responseHeaders): int {
                // Starts again at a status line: an interim response, such as 103, comes before the final one.
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (false !== ($colon = strpos($line, ':'))) {
                    $name = strtolower(trim(substr($line, 0, $colon)));
                    $value = trim(substr($line, $colon + 1));
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? $responseHeaders[$name].', '.$value : $value;
                }

                return \strlen($line);
            },
        ] + ('' === $request->body ? [] : [\CURLOPT_POSTFIELDS => $request->body]));

        $body = curl_exec($handle);
        if (!\is_string($body)) {
            throw new HttpClientException(\sprintf('%s (curl error %d)', curl_error($handle), curl_errno($handle)));
        }

        return new Response(curl_getinfo($handle, \CURLINFO_RESPONSE_CODE), $responseHeaders, $body);
    }
}
