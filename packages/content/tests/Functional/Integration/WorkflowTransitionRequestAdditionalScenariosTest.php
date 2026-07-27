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
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestCancelNotAllowedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\WorkflowTransitionRequestEnabledKernel;
use Sulu\Content\Tests\Traits\CreateExampleTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Additional gap-filling scenarios for the workflow transition request feature.
 *
 * Covers gaps identified in commit-reviews/08-test-coverage.md:
 * - Gap 1: PUT save-draft after request exists must be blocked.
 * - Gap 5: cancel by non-creator must return 403.
 * - Gap 6: reject by user without REVIEW permission must return 403.
 * - Bug surfaced: PublishWorkflowTransitionRequestMessage + CreateWorkflowTransitionRequestMessage are dead.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
class WorkflowTransitionRequestAdditionalScenariosTest extends SuluTestCase
{
    use CreateExampleTrait;

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

        // PUT save-draft for the same locale → should be blocked by the content-manager decorator.
        $this->client->request(
            'PUT',
            \sprintf('/admin/api/examples/%d?locale=en', $example->getId()),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => 'example-2',
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

    public function testCancelByNonCreatorIsRejected(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        // requestCreator triggers the request (via token storage in setUp).
        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        // Switch the token to somebody who did not create the request, then drive the same
        // `cancel_review_draft` transition the admin snackbar uses.
        $otherUser = $this->createRequestCreator('other_canceller');
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($otherUser, 'test', []));

        $this->expectException(WorkflowTransitionRequestCancelNotAllowedException::class);
        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT,
        );
    }

    public function testCancelByCreatorClosesTheRequest(): void
    {
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_CANCEL_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->assertNull(
            $this->workflowTransitionRequestRepository->findOneBy([
                'resourceKey' => Example::RESOURCE_KEY,
                'resourceId' => (string) $example->getId(),
                'locale' => 'en',
                'active' => true,
            ]),
            'Cancelling through the content transition must close the request.',
        );
    }

    public function testBypassWithLivePermissionPublishesWithoutApproval(): void
    {
        // The default TestVoter grants every permission for username "test", so this exercises the
        // happy bypass path. No reviewer has approved — the publish would normally be blocked.
        $example = $this->createExampleAtDraft();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        $this->client->request(
            'POST',
            \sprintf('/admin/api/examples/%d?action=publish&locale=en&bypassReview=true', $example->getId()),
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
        // Document current behavior: bypass publishes the content but leaves the request as-is
        // (still active/PENDING). This is worth deciding — the request becomes stale data.
    }

    public function testBypassWithoutLivePermissionReturns403(): void
    {
        $this->grantTestUserViewAndEditButNotLive();

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
            'POST',
            \sprintf('/admin/api/examples/%d?action=publish&locale=en&bypassReview=true', $example->getId()),
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

    private function grantTestUserViewAndEditButNotLive(): void
    {
        $entityManager = static::getEntityManager();
        $testUser = static::getTestUser();

        $role = new \Sulu\Bundle\SecurityBundle\Entity\Role();
        $role->setName('bypass_without_live');
        $role->setSystem('Sulu');
        $entityManager->persist($role);

        $permission = new \Sulu\Bundle\SecurityBundle\Entity\Permission();
        $permission->setRole($role);
        $permission->setContext(\Sulu\Content\Tests\Application\ExampleTestBundle\Admin\ExampleAdmin::SECURITY_CONTEXT);
        $permission->setPermissions(80); // VIEW(64) + EDIT(16). No LIVE(2), no REVIEW(128).
        $entityManager->persist($permission);
        $role->addPermission($permission);

        $userRole = new \Sulu\Bundle\SecurityBundle\Entity\UserRole();
        $userRole->setUser($testUser);
        $userRole->setRole($role);
        $userRole->setLocale('["en"]');
        $entityManager->persist($userRole);
        $testUser->addUserRole($userRole);

        $entityManager->flush();
    }

    public function testRejectDeniedForUserWithoutReviewPermission(): void
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

        $this->renameTestUserTo('reviewer_no_review');
        $this->client->setServerParameter('PHP_AUTH_USER', 'reviewer_no_review');
        $this->client->setServerParameter('PHP_AUTH_PW', 'test');

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=reject', $request->getId()),
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            403,
            $response->getStatusCode(),
            \sprintf(
                'Expected 403 for reject without REVIEW permission but got %d. Body: %s',
                $response->getStatusCode(),
                (string) $response->getContent(),
            ),
        );
    }

    private function grantTestUserViewAndEditButNotReview(): void
    {
        $entityManager = static::getEntityManager();
        $testUser = static::getTestUser();

        $role = new \Sulu\Bundle\SecurityBundle\Entity\Role();
        $role->setName('reviewer_without_review');
        $role->setSystem('Sulu');
        $entityManager->persist($role);

        $permission = new \Sulu\Bundle\SecurityBundle\Entity\Permission();
        $permission->setRole($role);
        $permission->setContext(\Sulu\Content\Tests\Application\ExampleTestBundle\Admin\ExampleAdmin::SECURITY_CONTEXT);
        $permission->setPermissions(80); // VIEW(64) + EDIT(16). No REVIEW(128).
        $entityManager->persist($permission);
        $role->addPermission($permission);

        $userRole = new \Sulu\Bundle\SecurityBundle\Entity\UserRole();
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

    private function createRequestCreator(string $username = 'request_creator'): User
    {
        $entityManager = static::getEntityManager();

        $contact = new Contact();
        $contact->setFirstName('Request');
        $contact->setLastName('Creator');
        $entityManager->persist($contact);

        $user = new User();
        $user->setUsername($username);
        $user->setPassword('test');
        $user->setSalt('salt');
        $user->setLocale('en');
        $user->setContact($contact);
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
