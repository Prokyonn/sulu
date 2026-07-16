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

namespace Sulu\Article\Tests\Unit\Application\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Article\Application\MessageHandler\ApplyWorkflowTransitionArticleMessageHandler;
use Sulu\Article\Domain\Model\Article;
use Sulu\Article\Domain\Model\ArticleDimensionContent;
use Sulu\Article\Domain\Repository\ArticleRepositoryInterface;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;

#[CoversClass(ApplyWorkflowTransitionArticleMessageHandler::class)]
class ApplyWorkflowTransitionArticleMessageHandlerTest extends TestCase
{
    use ProphecyTrait;

    private ApplyWorkflowTransitionArticleMessageHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ApplyWorkflowTransitionArticleMessageHandler(
            $this->prophesize(ArticleRepositoryInterface::class)->reveal(),
            $this->prophesize(ContentWorkflowInterface::class)->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal(),
            $this->prophesize(DomainEventCollectorInterface::class)->reveal(),
        );
    }

    /**
     * @param array<string, string> $shadowLocales locale => baseLocale
     */
    private function createArticleWithUnlocalizedDraft(array $shadowLocales): Article
    {
        $article = new Article('article-123');

        $dimensionContent = new ArticleDimensionContent($article);
        $dimensionContent->setLocale(null);
        $dimensionContent->setStage(DimensionContentInterface::STAGE_DRAFT);
        foreach ($shadowLocales as $locale => $baseLocale) {
            $dimensionContent->addShadowLocale($locale, $baseLocale);
        }

        $article->addDimensionContent($dimensionContent);

        return $article;
    }

    /**
     * @return mixed[]
     */
    private function invokeResolveRelatedLocales(Article $article, string $locale): array
    {
        $method = new \ReflectionMethod(ApplyWorkflowTransitionArticleMessageHandler::class, 'resolveRelatedLocales');

        $result = $method->invoke($this->handler, $article, $locale);
        self::assertIsArray($result);

        return $result;
    }

    public function testResolveRelatedLocalesReturnsTransitiveDependentsForRootLocale(): void
    {
        $article = $this->createArticleWithUnlocalizedDraft(['de' => 'en', 'fr' => 'de']);

        $this->assertEqualsCanonicalizing(['de', 'fr'], $this->invokeResolveRelatedLocales($article, 'en'));
    }

    public function testResolveRelatedLocalesReturnsDependentsAndSourceForMidChainLocale(): void
    {
        $article = $this->createArticleWithUnlocalizedDraft(['de' => 'en', 'fr' => 'de']);

        $this->assertEqualsCanonicalizing(['fr', 'en'], $this->invokeResolveRelatedLocales($article, 'de'));
    }
}
