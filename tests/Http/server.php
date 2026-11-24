<?php

declare(strict_types=1);

// The router of the PHP development server that CurlHttpClientTest starts: it answers with what it received.

$uri = $_SERVER['REQUEST_URI'];
assert(is_string($uri));
parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);

if ('/redirect' === parse_url($uri, \PHP_URL_PATH)) {
    header('Location: /followed', true, 302);

    return;
}

http_response_code(is_numeric($query['status'] ?? null) ? (int) $query['status'] : 200);
header('Content-Type: application/json');
header('ETag: "the-etag"');
header('X-Repeated: first', false);
header('X-Repeated: second', false);
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $uri,
    'headers' => getallheaders(),
    'body' => file_get_contents('php://input'),
]);
