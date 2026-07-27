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
use Sulu\Bundle\ContactBundle\Entity\Contact;
use Sulu\Bundle\SecurityBundle\Entity\User;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\WorkflowTransitionRequestEnabledKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

#[CoversNothing]
class WorkflowTransitionRequestControllerTest extends SuluTestCase
{
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

    public function testGetReturnsRequest(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en');

        $this->client->request('GET', \sprintf('/admin/api/workflow-transition-requests/%s.json', $workflowTransitionRequest->getId()));

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(200, $response);

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);

        $this->assertSame($workflowTransitionRequest->getId(), $content['id']);
        $this->assertSame('examples', $content['resourceKey']);
        $this->assertSame('1', $content['resourceId']);
        $this->assertSame('en', $content['locale']);
        $this->assertSame('pending', $content['status']);
        $this->assertArrayHasKey('requestedAt', $content);
        $this->assertSame([], $content['reviewers']);
    }

    public function testGetNotFoundReturns404(): void
    {
        $this->client->request('GET', '/admin/api/workflow-transition-requests/00000000-0000-0000-0000-000000000000.json');

        $this->assertHttpStatusCode(404, $this->client->getResponse());
    }

    public function testApproveCreatesReviewerAndTransitionsStatus(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en', $this->createRequestCreator());

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $workflowTransitionRequest->getId()),
        );

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(200, $response);

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame('approved', $content['status']);
        /** @var array<int, array<string, mixed>> $reviewers */
        $reviewers = $content['reviewers'];
        $this->assertCount(1, $reviewers);
        $this->assertSame('approved', $reviewers[0]['status']);
        $this->assertNull($reviewers[0]['comment']);
    }

    public function testApproveWithCommentPersistsComment(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en', $this->createRequestCreator());

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $workflowTransitionRequest->getId()),
            ['comment' => 'Looks good to me'],
        );

        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        /** @var array<int, array<string, mixed>> $reviewers */
        $reviewers = $content['reviewers'];
        $this->assertSame('Looks good to me', $reviewers[0]['comment']);
    }

    public function testRejectTransitionsToRejected(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en', $this->createRequestCreator());

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=reject', $workflowTransitionRequest->getId()),
            ['comment' => 'Not yet'],
        );

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(200, $response);

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame('rejected', $content['status']);
        /** @var array<int, array<string, mixed>> $reviewers */
        $reviewers = $content['reviewers'];
        $this->assertCount(1, $reviewers);
        $this->assertSame('rejected', $reviewers[0]['status']);
        $this->assertSame('Not yet', $reviewers[0]['comment']);
    }

    public function testApproveTwiceBySameUserUpdatesReviewer(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en', $this->createRequestCreator());
        $url = \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $workflowTransitionRequest->getId());

        $this->client->request('POST', $url, ['comment' => 'First']);
        $this->client->request('POST', $url, ['comment' => 'Second']);

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(200, $response);

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);
        /** @var array<int, array<string, mixed>> $reviewers */
        $reviewers = $content['reviewers'];
        $this->assertCount(1, $reviewers);
        $this->assertSame('Second', $reviewers[0]['comment']);
    }

    public function testRejectAfterApproveFlipsStatus(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en', $this->createRequestCreator());
        $baseUrl = \sprintf('/admin/api/workflow-transition-requests/%s.json', $workflowTransitionRequest->getId());

        $this->client->request('POST', $baseUrl . '?action=approve');
        $this->client->request('POST', $baseUrl . '?action=reject');

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(200, $response);

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame('rejected', $content['status']);
        /** @var array<int, array<string, mixed>> $reviewers */
        $reviewers = $content['reviewers'];
        $this->assertCount(1, $reviewers);
    }

    public function testCancelActionIsNotExposed(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en', static::getTestUser());

        // Cancelling has to move the content's workflow place too, so it runs as the
        // `cancel_review` content transition rather than through this endpoint.
        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=cancel', $workflowTransitionRequest->getId()),
        );

        $this->assertHttpStatusCode(400, $this->client->getResponse());
    }

    public function testApproveOnCancelledReturnsTranslatable400(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en', static::getTestUser());
        $workflowTransitionRequest->cancel();
        static::getEntityManager()->flush();

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $workflowTransitionRequest->getId()),
        );

        $response = $this->client->getResponse();
        $this->assertSame(400, $response->getStatusCode());

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame(
            'This workflow transition request has already been closed and cannot be modified.',
            $content['detail'] ?? null,
        );
    }

    public function testUnknownActionReturns400(): void
    {
        $workflowTransitionRequest = $this->persistWorkflowTransitionRequest('examples', '1', 'en');

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=explode', $workflowTransitionRequest->getId()),
        );

        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testActionOnMissingIdReturns404(): void
    {
        $this->client->request(
            'POST',
            '/admin/api/workflow-transition-requests/00000000-0000-0000-0000-000000000000.json?action=approve',
        );

        $this->assertHttpStatusCode(404, $this->client->getResponse());
    }

    private function persistWorkflowTransitionRequest(string $resourceKey, string $resourceId, string $locale, ?User $creator = null): WorkflowTransitionRequest
    {
        $workflowTransitionRequest = new WorkflowTransitionRequest($resourceKey, $resourceId, $locale);
        if (null !== $creator) {
            $workflowTransitionRequest->setCreator($creator);
        }

        $this->workflowTransitionRequestRepository->add($workflowTransitionRequest);
        static::getEntityManager()->flush();

        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $workflowTransitionRequest->getStatus());

        return $workflowTransitionRequest;
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
