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
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestPrevalidationFailedException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Traits\WorkflowTransitionRequestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Verifies the prevalidator gate: `seo_required` and `excerpt_required` are configured, so content
 * that misses either cannot enter review at all. The `request_for_review` transition aborts, no
 * request row is created, and the admin gets a 422 naming every missing field, not only the first
 * failure.
 *
 * Once a request exists the content is locked, so the publish guard no longer re-checks the
 * content, it only asserts the request was approved.
 */
#[CoversNothing]
class WorkflowTransitionRequestSeoRequiredEndToEndTest extends SuluTestCase
{
    use WorkflowTransitionRequestTrait;

    private const SEO_TEMPLATE = 'example-seo-workflow';

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

        // The transition subscriber reads token storage to attribute the request creator. Authenticating
        // as a *different* user than the http test user prevents the self-review guard from firing.
        $this->authenticateAsRequestCreator();
    }

    public function testRequestForReviewBlockedWhenSeoMissing(): void
    {
        $example = $this->createExampleWithoutSeo();
        $dimensionAttributes = ['stage' => DimensionContentInterface::STAGE_DRAFT, 'locale' => 'en'];

        try {
            $this->contentManager->applyTransition(
                $example,
                $dimensionAttributes,
                WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
            );
            $this->fail('Expected the prevalidators to abort the transition.');
        } catch (WorkflowTransitionRequestPrevalidationFailedException $exception) {
            $this->assertSame(
                'SEO fields required before requesting a review: title, description.'
                . ' Excerpt fields required before requesting a review: title.',
                $exception->getMessageTranslationKey(),
                'Every failing check contributes its translated message to the single admin message.',
            );
        }

        $this->assertNull(
            $this->workflowTransitionRequestRepository->findOneBy([
                'resourceKey' => Example::RESOURCE_KEY,
                'resourceId' => (string) $example->getId(),
                'locale' => 'en',
            ]),
            'A failing prevalidator must prevent the request from being created at all.',
        );
    }

    public function testRequestForReviewReturns422WhenSeoMissing(): void
    {
        $example = $this->createExampleWithoutSeo();

        $this->client->request(
            'POST',
            \sprintf(
                '/admin/api/examples/%d?action=%s&locale=en',
                $example->getId(),
                WorkflowInterface::WORKFLOW_TRANSITION_REQUEST_FOR_REVIEW_DRAFT,
            ),
        );

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(422, $response);

        /** @var array{detail?: string} $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertSame(
            'SEO fields required before requesting a review: title, description.'
            . ' Excerpt fields required before requesting a review: title.',
            $content['detail'] ?? null,
            'The 422 detail must name every field that is still missing, in one line.',
        );
    }

    public function testPublishSucceedsWhenSeoFilledAtPublishTime(): void
    {
        $example = $this->createExampleWithSeoAndExcerptTitle();
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

    private function createExampleWithoutSeo(): Example
    {
        $example = static::createExample(
            [
                'en' => [
                    'draft' => [
                        'template' => self::SEO_TEMPLATE,
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

    private function createExampleWithSeoAndExcerptTitle(): Example
    {
        $example = static::createExample(
            [
                'en' => [
                    'live' => [
                        'template' => self::SEO_TEMPLATE,
                        'title' => 'Published Title',
                        'url' => '/published-title-seo',
                    ],
                    'draft' => [
                        'template' => self::SEO_TEMPLATE,
                        'title' => 'Draft Title',
                        'url' => '/draft-title-seo',
                        'seo' => ['title' => 'SEO Title', 'description' => 'SEO Description'],
                        'excerpt' => ['title' => 'Excerpt Title'],
                    ],
                ],
            ],
            ['create_route' => true],
        );
        static::getEntityManager()->flush();

        return $example;
    }
}
