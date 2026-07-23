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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\SecurityBundle\Entity\Permission;
use Sulu\Bundle\SecurityBundle\Entity\Role;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\SecurityBundle\Entity\UserRole;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Admin\ExampleAdmin;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Repository\ExampleRepository;
use Sulu\Content\Tests\Application\WorkflowTransitionRequestEnabledKernel;
use Sulu\Content\Tests\Functional\Traits\CreateCategoryTrait;
use Sulu\Content\Tests\Functional\Traits\CreateMediaTrait;
use Sulu\Content\Tests\Functional\Traits\CreateTagTrait;
use Sulu\Content\Tests\Traits\CreateExampleTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[CoversNothing]
#[RunTestsInSeparateProcesses]
class WorkflowTransitionRequestEndToEndTest extends SuluTestCase
{
    use CreateCategoryTrait;
    use CreateExampleTrait;
    use CreateMediaTrait;
    use CreateTagTrait;

    private KernelBrowser $client;

    private ContentManagerInterface $contentManager;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    private User $requestCreator;

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
        $this->requestCreator = $this->createRequestCreator();
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->requestCreator, 'test', []));
    }

    private function reloadExample(int $id): Example
    {
        /** @var ExampleRepository $repository */
        $repository = static::getContainer()->get('example_test.example_repository');

        return $repository->getOneBy(['id' => $id], ['example_admin' => true]);
    }

    public function testApproveDeniedForUserWithoutReviewPermission(): void
    {
        $this->grantTestUserViewAndEditButNotReview();

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

        // Rename the test user so the TestVoter (which grants every permission for username "test") abstains and
        // the real SecurityChecker takes over. The user keeps the same plaintext password "test".
        $this->renameTestUserTo('reviewer_no_review');

        $this->client->setServerParameter('PHP_AUTH_USER', 'reviewer_no_review');
        $this->client->setServerParameter('PHP_AUTH_PW', 'test');

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $request->getId()),
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            403,
            $response->getStatusCode(),
            \sprintf('Expected 403 but got %d. Body: %s', $response->getStatusCode(), (string) $response->getContent()),
        );
    }

    private function grantTestUserViewAndEditButNotReview(): void
    {
        $entityManager = static::getEntityManager();
        $testUser = static::getTestUser();

        $role = new Role();
        $role->setName('reviewer_without_review');
        $role->setSystem('Sulu');
        $entityManager->persist($role);

        // VIEW (64) + EDIT (16) = 80. No REVIEW (128).
        $permission = new Permission();
        $permission->setRole($role);
        $permission->setContext(ExampleAdmin::SECURITY_CONTEXT);
        $permission->setPermissions(80);
        $entityManager->persist($permission);
        $role->addPermission($permission);

        $userRole = new UserRole();
        $userRole->setUser($testUser);
        $userRole->setRole($role);
        $userRole->setLocale('["en"]');
        $entityManager->persist($userRole);
        $testUser->addUserRole($userRole);

        $entityManager->flush();
    }

    private function renameTestUserTo(string $username): void
    {
        $user = static::getTestUser();
        $user->setUsername($username);
        static::getEntityManager()->flush();
    }

    public function testHappyPathFromDraftThroughApprovalToPublish(): void
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
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $request->getId()),
            ['comment' => 'Looks great'],
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $approveContent */
        $approveContent = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('approved', $approveContent['status']);

        $reloadedExample = $this->reloadExample((int) $example->getId());
        $this->contentManager->applyTransition(
            $reloadedExample,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        );
        static::getEntityManager()->flush();

        $finalRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PUBLISHED, $finalRequest->getStatus());
        $this->assertNull($finalRequest->getActiveKey());

        $liveContent = $this->contentManager->resolve(
            $reloadedExample,
            ['stage' => DimensionContentInterface::STAGE_LIVE, 'locale' => 'en'],
        );
        $this->assertSame('Draft Title', $liveContent->getTemplateData()['title'] ?? null);
    }

    public function testRejectionRecoveryThroughReApprovalToPublish(): void
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
        $url = \sprintf('/admin/api/workflow-transition-requests/%s.json', $request->getId());

        $this->client->request('POST', $url . '?action=reject', ['comment' => 'Needs changes']);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $rejectContent */
        $rejectContent = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('rejected', $rejectContent['status']);

        $rejectedRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::REJECTED, $rejectedRequest->getStatus());
        $this->assertNotNull($rejectedRequest->getActiveKey(), 'Rejected request must remain active');

        $this->client->request('POST', $url . '?action=approve', ['comment' => 'Resolved']);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $approveContent */
        $approveContent = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('approved', $approveContent['status']);

        $approvedRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::APPROVED, $approvedRequest->getStatus());
    }

    public function testCreatorCannotApproveOwnRequestViaHttp(): void
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

        // Simulate a creator that matches the authenticated http user — outside an HTTP request the BlameSubscriber
        // does not fire, so we set the creator explicitly to reproduce the production scenario.
        $request->setCreator(static::getTestUser());
        static::getEntityManager()->flush();

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $request->getId()),
        );

        $response = $this->client->getResponse();
        $this->assertSame(403, $response->getStatusCode());

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame(
            'You cannot review a workflow transition request you created yourself.',
            $content['detail'] ?? null,
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
