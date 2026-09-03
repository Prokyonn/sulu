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

namespace Sulu\Content\Tests\Functional\Application\ContentWorkflow;

use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Exception\DuplicateActiveWorkflowTransitionRequestException;
use Sulu\Content\Domain\Exception\NoRequestWorkflowException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestCancelNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Traits\WorkflowTransitionRequestTrait;

#[CoversNothing]
class WorkflowTransitionRequestWorkflowTest extends SuluTestCase
{
    use WorkflowTransitionRequestTrait;

    private ContentManagerInterface $contentManager;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    protected function setUp(): void
    {
        self::purgeDatabase();

        $this->contentManager = static::getContainer()->get(ContentManagerInterface::class);
        $this->workflowTransitionRequestRepository = static::getContainer()->get(WorkflowTransitionRequestRepositoryInterface::class);
        $this->authenticateAsRequestCreator();
    }

    public function testRequestForReviewDraftCreatesPendingRequest(): void
    {
        $example = $this->createExampleAtDraft();

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $request = $this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => (string) $example->getId(),
            'locale' => 'en',
            'active' => true,
        ]);

        $this->assertNotNull($request);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
    }

    public function testRequestForReviewWhenActiveExistsThrowsDuplicate(): void
    {
        $example = $this->createExampleAtDraft();

        $activeRequest = new WorkflowTransitionRequest(Example::RESOURCE_KEY, (string) $example->getId(), 'en');
        $this->workflowTransitionRequestRepository->add($activeRequest);
        static::getEntityManager()->flush();

        $this->expectException(DuplicateActiveWorkflowTransitionRequestException::class);

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
    }

    public function testPublishBlockedWithoutApprovedRequest(): void
    {
        $example = $this->createExampleAtDraft();

        $this->expectException(WorkflowTransitionRequestNotApprovedException::class);

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        );
    }

    public function testPublishBlockedWithPendingRequest(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->expectException(WorkflowTransitionRequestNotApprovedException::class);

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        );
    }

    public function testCancelReviewClosesTheRequestAndReturnsContentToUnpublished(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition($example, $dimensionAttributes, WorkflowInterface::WORKFLOW_TRANSITION_UNPUBLISH);
        $this->contentManager->applyTransition($example, $dimensionAttributes, WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW);
        static::getEntityManager()->flush();

        $request = $this->workflowTransitionRequestRepository->getOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => (string) $example->getId(),
            'locale' => 'en',
            'active' => true,
        ]);

        $dimensionContent = $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW,
        );
        static::getEntityManager()->flush();

        $this->assertSame(WorkflowInterface::WORKFLOW_PLACE_UNPUBLISHED, $dimensionContent->getWorkflowPlace());

        $cancelledRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $cancelledRequest->getStatus());
        $this->assertNull($cancelledRequest->getActiveKey(), 'A cancelled request must not block the next one.');
    }

    public function testRejectClosesTheRequestAndReturnsContentToUnpublished(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition($example, $dimensionAttributes, WorkflowInterface::WORKFLOW_TRANSITION_UNPUBLISH);
        $this->contentManager->applyTransition($example, $dimensionAttributes, WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW);
        static::getEntityManager()->flush();

        $request = $this->workflowTransitionRequestRepository->getOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => (string) $example->getId(),
            'locale' => 'en',
            'active' => true,
        ]);

        $dimensionContent = $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REJECT,
        );
        static::getEntityManager()->flush();

        $this->assertSame(WorkflowInterface::WORKFLOW_PLACE_UNPUBLISHED, $dimensionContent->getWorkflowPlace());

        $rejectedRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $rejectedRequest->getStatus());
        $this->assertNull(
            $rejectedRequest->getActiveKey(),
            'A rejected request must release the lock instead of wedging the content in review.',
        );
    }

    public function testCancelReviewDraftClosesTheRequestAndReturnsContentToDraft(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $request = $this->workflowTransitionRequestRepository->getOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => (string) $example->getId(),
            'locale' => 'en',
            'active' => true,
        ]);

        $dimensionContent = $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->assertSame(WorkflowInterface::WORKFLOW_PLACE_DRAFT, $dimensionContent->getWorkflowPlace());

        $cancelledRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $cancelledRequest->getStatus());
        $this->assertNull($cancelledRequest->getActiveKey(), 'A cancelled request must not block the next one.');
    }

    public function testCancelReviewDraftByNonCreatorIsRejected(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->authenticateAsRequestCreator('other_canceller');

        $this->expectException(WorkflowTransitionRequestCancelNotAllowedException::class);
        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT,
        );
    }

    public function testRequestForReviewIsRefusedWhenTheTemplateOptsOut(): void
    {
        $example = static::createExample(
            [
                'en' => [
                    'live' => [
                        'template' => 'example-no-workflow',
                        'title' => 'Published Title',
                        'url' => '/published-title-no-workflow',
                    ],
                    'draft' => [
                        'template' => 'example-no-workflow',
                        'title' => 'Draft Title',
                        'url' => '/draft-title-no-workflow',
                    ],
                ],
            ],
            ['create_route' => true],
        );
        static::getEntityManager()->flush();

        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        try {
            $this->contentManager->applyTransition(
                $example,
                $dimensionAttributes,
                WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
            );
            $this->fail('Expected the transition to be refused for a template tagged workflow="none".');
        } catch (NoRequestWorkflowException $exception) {
            $this->assertSame(
                'sulu_content.workflow_transition_request.no_workflow',
                $exception->getMessageTranslationKey(),
            );
        }

        $dimensionContent = $this->contentManager->resolve($example, $dimensionAttributes);
        $this->assertSame(
            WorkflowInterface::WORKFLOW_PLACE_DRAFT,
            $dimensionContent->getWorkflowPlace(),
            'A refused request must leave the content where it was.',
        );

        $this->assertNull($this->workflowTransitionRequestRepository->findOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => (string) $example->getId(),
            'locale' => 'en',
            'active' => true,
        ]));
    }
}
