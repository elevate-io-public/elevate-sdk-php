<?php

declare(strict_types=1);

// The router of the PHP development server: it passes each request to FakeElevate.

use Elevate\Http\Request;
use Elevate\Http\Response;
use Elevate\SystemTests\Support\FakeElevate;
use Elevate\SystemTests\Support\Specification;
use Elevate\SystemTests\Support\State;

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';

    // Stops PHP from adding a Content-Type to a response that has none.
    ini_set('default_mimetype', '');

    $file = getenv('ELEVATE_FAKE_STATE');
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = $_SERVER['REQUEST_URI'];
    assert(is_string($file) && is_string($method) && is_string($uri));
    $body = (string) file_get_contents('php://input');

    $headers = [];
    foreach (getallheaders() as $name => $value) {
        if (is_string($value)) {
            $headers[(string) $name] = $value;
        }
    }

    $state = State::load($file);
    $state->requests[] = ['method' => $method, 'uri' => $uri, 'body' => $body];
    try {
        $response = (new FakeElevate($state, Specification::pinned()))->receive(new Request($method, $state->url.$uri, $headers, $body));
    } catch (Throwable $exception) {
        $state->violations[] = sprintf('%s %s: %s', $method, $uri, $exception->getMessage());
        $response = new Response(500, ['Content-Type' => 'text/plain'], $exception->getMessage());
    }
    $isLost = $state->lostResponses > 0;
    $state->lostResponses -= $isLost ? 1 : 0;
    // Saves before it answers: the test reads the state as soon as the response arrives.
    $state->save($file);
} catch (Throwable $exception) {
    // Writes the fault to the log of the server: the state, which carries the violations to the test, may be the fault.
    error_log((string) $exception);
    http_response_code(500);
    header('Content-Type: text/plain');
    echo $exception->getMessage();

    return;
}

http_response_code($response->statusCode);
foreach ($response->headers as $name => $value) {
    header($name.': '.$value);
}
if ($isLost) {
    // Declares more bytes than it sends: the server then closes the connection early, and the client fails.
    header('Content-Length: '.(strlen($response->body) + 1));
}
echo $response->body;
