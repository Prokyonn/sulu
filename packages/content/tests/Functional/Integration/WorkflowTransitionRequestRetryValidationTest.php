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
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\Kernel;
use Sulu\Content\Tests\Traits\ConsumeTransportTrait;
use Sulu\Content\Tests\Traits\WorkflowTransitionRequestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A validator that answered can be run again from the review overlay. The retry resets its row to
 * pending and dispatches the validation message once more, so the check answers a second time.
 */
#[CoversNothing]
class WorkflowTransitionRequestRetryValidationTest extends SuluTestCase
{
    use ConsumeTransportTrait;
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

    public function testRetryRunsTheCheckAgain(): void
    {
        $example = $this->sendForReview();
        $this->consumeTransport(Kernel::VALIDATION_TRANSPORT);

        $this->assertSame(
            WorkflowTransitionRequestReviewerStatusEnum::REJECTED,
            $this->findValidatorReviewer($example)->getStatus(),
        );

        $this->client->request(
            'POST',
            \sprintf(
                '/admin/api/workflow-transition-requests/%s.json?action=retry&validator=%s',
                $this->findRequest($example)->getId(),
                'test_configured_result',
            ),
        );

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(200, $response);

        /** @var array{reviewers: array<int, array<string, mixed>>} $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame('pending', $content['reviewers'][0]['status']);
        $this->assertNull($content['reviewers'][0]['comment']);
        $this->assertNull($content['reviewers'][0]['decidedAt']);

        $this->consumeTransport(Kernel::VALIDATION_TRANSPORT);

        $reviewer = $this->findValidatorReviewer($example);
        $this->assertSame(
            WorkflowTransitionRequestReviewerStatusEnum::REJECTED,
            $reviewer->getStatus(),
            'The retried check has to answer again instead of staying pending.',
        );
        $this->assertSame(
            \sprintf('The configured check rejected example %s.', $example->getId()),
            $reviewer->getComment(),
        );
    }

    public function testRetryWithUnknownValidatorReturns400(): void
    {
        $example = $this->sendForReview();

        $this->client->request(
            'POST',
            \sprintf(
                '/admin/api/workflow-transition-requests/%s.json?action=retry&validator=nope',
                $this->findRequest($example)->getId(),
            ),
        );

        $this->assertHttpStatusCode(400, $this->client->getResponse());
    }

    public function testRetryOnAClosedRequestLeavesTheVerdictStanding(): void
    {
        $example = $this->sendForReview();
        $this->consumeTransport(Kernel::VALIDATION_TRANSPORT);

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_BYPASS_PUBLISH,
        );
        static::getEntityManager()->flush();

        $request = $this->findRequest($example);
        $this->assertFalse($request->isOpen(), 'Publishing has to close the request.');

        $this->client->request(
            'POST',
            \sprintf(
                '/admin/api/workflow-transition-requests/%s.json?action=retry&validator=%s',
                $request->getId(),
                'test_configured_result',
            ),
        );

        $this->assertHttpStatusCode(400, $this->client->getResponse());

        $reviewer = $this->findValidatorReviewer($example);
        $this->assertSame(
            WorkflowTransitionRequestReviewerStatusEnum::REJECTED,
            $reviewer->getStatus(),
            'A retry on a closed request must not wipe the verdict the check already gave.',
        );
        $this->assertNotNull($reviewer->getComment());
    }

    private function sendForReview(): Example
    {
        $example = $this->createExampleAtDraft('example-configured-workflow');

        $this->contentManager->applyTransition(
            $example,
            ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'],
            WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
        );
        static::getEntityManager()->flush();

        return $example;
    }

    private function findRequest(Example $example): WorkflowTransitionRequest
    {
        static::getEntityManager()->clear();

        return $this->workflowTransitionRequestRepository->getOneBy([
            'resourceKey' => Example::RESOURCE_KEY,
            'resourceId' => (string) $example->getId(),
            'locale' => 'en',
        ]);
    }

    private function findValidatorReviewer(Example $example): WorkflowTransitionRequestReviewer
    {
        $reviewer = $this->findRequest($example)->getValidatorReviewer('test_configured_result');
        $this->assertNotNull($reviewer);

        return $reviewer;
    }
}
