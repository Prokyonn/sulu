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

namespace Sulu\Page\Tests\Functional\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Sulu\Page\Tests\Application\ReviewEnabledKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The admin submits every toolbar action as one request carrying the whole form plus an action, and
 * the controller persists before applying the action. These tests pin both halves of that: content
 * saves are refused while a review is open, and the transitions that end a review still get through.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
class PageInReviewTest extends SuluTestCase
{
    protected KernelBrowser $client;

    protected static function getKernelClass(): string
    {
        return ReviewEnabledKernel::class;
    }

    protected function setUp(): void
    {
        $this->client = $this->createAuthenticatedClient(
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::purgeDatabase();
    }

    public function testDraftSaveIsRejectedWhileInReview(): void
    {
        $id = $this->createPageInReview();

        $this->put($id, ['action' => 'draft'], 'Edited While In Review');

        $response = $this->client->getResponse();
        $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{detail?: string} $content */
        $content = \json_decode((string) $response->getContent(), true);
        $this->assertStringContainsString('in review', (string) ($content['detail'] ?? ''));
    }

    public function testCancelReviewIsAllowedAndClearsTheLock(): void
    {
        $id = $this->createPageInReview();

        $this->put($id, ['action' => 'cancel_review'], 'Ignored Payload');
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array{workflowPlace: string, _locked: bool, activeWorkflowTransitionRequest: mixed, title: string} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('unpublished', $content['workflowPlace']);
        $this->assertFalse($content['_locked']);
        $this->assertNull($content['activeWorkflowTransitionRequest']);

        // The read-only payload sent alongside the transition must not have been written.
        $this->assertSame('Page In Review', $content['title']);
    }

    public function testDraftSaveIsAllowedAgainAfterCancel(): void
    {
        $id = $this->createPageInReview();

        $this->put($id, ['action' => 'cancel_review'], 'Ignored Payload');
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $this->put($id, ['action' => 'draft'], 'Edited After Cancel');
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array{title: string} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('Edited After Cancel', $content['title']);
    }

    public function testDraftSaveIsAllowedWhenNoReviewIsOpen(): void
    {
        $id = $this->createPage();

        $this->put($id, ['action' => 'draft'], 'Edited Normally');
        $this->assertHttpStatusCode(200, $this->client->getResponse());
    }

    /**
     * @param array<string, string> $query
     */
    private function put(string $id, array $query, string $title): void
    {
        $this->client->request(
            'PUT',
            \sprintf('/admin/api/pages/%s?%s', $id, \http_build_query($query + ['locale' => 'en', 'webspace' => 'sulu-io'])),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => 'default',
                'title' => $title,
                'url' => '/page-in-review',
            ]),
        );
    }

    private function createPage(): string
    {
        $homepage = $this->createHomepage();

        $this->client->request(
            'POST',
            \sprintf('/admin/api/pages?locale=en&parentId=%s&webspace=sulu-io', $homepage->getId()),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => 'default',
                'title' => 'Page In Review',
                'url' => '/page-in-review',
            ]),
        );
        $this->assertHttpStatusCode(201, $this->client->getResponse());

        /** @var array{id: string} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);

        return $content['id'];
    }

    private function createPageInReview(): string
    {
        $id = $this->createPage();

        $this->client->request(
            'POST',
            \sprintf('/admin/api/pages/%s?locale=en&action=request_for_review', $id),
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array{workflowPlace: string, _locked: bool} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('review', $content['workflowPlace']);
        $this->assertTrue($content['_locked'], 'A workflow must be configured for pages in this kernel.');

        return $id;
    }

    private function createHomepage(): PageInterface
    {
        $homepage = new Page('0199ee04-c220-784e-a6fa-ac985870f2d5');
        $homepage->setLft(0);
        $homepage->setRgt(1);
        $homepage->setDepth(0);
        $homepage->setWebspaceKey('sulu-io');
        self::getEntityManager()->persist($homepage);
        self::getEntityManager()->flush();

        return $homepage;
    }
}
