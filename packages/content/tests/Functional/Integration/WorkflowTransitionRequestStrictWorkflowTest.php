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
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\WorkflowTransitionRequestStrictKernel;
use Sulu\Content\Tests\Traits\CreateExampleTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Verifies the template-tag-driven workflow resolution: a template that opts into a non-default
 * named workflow via `<tag name="sulu_content.request_workflow" workflow="strict"/>` should produce
 * a workflow transition request with `workflowName=strict`, and that workflow's validators (e.g.
 * `user_approvals.count=2`) should govern publish authorization.
 *
 * Tests Gap 7 from commit-reviews/08-test-coverage.md.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
class WorkflowTransitionRequestStrictWorkflowTest extends SuluTestCase
{
    use CreateExampleTrait;

    private KernelBrowser $client;

    private ContentManagerInterface $contentManager;

    private WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository;

    private User $requestCreator;

    protected static function getKernelClass(): string
    {
        return WorkflowTransitionRequestStrictKernel::class;
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

    public function testRequestCreatedWithStrictWorkflowNameWhenTemplateOptsIn(): void
    {
        $example = $this->createExampleWithStrictTemplate();
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

        $this->assertSame(
            'strict',
            $request->getWorkflowName(),
            \sprintf(
                'Template-tag-driven workflow resolution failed: request was created with workflowName="%s"; expected "strict".',
                $request->getWorkflowName(),
            ),
        );
    }

    public function testStrictWorkflowRequiresTwoApprovalsBeforePublish(): void
    {
        $example = $this->createExampleWithStrictTemplate();
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

        // First approver: test user (different from request creator).
        $this->client->request(
            'POST',
            \sprintf('/admin/api/workflow-transition-requests/%s.json?action=approve', $request->getId()),
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $afterFirstApproval = $this->workflowTransitionRequestRepository->getOneBy(['id' => $request->getId()]);
        $this->assertSame(
            WorkflowTransitionRequestStatusEnum::PENDING,
            $afterFirstApproval->getStatus(),
            'After one approval the request must remain PENDING because strict workflow requires count=2.',
        );
    }

    private function createExampleWithStrictTemplate(): Example
    {
        $example = static::createExample(
            [
                'en' => [
                    'live' => [
                        'template' => 'example-strict-workflow',
                        'title' => 'Published Title',
                        'url' => '/published-title-strict',
                    ],
                    'draft' => [
                        'template' => 'example-strict-workflow',
                        'title' => 'Draft Title',
                        'url' => '/draft-title-strict',
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
