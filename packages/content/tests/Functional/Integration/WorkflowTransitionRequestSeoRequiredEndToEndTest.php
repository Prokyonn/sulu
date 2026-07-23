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
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\WorkflowTransitionRequestSeoRequiredKernel;
use Sulu\Content\Tests\Traits\CreateExampleTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Verifies the load-bearing publish-guard semantics: the request can be APPROVED by reviewers even
 * when validators that depend on dimension content (SEO/excerpt) would fail, because the evaluator
 * is invoked WITHOUT dimension content from message handlers. The check then fires at publish time
 * with the current dimension content, and the publish must be blocked.
 *
 * Tests Gap 3 from commit-reviews/08-test-coverage.md.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
class WorkflowTransitionRequestSeoRequiredEndToEndTest extends SuluTestCase
{
    use CreateExampleTrait;

    private KernelBrowser $client;

    private ContentManagerInterface $contentManager;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    private User $requestCreator;

    protected static function getKernelClass(): string
    {
        return WorkflowTransitionRequestSeoRequiredKernel::class;
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
        // The transition subscriber reads token storage to attribute the request creator. Authenticating
        // as a *different* user than the http test user prevents the self-review guard from firing.
        static::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($this->requestCreator, 'test', []));
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

    public function testApprovalSucceedsButPublishBlockedWhenSeoMissingInContent(): void
    {
        $example = $this->createExampleWithoutSeo();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        // Step 1: request review. Should create PENDING request.
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

        // Step 2: approve via HTTP. The Approve handler calls refreshStatus() WITHOUT dimension content,
        // so the SEO validator should short-circuit to pass. Request becomes APPROVED.
        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $request->getId()),
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $approveContent */
        $approveContent = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame(
            'approved',
            $approveContent['status'],
            'Request must become APPROVED even though SEO is missing — Approve handler does not see dimension content.',
        );

        // Step 3: attempt publish. The publish guard subscriber re-evaluates with dimension content,
        // SEO is missing, so the publish must be blocked.
        $this->expectException(WorkflowTransitionRequestNotApprovedException::class);
        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        );
    }

    public function testPublishSucceedsWhenSeoFilledAtPublishTime(): void
    {
        $example = $this->createExampleWithSeoTitle();
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

        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $request->getId()),
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $this->contentManager->applyTransition(
            $example,
            $dimensionAttributes,
            WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH,
        );
        static::getEntityManager()->flush();

        $finalRequest = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(WorkflowTransitionRequestStatusEnum::PUBLISHED, $finalRequest->getStatus());
    }

    public function testNormalizerSurfacesPublishValidationFailureAfterApproval(): void
    {
        $example = $this->createExampleWithoutSeo();
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

        // Approve via HTTP — status flips to APPROVED, but SEO is still missing on dimension content.
        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $request->getId()),
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        // GET the example. The normalizer should re-evaluate publish validators against current
        // content and surface SEO failure on `activeWorkflowTransitionRequest.publishValidation`.
        $this->client->request(
            'GET',
            \sprintf('/admin/api/examples/%d?locale=en', $example->getId()),
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array<string, mixed> $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        /** @var array{status: string, publishValidation: array{passed: bool, failures: list<array<string, mixed>>}} $activeRequest */
        $activeRequest = $content['activeWorkflowTransitionRequest'];

        $this->assertSame('approved', $activeRequest['status']);
        $this->assertFalse(
            $activeRequest['publishValidation']['passed'],
            'publishValidation should report failure for APPROVED request that misses SEO fields.',
        );
        $this->assertNotEmpty(
            $activeRequest['publishValidation']['failures'],
            'publishValidation.failures should list the missing-SEO validator output.',
        );

        $messageKeys = \array_column($activeRequest['publishValidation']['failures'], 'messageKey');
        $this->assertContains(
            'sulu_content.workflow_transition_request.seo_required.missing',
            $messageKeys,
            'Expected the seo_required validator failure to be surfaced.',
        );
    }

    private function createExampleWithoutSeo(): Example
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

    private function createExampleWithSeoTitle(): Example
    {
        $example = static::createExample(
            [
                'en' => [
                    'live' => [
                        'template' => 'example-2',
                        'title' => 'Published Title',
                        'url' => '/published-title-seo',
                    ],
                    'draft' => [
                        'template' => 'example-2',
                        'title' => 'Draft Title',
                        'url' => '/draft-title-seo',
                        'seo' => ['title' => 'SEO Title'],
                    ],
                ],
            ],
            ['create_route' => true],
        );
        static::getEntityManager()->flush();

        return $example;
    }
}
