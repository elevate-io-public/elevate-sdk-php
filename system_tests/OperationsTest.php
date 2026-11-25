<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

use Elevate\Api\Model\BooleanTagValue;
use Elevate\Api\Model\ColourTagValue;
use Elevate\Api\Model\CompletedPart;
use Elevate\Api\Model\CompleteMediaIngestRequest;
use Elevate\Api\Model\Coordinates;
use Elevate\Api\Model\CoordinatesTagValue;
use Elevate\Api\Model\CreateMediaIngestPartUrlsRequest;
use Elevate\Api\Model\CreateMediaIngestPartUrlsResponse;
use Elevate\Api\Model\CreateProjectRequest;
use Elevate\Api\Model\DatetimeTagValue;
use Elevate\Api\Model\FreetextTagValue;
use Elevate\Api\Model\IngestFailure;
use Elevate\Api\Model\IngestStatus;
use Elevate\Api\Model\InitMediaIngestRequest;
use Elevate\Api\Model\InitMediaIngestResponse;
use Elevate\Api\Model\KeywordTagValue;
use Elevate\Api\Model\ListMediaIngestPartsResponse;
use Elevate\Api\Model\MediaIngestStatus;
use Elevate\Api\Model\PartUrl;
use Elevate\Api\Model\ProjectRole;
use Elevate\Api\Model\UploadedPart;
use Elevate\SystemTests\Support\Specification;

final class OperationsTest extends SystemTestCase
{
    public function testSendsEveryOperationThatTakesAnAccessTokenAsTheSpecificationDescribesIt(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);

        $apiClient->teams->getTeam(self::TEAM_ID);
        $project = $apiClient->projects->createProject(self::TEAM_ID, new CreateProjectRequest('Election night'))->project;
        $apiClient->projects->listProjects(self::TEAM_ID, ProjectRole::Viewer, 1, '0');
        $ingest = $apiClient->media->initMediaIngest(self::TEAM_ID, new InitMediaIngestRequest('clip.mp4', 12_000_000_000, $project->id, [
            'a' => new FreetextTagValue('freetext', 'a note'),
            'b' => new KeywordTagValue('keyword', ['election', 'night']),
            'c' => new DatetimeTagValue('datetime', '2027-01-15T08:00:00Z'),
            'd' => new BooleanTagValue('boolean', false),
            'e' => new ColourTagValue('colour', '#00ff7f'),
            'f' => new CoordinatesTagValue('coordinates', new Coordinates(51.5, -0.12)),
        ]));
        $urls = $apiClient->media->createMediaIngestPartUrls(self::TEAM_ID, $ingest->mediaId, new CreateMediaIngestPartUrlsRequest(1, 10_000));
        $parts = $apiClient->media->listMediaIngestParts(self::TEAM_ID, $ingest->mediaId);
        $completed = $apiClient->media->completeMediaIngest(self::TEAM_ID, $ingest->mediaId, new CompleteMediaIngestRequest([
            new CompletedPart($parts->parts[0]->partNumber, $parts->parts[0]->eTag),
        ]));
        $status = $apiClient->media->getMediaIngestStatus(self::TEAM_ID, $ingest->mediaId);
        $apiClient->auth->revokeGrant();

        // The fake numbers the integers of a response from 1 and names each string after its property.
        $partUrl = static fn (int $partNumber): PartUrl => new PartUrl($partNumber, 'https://elevate.example.test/url', 'PUT', ['key' => ['headers']]);
        $expectedStatus = new MediaIngestStatus(
            $ingest->mediaId,
            IngestStatus::AwaitingUpload->value,
            1,
            new IngestFailure('code', 'message', 'https://elevate.example.test/actionUrl'),
        );
        self::assertEquals(new InitMediaIngestResponse($ingest->mediaId, 1, 2, [$partUrl(3)]), $ingest);
        self::assertEquals(new CreateMediaIngestPartUrlsResponse([$partUrl(1)]), $urls);
        self::assertEquals(new ListMediaIngestPartsResponse(1, 2, [new UploadedPart(3, 'eTag', 4)]), $parts);
        self::assertEquals($expectedStatus, $completed);
        self::assertEquals($expectedStatus, $status);

        $team = '/v1/teams/'.self::TEAM_ID;
        $media = $team.'/media/'.$ingest->mediaId;
        self::assertSame([
            ['GET', $team, [], null],
            ['POST', $team.'/projects', [], ['name' => 'Election night']],
            ['GET', $team.'/projects', ['role' => 'VIEWER', 'limit' => '1', 'after' => '0'], null],
            ['POST', $team.'/media/ingest-init', [], [
                'filename' => 'clip.mp4',
                'sizeBytes' => 12_000_000_000,
                'projectId' => $project->id,
                'tags' => [
                    'a' => ['type' => 'freetext', 'value' => 'a note'],
                    'b' => ['type' => 'keyword', 'values' => ['election', 'night']],
                    'c' => ['type' => 'datetime', 'value' => '2027-01-15T08:00:00Z'],
                    'd' => ['type' => 'boolean', 'value' => false],
                    'e' => ['type' => 'colour', 'value' => '#00ff7f'],
                    'f' => ['type' => 'coordinates', 'value' => ['latitude' => 51.5, 'longitude' => -0.12]],
                ],
            ]],
            ['POST', $media.'/ingest-part-urls', [], ['chunkFrom' => 1, 'chunkTo' => 10_000]],
            ['GET', $media.'/ingest-parts', [], null],
            ['POST', $media.'/ingest-complete', [], ['parts' => [['partNumber' => 3, 'eTag' => 'eTag']]]],
            ['GET', $media.'/ingest-status', [], null],
            ['POST', '/v1/auth/revoke-grant', [], null],
        ], array_map(self::sent(...), \array_slice($this->elevate->requests(), 1)));

        $specification = Specification::pinned();
        $described = [];
        foreach ($specification->operations() as $operation) {
            if (null !== $specification->scopes($operation)) {
                $described[] = $operation->id;
            }
        }
        sort($described);
        $sent = array_values(array_unique($this->elevate->operations()));
        sort($sent);
        self::assertSame($described, $sent);
    }

    /**
     * @param array{method: string, uri: string, body: string} $request
     *
     * @return array{string, string, array<array-key, mixed>, mixed} the method, the path, the query and the decoded body
     */
    private static function sent(array $request): array
    {
        parse_str((string) parse_url($request['uri'], \PHP_URL_QUERY), $query);

        return [$request['method'], (string) parse_url($request['uri'], \PHP_URL_PATH), $query, json_decode($request['body'], true)];
    }
}
