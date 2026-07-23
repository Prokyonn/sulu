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
use Sulu\Content\Tests\Application\WorkflowTransitionRequestEnabledKernel;
use Sulu\Content\Tests\Functional\Traits\CreateCategoryTrait;
use Sulu\Content\Tests\Functional\Traits\CreateMediaTrait;
use Sulu\Content\Tests\Functional\Traits\CreateTagTrait;
use Sulu\Content\Tests\Traits\CreateExampleTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

#[CoversNothing]
class WorkflowTransitionRequestContentNormalizationTest extends SuluTestCase
{
    use CreateCategoryTrait;
    use CreateExampleTrait;
    use CreateMediaTrait;
    use CreateTagTrait;

    private KernelBrowser $client;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    protected static function getKernelClass(): string
    {
        return WorkflowTransitionRequestEnabledKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = $this->createAuthenticatedClient(
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::purgeDatabase();
        $this->workflowTransitionRequestRepository = static::getContainer()->get(WorkflowTransitionRequestRepositoryInterface::class);
    }

    public function testGetReturnsActiveWorkflowTransitionRequestWhenPresent(): void
    {
        $example = $this->createExampleAtDraft();
        $workflowTransitionRequest = new WorkflowTransitionRequest(Example::RESOURCE_KEY, (string) $example->getId(), 'en');
        $this->workflowTransitionRequestRepository->add($workflowTransitionRequest);
        static::getEntityManager()->flush();

        $this->client->request('GET', \sprintf('/admin/api/examples/%d?locale=en', $example->getId()));
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('activeWorkflowTransitionRequest', $content);
        $this->assertNotNull($content['activeWorkflowTransitionRequest']);
        /** @var array<string, mixed> $activeRequest */
        $activeRequest = $content['activeWorkflowTransitionRequest'];
        $this->assertSame($workflowTransitionRequest->getId(), $activeRequest['id']);
        $this->assertSame('pending', $activeRequest['status']);
    }

    public function testGetReturnsNullActiveWorkflowTransitionRequestWhenAbsent(): void
    {
        $example = $this->createExampleAtDraft();

        $this->client->request('GET', \sprintf('/admin/api/examples/%d?locale=en', $example->getId()));
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('activeWorkflowTransitionRequest', $content);
        $this->assertNull($content['activeWorkflowTransitionRequest']);
    }

    private function createExampleAtDraft(): Example
    {
        $example = static::createExample(
            [
                'en' => [
                    'draft' => [
                        'template' => 'example-2',
                        'title' => 'Draft Title',
                        'url' => '/draft-title',
                    ],
                ],
            ],
            ['create_route' => true],
        );
        static::getEntityManager()->flush();

        return $example;
    }
}
