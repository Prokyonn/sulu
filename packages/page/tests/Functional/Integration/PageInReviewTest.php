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
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Content sitting in a review place must not accept draft saves. The rule lives in the content
 * workflow — there is no `edit` transition out of `review`/`review_draft` — so this exercises the
 * real admin save route rather than a seam no shipped content type uses.
 */
#[CoversNothing]
class PageInReviewTest extends SuluTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->createAuthenticatedClient(
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        self::purgeDatabase();
    }

    public function testSaveDraftIsRejectedWhilePageIsInReview(): void
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
        $id = $content['id'];

        $this->client->request('POST', \sprintf('/admin/api/pages/%s?locale=en&action=request_for_review', $id));
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        /** @var array{workflowPlace: string} $reviewed */
        $reviewed = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->assertSame('review', $reviewed['workflowPlace']);

        $this->client->request(
            'PUT',
            \sprintf('/admin/api/pages/%s?locale=en', $id),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => 'default',
                'title' => 'Edited While In Review',
                'url' => '/page-in-review',
            ]),
        );

        $response = $this->client->getResponse();
        $this->assertSame(
            409,
            $response->getStatusCode(),
            \sprintf(
                'Expected 409 when saving a draft of a page in review, got %d. Body: %s',
                $response->getStatusCode(),
                (string) $response->getContent(),
            ),
        );
    }

    public function testSaveDraftIsAcceptedWhilePageIsNotInReview(): void
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
                'title' => 'Editable Page',
                'url' => '/editable-page',
            ]),
        );
        $this->assertHttpStatusCode(201, $this->client->getResponse());

        /** @var array{id: string} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->client->request(
            'PUT',
            \sprintf('/admin/api/pages/%s?locale=en', $content['id']),
            [],
            [],
            [],
            (string) \json_encode([
                'template' => 'default',
                'title' => 'Edited Normally',
                'url' => '/editable-page',
            ]),
        );

        $this->assertHttpStatusCode(200, $this->client->getResponse());
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
