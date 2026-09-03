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
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Traits\WorkflowTransitionRequestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The admin sends every toolbar action as one PUT that carries the whole form, so these cases drive
 * the review lock and the bypass action over HTTP.
 */
#[CoversNothing]
class WorkflowTransitionRequestLockAndBypassTest extends SuluTestCase
{
    use WorkflowTransitionRequestTrait;

    private KernelBrowser $client;

    private ContentManagerInterface $contentManager;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    protected function setUp(): void
    {
        $this->client = $this->createAuthenticatedClient(
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::purgeDatabase();

        $this->contentManager = static::getContainer()->get(ContentManagerInterface::class);
        $this->workflowTransitionRequestRepository = static::getContainer()->get(WorkflowTransitionRequestRepositoryInterface::class);

        $this->authenticateAsRequestCreator();
    }

    public function testSaveDraftBlockedWhenActiveRequestExists(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->client->request(
            'PUT',
            \sprintf('/admin/api/examples/%d?locale=en', $example->getId()),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => self::REVIEW_TEMPLATE,
                'title' => 'Updated While Locked',
                'url' => '/updated-while-locked',
            ]),
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            409,
            $response->getStatusCode(),
            \sprintf(
                'Expected 409 for PUT during active request but got %d. Body: %s',
                $response->getStatusCode(),
                (string) $response->getContent(),
            ),
        );
    }

    public function testCopyLocaleIntoReviewedLocaleIsRejected(): void
    {
        $example = static::createExample([
            'en' => ['draft' => ['template' => self::REVIEW_TEMPLATE, 'title' => 'English Draft', 'url' => '/english-draft']],
            'de' => ['draft' => ['template' => self::REVIEW_TEMPLATE, 'title' => 'German Draft', 'url' => '/german-draft']],
        ]);
        static::getEntityManager()->flush();

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->client->request(
            'POST',
            \sprintf('/admin/api/examples/%d?locale=en&action=copy_locale&src=de&dest=en', $example->getId()),
        );

        $response = $this->client->getResponse();
        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());

        static::getEntityManager()->clear();
        $this->assertNotNull(
            $this->workflowTransitionRequestRepository->findOneBy([
                'resourceKey' => Example::RESOURCE_KEY,
                'resourceId' => (string) $example->getId(),
                'locale' => 'en',
                'active' => true,
            ]),
            'The refused copy must leave the review open.',
        );

        $this->client->request('GET', \sprintf('/admin/api/examples/%d?locale=en', $example->getId()));

        /** @var array{title: string} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('English Draft', $content['title'], 'The locked draft must not be overwritten.');
    }

    public function testRestoreIntoReviewedLocaleIsRejected(): void
    {
        $example = static::createExample([
            'en' => ['draft' => ['template' => self::REVIEW_TEMPLATE, 'title' => 'English Draft', 'url' => '/english-draft']],
        ]);
        static::getEntityManager()->flush();

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->client->request(
            'POST',
            \sprintf('/admin/api/examples/%d?locale=en&action=restore&version=1', $example->getId()),
        );

        $response = $this->client->getResponse();
        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());

        static::getEntityManager()->clear();
        $this->assertNotNull(
            $this->workflowTransitionRequestRepository->findOneBy([
                'resourceKey' => Example::RESOURCE_KEY,
                'resourceId' => (string) $example->getId(),
                'locale' => 'en',
                'active' => true,
            ]),
            'The refused restore must leave the review open.',
        );

        $this->client->request('GET', \sprintf('/admin/api/examples/%d?locale=en', $example->getId()));

        /** @var array{title: string} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('English Draft', $content['title'], 'The locked draft must not be overwritten.');
    }

    public function testBypassWithLivePermissionPublishesWithoutApproval(): void
    {
        // The default TestVoter grants every permission for username "test", so this exercises the
        // happy bypass path. No reviewer has approved, so the publish would normally be blocked.
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->client->request(
            'PUT',
            \sprintf('/admin/api/examples/%d?action=bypass_publish&locale=en', $example->getId()),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => self::REVIEW_TEMPLATE,
                'title' => 'Draft Title',
                'url' => '/draft-title',
            ]),
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            200,
            $response->getStatusCode(),
            \sprintf(
                'Expected 200 for bypass+publish but got %d. Body: %s',
                $response->getStatusCode(),
                (string) $response->getContent(),
            ),
        );

        $finalRequest = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => (string) $example->getId(),
            'locale' => 'en',
        ]);
        $this->assertNotNull($finalRequest, 'Request row should still exist after bypass.');
        // The bypass_publish transition runs the same publish subscriber as `publish`, so the
        // request is closed rather than left behind as stale active data.
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PUBLISHED, $finalRequest->getStatus());
        $this->assertNull($finalRequest->getActiveKey());
    }

    public function testBypassWithoutLivePermissionReturns403(): void
    {
        $this->grantTestUserViewAndEditOnly();

        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->renameTestUserTo('bypass_no_live');
        $this->client->setServerParameter('PHP_AUTH_USER', 'bypass_no_live');
        $this->client->setServerParameter('PHP_AUTH_PW', 'test');

        $this->client->request(
            'PUT',
            \sprintf('/admin/api/examples/%d?action=bypass_publish&locale=en', $example->getId()),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => self::REVIEW_TEMPLATE,
                'title' => 'Draft Title',
                'url' => '/draft-title',
            ]),
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            403,
            $response->getStatusCode(),
            \sprintf(
                'Expected 403 for bypass without LIVE permission but got %d. Body: %s',
                $response->getStatusCode(),
                (string) $response->getContent(),
            ),
        );
    }
}
