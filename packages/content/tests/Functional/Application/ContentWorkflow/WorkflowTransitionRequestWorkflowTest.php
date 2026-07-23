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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Exception\DuplicateActiveWorkflowTransitionRequestException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\WorkflowTransitionRequestEnabledKernel;
use Sulu\Content\Tests\Functional\Traits\CreateCategoryTrait;
use Sulu\Content\Tests\Functional\Traits\CreateMediaTrait;
use Sulu\Content\Tests\Functional\Traits\CreateTagTrait;
use Sulu\Content\Tests\Traits\CreateExampleTrait;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
class WorkflowTransitionRequestWorkflowTest extends SuluTestCase
{
    use CreateCategoryTrait;
    use CreateExampleTrait;
    use CreateMediaTrait;
    use CreateTagTrait;

    private ContentManagerInterface $contentManager;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    private User $requestCreator;

    protected static function getKernelClass(): string
    {
        return WorkflowTransitionRequestEnabledKernel::class;
    }

    protected function setUp(): void
    {
        self::purgeDatabase();

        $this->contentManager = static::getContainer()->get(ContentManagerInterface::class);
        $this->workflowTransitionRequestRepository = static::getContainer()->get(WorkflowTransitionRequestRepositoryInterface::class);
        $this->requestCreator = $this->createRequestCreator();
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->requestCreator, 'test', []));
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

    public function testPublishAllowedWithApprovedRequest(): void
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
        $request->addApproval(static::getTestUser());
        static::getEntityManager()->flush();

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        );
        static::getEntityManager()->flush();

        $publishedRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PUBLISHED, $publishedRequest->getStatus());
    }

    public function testPublishWithoutActiveRequestBlocked(): void
    {
        $example = $this->createExampleAtDraft();

        $this->expectException(WorkflowTransitionRequestNotApprovedException::class);

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        );
    }

    private function createExampleAtDraft(): Example
    {
        $example = static::createExample(
            [
                'en' => [
                    'live' => [
                        'template' => 'example-2',
                        'title' => 'Published Title',
                        'url' => '/published-title',
                    ],
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

    private function createRequestCreator(): User
    {
        $entityManager = static::getEntityManager();

        $contact = new Contact();
        $contact->setFirstName('Request');
        $contact->setLastName('Creator');
        $entityManager->persist($contact);

        $user = new User();
        $user->setUsername('request_creator');
        $user->setPassword('test');
        $user->setSalt('salt');
        $user->setLocale('en');
        $user->setContact($contact);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
