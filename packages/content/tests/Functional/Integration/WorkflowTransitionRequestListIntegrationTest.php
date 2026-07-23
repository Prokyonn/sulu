<?php

declare(strict_types=1);

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Content\Tests\Functional\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Functional\Traits\CreateCategoryTrait;
use Sulu\Content\Tests\Functional\Traits\CreateMediaTrait;
use Sulu\Content\Tests\Functional\Traits\CreateTagTrait;
use Sulu\Content\Tests\Traits\CreateExampleTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

#[CoversNothing]
class WorkflowTransitionRequestListIntegrationTest extends SuluTestCase
{
    use CreateCategoryTrait;
    use CreateExampleTrait;
    use CreateMediaTrait;
    use CreateTagTrait;

    private KernelBrowser $client;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    protected function setUp(): void
    {
        $this->client = $this->createAuthenticatedClient(
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::purgeDatabase();
        $this->workflowTransitionRequestRepository = static::getContainer()->get(WorkflowTransitionRequestRepositoryInterface::class);
    }

    public function testListExposesWorkflowTransitionRequestStatusPerRow(): void
    {
        $exampleA = $this->createExampleFixture('A');
        $exampleB = $this->createExampleFixture('B');

        $this->persistRequest((string) $exampleA->getId(), 'en');
        // exampleB has no active request

        $this->client->request('GET', '/admin/api/examples?locale=en');
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array{_embedded: array{examples: list<array{title: string, workflowTransitionRequestStatus: string|null}>}} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);

        $rowsByTitle = [];
        foreach ($content['_embedded']['examples'] as $row) {
            $rowsByTitle[$row['title']] = $row;
        }

        $this->assertSame('pending', $rowsByTitle['A']['workflowTransitionRequestStatus']);
        $this->assertNull($rowsByTitle['B']['workflowTransitionRequestStatus']);
    }

    public function testFilteringByHasActiveWorkflowTransitionRequestRestrictsRows(): void
    {
        $exampleA = $this->createExampleFixture('A');
        $exampleB = $this->createExampleFixture('B');

        $this->persistRequest((string) $exampleA->getId(), 'en');

        $this->client->request('GET', '/admin/api/examples?locale=en&hasActiveWorkflowTransitionRequest=true');
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array{_embedded: array{examples: list<array<string, mixed>>}, total: int} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame(1, $content['total']);
        $this->assertCount(1, $content['_embedded']['examples']);
        $this->assertSame((int) $exampleA->getId(), $content['_embedded']['examples'][0]['id']);
        $this->assertSame('pending', $content['_embedded']['examples'][0]['workflowTransitionRequestStatus']);
    }

    public function testFilteringByHasActiveWorkflowTransitionRequestReturnsEmptyWhenNoneExist(): void
    {
        $this->createExampleFixture('A');
        $this->createExampleFixture('B');

        $this->client->request('GET', '/admin/api/examples?locale=en&hasActiveWorkflowTransitionRequest=true');
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array{_embedded: array{examples: list<array<string, mixed>>}, total: int} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->assertSame(0, $content['total']);
        $this->assertSame([], $content['_embedded']['examples']);
    }

    private function createExampleFixture(string $title): Example
    {
        $example = static::createExample(
            [
                'en' => [
                    'draft' => [
                        'template' => 'example-2',
                        'title' => $title,
                        'url' => '/' . \strtolower($title),
                    ],
                ],
            ],
            ['create_route' => true],
        );
        static::getEntityManager()->flush();

        return $example;
    }

    private function persistRequest(string $resourceId, string $locale): void
    {
        $request = new WorkflowTransitionRequest(Example::RESOURCE_KEY, $resourceId, $locale);
        $this->workflowTransitionRequestRepository->add($request);
        static::getEntityManager()->flush();
    }
}
