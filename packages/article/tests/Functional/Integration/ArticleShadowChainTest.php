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

namespace Sulu\Article\Tests\Functional\Integration;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Route\Domain\Value\RequestAttributeEnum;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

#[CoversNothing]
class ArticleShadowChainTest extends SuluTestCase
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

    public function testRepublishingRootUpdatesWholeChain(): void
    {
        self::purgeDatabase();

        $this->client->request(
            'POST',
            '/admin/api/articles?locale=en&action=publish',
            [], [], [],
            \json_encode([
                'template' => 'article',
                'title' => 'Root EN',
                'url' => '/root-en',
                'mainWebspace' => 'sulu-io',
            ]) ?: null,
        );
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $id = $this->extractId();

        $deData = ['template' => 'article', 'title' => 'Root DE', 'url' => '/root-de', 'mainWebspace' => 'sulu-io', 'shadowOn' => true, 'shadowLocale' => 'en'];
        $this->client->request('PUT', '/admin/api/articles/' . $id . '?locale=de', [], [], [], \json_encode($deData) ?: null);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->client->request('PUT', '/admin/api/articles/' . $id . '?locale=de&action=publish', [], [], [], \json_encode($deData) ?: null);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $frData = ['template' => 'article', 'title' => 'Root FR', 'url' => '/root-fr', 'mainWebspace' => 'sulu-io', 'shadowOn' => true, 'shadowLocale' => 'de'];
        $this->client->request('PUT', '/admin/api/articles/' . $id . '?locale=fr', [], [], [], \json_encode($frData) ?: null);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        $this->client->request('PUT', '/admin/api/articles/' . $id . '?locale=fr&action=publish', [], [], [], \json_encode($frData) ?: null);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $liveDe = $this->getLiveDimensionContent($id, 'de');
        $liveFr = $this->getLiveDimensionContent($id, 'fr');
        self::assertNotNull($liveDe);
        self::assertNotNull($liveFr);
        self::assertIsArray($liveDe['templateData']);
        self::assertIsArray($liveFr['templateData']);
        self::assertSame('Root EN', $liveDe['templateData']['title'] ?? null);
        self::assertSame('Root EN', $liveFr['templateData']['title'] ?? null);

        // Republish the root (en). The pre-fix one-hop cascade only refreshed the direct
        // shadow (de) and never reached the transitive shadow (fr).
        $this->client->request(
            'PUT',
            '/admin/api/articles/' . $id . '?locale=en&action=publish',
            [], [], [],
            \json_encode(['template' => 'article', 'title' => 'Root EN v2', 'url' => '/root-en', 'mainWebspace' => 'sulu-io']) ?: null,
        );
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        $liveDe = $this->getLiveDimensionContent($id, 'de');
        $liveFr = $this->getLiveDimensionContent($id, 'fr');
        self::assertNotNull($liveDe);
        self::assertNotNull($liveFr);
        self::assertIsArray($liveDe['templateData']);
        self::assertIsArray($liveFr['templateData']);
        self::assertSame('Root EN v2', $liveDe['templateData']['title'] ?? null, 'LIVE de should reflect the republished root content.');
        self::assertSame('Root EN v2', $liveFr['templateData']['title'] ?? null, 'LIVE fr should transitively reflect the republished root content.');
    }

    /**
     * Reads the "id" field from the last JSON response body.
     */
    private function extractId(): string
    {
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($content);
        $id = $content['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLiveDimensionContent(string $articleId, string $locale): ?array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $row = $entityManager->createQueryBuilder()
            ->from(ArticleDimensionContent::class, 'dimensionContent')
            ->select(
                'dimensionContent.stage',
                'dimensionContent.locale',
                'dimensionContent.templateKey',
                'dimensionContent.workflowPublished',
                'dimensionContent.shadowLocale',
                'dimensionContent.templateData',
            )
            ->where('IDENTITY(dimensionContent.article) = :articleId')
            ->andWhere('dimensionContent.stage = :stage')
            ->andWhere('dimensionContent.locale = :locale')
            ->andWhere('dimensionContent.version = 0')
            ->setParameter('articleId', $articleId)
            ->setParameter('stage', 'live')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();

        if (!\is_array($row)) {
            return null;
        }

        $result = [];
        foreach ($row as $key => $value) {
            if (\is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
