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

namespace Sulu\Content\Tests\Functional\Application\ContentManager;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestInProgressException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
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
#[RunTestsInSeparateProcesses]
class WorkflowTransitionRequestAwareContentManagerTest extends SuluTestCase
{
    use CreateCategoryTrait;
    use CreateExampleTrait;
    use CreateMediaTrait;
    use CreateTagTrait;

    private KernelBrowser $client;

    private ContentManagerInterface $contentManager;

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

        $this->contentManager = static::getContainer()->get(ContentManagerInterface::class);
        $this->workflowTransitionRequestRepository = static::getContainer()->get(WorkflowTransitionRequestRepositoryInterface::class);
    }

    public function testPersistBlockedWhenActiveRequestExists(): void
    {
        $example = $this->persistExampleAtDraft();
        $this->persistActiveRequest($example, 'en');

        $this->expectException(WorkflowTransitionRequestInProgressException::class);

        $this->contentManager->persist(
            $example,
            ['template' => 'example-2', 'title' => 'Updated', 'url' => '/updated'],
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
        );
    }

    public function testPersistAllowedWithoutActiveRequest(): void
    {
        $example = $this->persistExampleAtDraft();

        $dimensionContent = $this->contentManager->persist(
            $example,
            ['template' => 'example-2', 'title' => 'Updated', 'url' => '/updated'],
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
        );

        $this->assertSame('en', $dimensionContent->getLocale());
    }

    public function testPersistAllowedForDifferentLocale(): void
    {
        $example = $this->persistExampleAtDraft();
        $this->persistActiveRequest($example, 'en');

        $dimensionContent = $this->contentManager->persist(
            $example,
            ['template' => 'example-2', 'title' => 'Draft DE', 'url' => '/draft-de'],
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'de'],
        );

        $this->assertSame('de', $dimensionContent->getLocale());
    }

    public function testPersistAllowedAfterRequestCancelled(): void
    {
        $example = $this->persistExampleAtDraft();
        $workflowTransitionRequest = $this->persistActiveRequest($example, 'en');
        $workflowTransitionRequest->cancel();
        static::getEntityManager()->flush();

        $dimensionContent = $this->contentManager->persist(
            $example,
            ['template' => 'example-2', 'title' => 'Updated', 'url' => '/updated'],
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
        );

        $this->assertSame('en', $dimensionContent->getLocale());
    }

    public function testPersistAllowedForNewEntity(): void
    {
        $example = new Example();

        $dimensionContent = $this->contentManager->persist(
            $example,
            ['template' => 'example-2', 'title' => 'New', 'url' => '/new'],
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
        );

        $this->assertSame('en', $dimensionContent->getLocale());
    }

    public function testPersistBlockedSurfacesTranslationInfoViaHttp(): void
    {
        $example = $this->persistExampleAtDraft();
        $this->persistActiveRequest($example, 'en');

        $this->client->request(
            'PUT',
            \sprintf('/admin/api/examples/%d.json?locale=en', $example->getId()),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => 'example-2',
                'title' => 'Updated',
                'url' => '/updated',
            ]),
        );

        $response = $this->client->getResponse();
        $this->assertSame(409, $response->getStatusCode());

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame(
            'An active workflow transition request exists for this content. Resolve the request before saving changes.',
            $content['detail'] ?? null,
        );
    }

    private function persistExampleAtDraft(): Example
    {
        $example = static::createExample([
            'en' => [
                'draft' => [
                    'template' => 'example-2',
                    'title' => 'Original',
                    'url' => '/original',
                ],
            ],
        ]);
        static::getEntityManager()->flush();

        return $example;
    }

    private function persistActiveRequest(Example $example, string $locale): WorkflowTransitionRequest
    {
        $workflowTransitionRequest = new WorkflowTransitionRequest(Example::RESOURCE_KEY, (string) $example->getId(), $locale);
        $this->workflowTransitionRequestRepository->add($workflowTransitionRequest);
        static::getEntityManager()->flush();

        return $workflowTransitionRequest;
    }
}
