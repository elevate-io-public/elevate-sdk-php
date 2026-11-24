<?php

declare(strict_types=1);

namespace Elevate\Api\Model;

/**
 * - AWAITING_UPLOAD: elevate.io waits for the parts and the ingest-complete call.
 * - INGESTING: elevate.io processes the file.
 * - COMPLETED: the media is ready in the team, and in the project when the ingest named one.
 * - FAILED: the ingest failed. The failure says why.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
enum IngestStatus: string
{
    case AwaitingUpload = 'AWAITING_UPLOAD';
    case Ingesting = 'INGESTING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
}
