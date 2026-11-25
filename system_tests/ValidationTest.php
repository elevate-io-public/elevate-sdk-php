<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

use Elevate\Api\ApiClient;
use Elevate\Api\Model\ColourTagValue;
use Elevate\Api\Model\CompleteMediaIngestRequest;
use Elevate\Api\Model\CreateProjectRequest;
use Elevate\Api\Model\FreetextTagValue;
use Elevate\Api\Model\InitMediaIngestRequest;
use Elevate\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValidationTest extends SystemTestCase
{
    /**
     * @return iterable<string, array{\Closure(ApiClient): mixed, string}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'a team id that is not a UUID' => [static fn (ApiClient $apiClient) => $apiClient->teams->getTeam('newsroom'), 'teamId'];
        yield 'a limit above the maximum' => [static fn (ApiClient $apiClient) => $apiClient->projects->listProjects(self::TEAM_ID, limit: 101), 'limit'];
        yield 'an empty project name' => [static fn (ApiClient $apiClient) => $apiClient->projects->createProject(self::TEAM_ID, new CreateProjectRequest('')), 'name'];
        yield 'an empty file' => [static fn (ApiClient $apiClient) => $apiClient->media->initMediaIngest(self::TEAM_ID, new InitMediaIngestRequest('clip.mp4', 0)), 'sizeBytes'];
        yield 'a tag of an unknown type' => [
            static fn (ApiClient $apiClient) => $apiClient->media->initMediaIngest(self::TEAM_ID, new InitMediaIngestRequest('clip.mp4', 1, tags: ['key' => new FreetextTagValue('text', 'a note')])),
            'tags.key',
        ];
        yield 'a colour outside the pattern' => [
            static fn (ApiClient $apiClient) => $apiClient->media->initMediaIngest(self::TEAM_ID, new InitMediaIngestRequest('clip.mp4', 1, tags: ['key' => new ColourTagValue('colour', 'green')])),
            'tags.key',
        ];
        yield 'a completion without parts' => [
            static fn (ApiClient $apiClient) => $apiClient->media->completeMediaIngest(self::TEAM_ID, self::MEDIA_ID, new CompleteMediaIngestRequest([])),
            'parts',
        ];
    }

    /**
     * @param \Closure(ApiClient): mixed $call
     */
    #[DataProvider('invalidRequests')]
    public function testReportsAValueThatTheSpecificationRefuses(\Closure $call, string $path): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);

        try {
            $call($apiClient);
            self::fail('The call succeeded.');
        } catch (ValidationException $exception) {
            self::assertSame([$path], array_column($exception->errors, 'path'));
        }
    }
}
