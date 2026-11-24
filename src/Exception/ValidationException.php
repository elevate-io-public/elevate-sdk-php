<?php

declare(strict_types=1);

namespace Elevate\Exception;

/**
 * The request is invalid: elevate.io answered 400, or the SDK refused to send it. Correct the request: a retry fails
 * again.
 */
final class ValidationException extends \RuntimeException implements ElevateException
{
    /**
     * @param non-empty-list<array{code: string, path: string}> $errors each names the path in the request that is
     *                                                                  invalid, such as `teamId`, and an error code
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(\sprintf('The request is invalid: %s.', implode(', ', array_map(
            static fn (array $error): string => \sprintf('%s (%s)', $error['path'], $error['code']),
            $errors,
        ))));
    }
}
