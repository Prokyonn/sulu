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

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use Sulu\Bundle\TestBundle\Testing\SuluTestCase;
use Sulu\Content\Domain\Exception\ShadowLocaleCycleException;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;
use Sulu\Route\Domain\Value\RequestAttributeEnum;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Functional tests for shadow locale chains (a shadow whose base locale is itself a shadow,
 * e.g. fr -> de -> en). The integration test should have no impact on the coverage so we set
 * it to coversNothing.
 */
#[CoversNothing]
class ExampleControllerShadowChainTest extends SuluTestCase
{
    /**
     * @var KernelBrowser
     */
    protected $client;

    protected function setUp(): void
    {
        $this->client = $this->createAuthenticatedClient(
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']
        );

        // TODO this should not be necessary
        $this->client->disableReboot();
        $requestContext = self::getContainer()->get('router.request_context');
        $requestContext->setParameter(RequestAttributeEnum::WEBSPACE->value, 'sulu-io');
        // TODO this should not be necessary
    }

    public function testRepublishingRootUpdatesWholeChain(): void
    {
        self::purgeDatabase();

        $id = $this->createAndPublishChain('Root EN');

        $liveDe = $this->getLiveDimensionContent($id, 'de');
        $liveFr = $this->getLiveDimensionContent($id, 'fr');
        self::assertNotNull($liveDe);
        self::assertNotNull($liveFr);
        self::assertIsArray($liveDe['templateData']);
        self::assertIsArray($liveFr['templateData']);
        self::assertSame('Root EN', $liveDe['templateData']['title'] ?? null);
        self::assertSame('Root EN', $liveFr['templateData']['title'] ?? null);

        // Update and republish the root (en). The pre-fix one-hop cascade only refreshed
        // the direct shadow (de) and never reached the transitive shadow (fr).
        $this->client->request(
            'PUT',
            '/admin/api/examples/' . $id . '?locale=en&action=publish',
            [], [], [],
            \json_encode([
                'template' => 'example-2',
                'title' => 'Root EN v2',
                'url' => '/root-en',
            ]) ?: null
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $liveDe = $this->getLiveDimensionContent($id, 'de');
        $liveFr = $this->getLiveDimensionContent($id, 'fr');
        self::assertNotNull($liveDe);
        self::assertNotNull($liveFr);
        self::assertIsArray($liveDe['templateData']);
        self::assertIsArray($liveFr['templateData']);
        self::assertSame('Root EN v2', $liveDe['templateData']['title'] ?? null, 'LIVE de should reflect the republished root content.');
        self::assertSame('Root EN v2', $liveFr['templateData']['title'] ?? null, 'LIVE fr should transitively reflect the republished root content.');
    }

    public function testRepublishingMidChainKeepsDependentInSync(): void
    {
        self::purgeDatabase();

        $id = $this->createAndPublishChain('Root EN');

        $liveFr = $this->getLiveDimensionContent($id, 'fr');
        self::assertNotNull($liveFr);
        self::assertIsArray($liveFr['templateData']);
        self::assertSame('Root EN', $liveFr['templateData']['title'] ?? null);

        // Corrupt fr's LIVE content directly (bypassing the publish pipeline) so that only a
        // real cascade write during de's republish can bring it back in sync. This makes the
        // assertion below load-bearing instead of trivially true.
        $this->setLiveTemplateDataDirectly($id, 'fr', ['title' => 'STALE FR', 'images' => null]);

        $liveFr = $this->getLiveDimensionContent($id, 'fr');
        self::assertNotNull($liveFr);
        self::assertIsArray($liveFr['templateData']);
        self::assertSame('STALE FR', $liveFr['templateData']['title'] ?? null, 'Sanity check: the direct corruption must be visible before republishing de.');

        // Republish the MID-CHAIN shadow (de) without touching en. This exercises the
        // shadow-branch cascadeToShadows($sourceDimensionContent, ...) path in
        // PublishTransitionSubscriber::onPublish().
        $this->client->request(
            'PUT',
            '/admin/api/examples/' . $id . '?locale=de&action=publish',
            [], [], [],
            \json_encode([
                'template' => 'example-2',
                'title' => 'Root DE',
                'url' => '/root-de',
                'shadowOn' => true,
                'shadowLocale' => 'en',
            ]) ?: null
        );
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $liveFr = $this->getLiveDimensionContent($id, 'fr');
        self::assertNotNull($liveFr);
        self::assertIsArray($liveFr['templateData']);
        self::assertSame(
            'Root EN',
            $liveFr['templateData']['title'] ?? null,
            'Republishing the mid-chain shadow (de) must cascade to its dependent (fr).'
        );
    }

    public function testPublishOutOfOrderReturns400(): void
    {
        self::purgeDatabase();

        // en is created but never published.
        $this->client->request('POST', '/admin/api/examples?locale=en', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root EN',
            'url' => '/root-en',
        ]) ?: null);
        $this->assertHttpStatusCode(201, $this->client->getResponse());
        $id = $this->extractId();

        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=de', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root DE',
            'url' => '/root-de',
            'shadowOn' => true,
            'shadowLocale' => 'en',
        ]) ?: null);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=de&action=publish', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root DE',
            'url' => '/root-de',
            'shadowOn' => true,
            'shadowLocale' => 'en',
        ]) ?: null);

        $this->assertHttpStatusCode(400, $this->client->getResponse());
    }

    public function testSavingCycleReturns400(): void
    {
        self::purgeDatabase();

        $this->client->request('POST', '/admin/api/examples?locale=en', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root EN',
            'url' => '/root-en',
        ]) ?: null);
        $this->assertHttpStatusCode(201, $this->client->getResponse());
        $id = $this->extractId();

        // de shadows en: valid, succeeds.
        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=de', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root DE',
            'url' => '/root-de',
            'shadowOn' => true,
            'shadowLocale' => 'en',
        ]) ?: null);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        // en shadows de: would create a cycle (en -> de -> en). Must be rejected.
        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=en', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root EN',
            'url' => '/root-en',
            'shadowOn' => true,
            'shadowLocale' => 'de',
        ]) ?: null);

        $response = $this->client->getResponse();
        $this->assertHttpStatusCode(400, $response);

        $responseData = \json_decode((string) $response->getContent(), true);
        self::assertIsArray($responseData);
        self::assertSame(ShadowLocaleCycleException::EXCEPTION_CODE_SHADOW_LOCALE_CYCLE, $responseData['code'] ?? null);
    }

    /**
     * Creates en (root, published), de (shadow of en, published) and fr (shadow of de,
     * published), in that order, and returns the entity id.
     */
    private function createAndPublishChain(string $rootTitle): int
    {
        $this->client->request(
            'POST',
            '/admin/api/examples?locale=en&action=publish',
            [], [], [],
            \json_encode([
                'template' => 'example-2',
                'title' => $rootTitle,
                'url' => '/root-en',
            ]) ?: null
        );
        $this->assertHttpStatusCode(201, $this->client->getResponse());
        $id = $this->extractId();

        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=de', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root DE',
            'url' => '/root-de',
            'shadowOn' => true,
            'shadowLocale' => 'en',
        ]) ?: null);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=de&action=publish', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root DE',
            'url' => '/root-de',
            'shadowOn' => true,
            'shadowLocale' => 'en',
        ]) ?: null);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=fr', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root FR',
            'url' => '/root-fr',
            'shadowOn' => true,
            'shadowLocale' => 'de',
        ]) ?: null);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        $this->client->request('PUT', '/admin/api/examples/' . $id . '?locale=fr&action=publish', [], [], [], \json_encode([
            'template' => 'example-2',
            'title' => 'Root FR',
            'url' => '/root-fr',
            'shadowOn' => true,
            'shadowLocale' => 'de',
        ]) ?: null);
        $this->assertHttpStatusCode(200, $this->client->getResponse());

        return $id;
    }

    /**
     * Reads the "id" field from the last JSON response body.
     */
    private function extractId(): int
    {
        $content = \json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($content);
        $id = $content['id'] ?? null;
        self::assertIsInt($id);

        return $id;
    }

    /**
     * Directly overwrites the LIVE templateData of a locale, bypassing the publish pipeline
     * entirely, so a subsequent cascade can be proven to have actually written new content.
     *
     * @param array<string, mixed> $templateData
     */
    private function setLiveTemplateDataDirectly(int $id, string $locale, array $templateData): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $dimensionContent = $entityManager->createQueryBuilder()
            ->select('dimensionContent')
            ->from(ExampleDimensionContent::class, 'dimensionContent')
            ->where('IDENTITY(dimensionContent.example) = :id')
            ->andWhere('dimensionContent.stage = :stage')
            ->andWhere('dimensionContent.locale = :locale')
            ->andWhere('dimensionContent.version = 0')
            ->setParameter('id', $id)
            ->setParameter('stage', 'live')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(ExampleDimensionContent::class, $dimensionContent, 'Expected an existing LIVE dimension content to corrupt.');

        $dimensionContent->setTemplateData($templateData);
        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getLiveDimensionContent(int $id, string $locale): ?array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $row = $entityManager->createQueryBuilder()
            ->from(ExampleDimensionContent::class, 'dimensionContent')
            ->select(
                'dimensionContent.stage',
                'dimensionContent.locale',
                'dimensionContent.templateKey',
                'dimensionContent.workflowPublished',
                'dimensionContent.shadowLocale',
                'dimensionContent.templateData',
            )
            ->where('IDENTITY(dimensionContent.example) = :id')
            ->andWhere('dimensionContent.stage = :stage')
            ->andWhere('dimensionContent.locale = :locale')
            ->andWhere('dimensionContent.version = 0')
            ->setParameter('id', $id)
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
