<?php

declare(strict_types=1);

namespace Elevate\Auth;

/**
 * The result of a code exchange: the tokens, and the resource that the user connected.
 */
final readonly class Connection
{
    /**
     * @param string $resourceType the type of the connected resource, such as `team`
     * @param string $resourceId   the id of the connected resource
     */
    public function __construct(
        public Tokens $tokens,
        public string $resourceType,
        public string $resourceId,
    ) {
    }
}
