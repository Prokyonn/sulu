<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Article\Tests\Functional\Integration;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Route\Domain\Value\RequestAttributeEnum;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Publishing a shadow article locale must copy the live content of its source locale. The workflow
 * handler therefore has to re-load the article with the source locale's dimension contents
 * hydrated; otherwise aggregating the source returns a template-less content and publishing fails
 * with a misleading "source locale has not been published yet" error even though it was.
 */
#[CoversNothing]
class ArticleShadowPublishTest extends SuluTestCase
{
    /**
     * @var KernelBrowser
     */
    protected $client;

    protected function setUp(): void
    {
        $this->client = $this->createAuthenticatedClient(
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        );

        $requestContext = self::getContainer()->get('router.request_context');
        $requestContext->setParameter(RequestAttributeEnum::WEBSPACE->value, 'sulu-io');
    }

    public function testPublishShadowLocaleSucceeds(): void
    {
        self::purgeDatabase();

        // 1. Create and publish the source article in EN.
        $this->client->request('POST', '/admin/api/articles?locale=en&action=publish', [], [], [], \json_encode([
            'template' => 'article',
            'title' => 'Source EN',
            'url' => '/source-en',
            'mainWebspace' => 'sulu-io',
        ]) ?: null);
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        /** @var array{id: string} $content */
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $content['id'];

        // 2. Save DE as a shadow of the published EN.
        $shadowData = [
            'template' => 'article',
            'title' => 'Source EN',
            'url' => '/quelle-de',
            'mainWebspace' => 'sulu-io',
            'shadowOn' => true,
            'shadowLocale' => 'en',
        ];
        $this->client->request('PUT', '/admin/api/articles/' . $id . '?locale=de', [], [], [], \json_encode($shadowData) ?: null);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        // 3. Publish the DE shadow. This used to fail with a false ShadowSourceNotPublishedException
        //    (code 1107) because the article was loaded without EN's dimension contents, so the
        //    workflow could not aggregate the published source locale.
        $this->client->request('PUT', '/admin/api/articles/' . $id . '?locale=de&action=publish', [], [], [], \json_encode($shadowData) ?: null);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        // The DE live dimension content exists, shadows EN and carries EN's published template.
        $liveDe = $this->getLiveDimensionContent($id, 'de');
        self::assertNotNull($liveDe, 'Expected a published (live) DE dimension content.');
        self::assertSame('en', $liveDe['shadowLocale']);
        self::assertSame('article', $liveDe['templateKey']);
        self::assertNotNull($liveDe['workflowPublished']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLiveDimensionContent(string $articleId, string $locale): ?array
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var array<string, mixed>|null $row */
        $row = $em->createQueryBuilder()
            ->from(ArticleDimensionContent::class, 'd')
            ->select('d.stage', 'd.locale', 'd.templateKey', 'd.workflowPublished', 'd.shadowLocale')
            ->where('IDENTITY(d.article) = :id')
            ->andWhere('d.stage = :stage')
            ->andWhere('d.locale = :locale')
            ->andWhere('d.version = 0')
            ->setParameter('id', $articleId)
            ->setParameter('stage', 'live')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }
}
