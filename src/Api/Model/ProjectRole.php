<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

/**
 * The user's role on a project. EDITOR includes REVIEWER, and REVIEWER includes VIEWER.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
enum ProjectRole: string
{
    case Editor = 'EDITOR';
    case Reviewer = 'REVIEWER';
    case Viewer = 'VIEWER';
}
