<?php

declare(strict_types=1);

namespace Elevate\SystemTests;

use Elevate\Api\Model\CreateProjectRequest;
use Elevate\Api\Model\ProjectRole;
use Elevate\Api\Model\ProjectWithPermissions;

final class ProjectsTest extends SystemTestCase
{
    public function testCreatesAProjectAndListsIt(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);
        self::assertSame([], $apiClient->projects->listProjects(self::TEAM_ID)->items);

        $created = $apiClient->projects->createProject(self::TEAM_ID, new CreateProjectRequest('Election night'));
        $list = $apiClient->projects->listProjects(self::TEAM_ID, ProjectRole::Editor, 100);

        self::assertSame('Election night', $created->project->name);
        self::assertSame(ProjectRole::Editor->value, $created->permissions->role);
        self::assertFalse($list->hasMoreAfter);
        self::assertNull($list->nextCursor);
        self::assertCount(1, $list->items);
        self::assertSame($created->project->id, $list->items[0]->project->id);
        self::assertSame('Election night', $list->items[0]->project->name);
    }

    public function testListsTheProjectsPageByPage(): void
    {
        $apiClient = $this->apiClient($this->connect()->tokens);
        $apiClient->projects->createProject(self::TEAM_ID, new CreateProjectRequest('Election night'));
        $apiClient->projects->createProject(self::TEAM_ID, new CreateProjectRequest('Budget day'));

        $first = $apiClient->projects->listProjects(self::TEAM_ID, limit: 1);
        self::assertTrue($first->hasMoreAfter);
        self::assertSame('1', $first->nextCursor);

        $second = $apiClient->projects->listProjects(self::TEAM_ID, limit: 1, after: $first->nextCursor);

        self::assertSame(['Election night'], array_map(static fn (ProjectWithPermissions $item): string => $item->project->name, $first->items));
        self::assertSame(['Budget day'], array_map(static fn (ProjectWithPermissions $item): string => $item->project->name, $second->items));
        self::assertFalse($second->hasMoreAfter);
        self::assertNull($second->nextCursor);
    }
}
