<?php

declare(strict_types=1);

namespace Elevate\SystemTests\Support;

/**
 * The SDK, the fake and the specification disagree: the SDK sent a request that the specification does not describe,
 * the fake built a response that the specification does not describe, or the specification uses a construct that the
 * fake cannot build a value for.
 */
final class ContractViolation extends \LogicException
{
}
