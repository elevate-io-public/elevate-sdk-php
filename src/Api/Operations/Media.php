<?php

declare(strict_types=1);

namespace Elevate\Api\Operations;

use Elevate\Api\Model\CompleteMediaIngestRequest;
use Elevate\Api\Model\CreateMediaIngestPartUrlsRequest;
use Elevate\Api\Model\CreateMediaIngestPartUrlsResponse;
use Elevate\Api\Model\InitMediaIngestRequest;
use Elevate\Api\Model\InitMediaIngestResponse;
use Elevate\Api\Model\ListMediaIngestPartsResponse;
use Elevate\Api\Model\MediaIngestStatus;
use Elevate\Api\Transport;
use Elevate\Exception\ApiException;
use Elevate\Exception\TokenRequestException;
use Elevate\Exception\TransportException;
use Elevate\Exception\UnexpectedResponseException;
use Elevate\Exception\ValidationException;

/**
 * Media ingest into the connected team.
 *
 * Generated from the OpenAPI specification. Do not edit it: run `composer generate`.
 */
final readonly class Media
{
    public function __construct(private Transport $transport)
    {
    }

    /**
     * Start ingesting one file into the team. The response carries the media ID and a presigned URL for every
     * part. PUT each part of the file to its URL, then complete the ingest.
     * elevate.io checks the user's storage quota against sizeBytes before it accepts any bytes.
     * It needs the `media:write` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function initMediaIngest(string $teamId, InitMediaIngestRequest $body): InitMediaIngestResponse
    {
        return $this->transport->call('POST', '/v1/teams/{teamId}/media/ingest-init', ['teamId' => $teamId], [], $body, InitMediaIngestResponse::class);
    }

    /**
     * Get the status of the media's ingest.
     * It needs the `media:write` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function getMediaIngestStatus(string $teamId, string $mediaId): MediaIngestStatus
    {
        return $this->transport->call('GET', '/v1/teams/{teamId}/media/{mediaId}/ingest-status', ['teamId' => $teamId, 'mediaId' => $mediaId], [], null, MediaIngestStatus::class);
    }

    /**
     * Complete the ingest once every part is uploaded. elevate.io then processes the file.
     * Poll the ingest status until it is COMPLETED or FAILED.
     * It needs the `media:write` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function completeMediaIngest(string $teamId, string $mediaId, CompleteMediaIngestRequest $body): MediaIngestStatus
    {
        return $this->transport->call('POST', '/v1/teams/{teamId}/media/{mediaId}/ingest-complete', ['teamId' => $teamId, 'mediaId' => $mediaId], [], $body, MediaIngestStatus::class);
    }

    /**
     * List the parts that elevate.io already holds for an ingest in progress.
     * A client resuming an interrupted upload skips these parts and uploads only the rest.
     * It needs the `media:write` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function listMediaIngestParts(string $teamId, string $mediaId): ListMediaIngestPartsResponse
    {
        return $this->transport->call('GET', '/v1/teams/{teamId}/media/{mediaId}/ingest-parts', ['teamId' => $teamId, 'mediaId' => $mediaId], [], null, ListMediaIngestPartsResponse::class);
    }

    /**
     * Create fresh presigned URLs for a range of parts.
     * Use it when the URLs from ingest-init expire while the upload is in progress.
     * It needs the `media:write` scope.
     *
     * @throws ApiException
     * @throws ValidationException
     * @throws TokenRequestException
     * @throws TransportException
     * @throws UnexpectedResponseException
     */
    public function createMediaIngestPartUrls(string $teamId, string $mediaId, CreateMediaIngestPartUrlsRequest $body): CreateMediaIngestPartUrlsResponse
    {
        return $this->transport->call('POST', '/v1/teams/{teamId}/media/{mediaId}/ingest-part-urls', ['teamId' => $teamId, 'mediaId' => $mediaId], [], $body, CreateMediaIngestPartUrlsResponse::class);
    }
}
