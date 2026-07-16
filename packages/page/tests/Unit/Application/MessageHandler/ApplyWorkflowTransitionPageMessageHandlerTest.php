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

namespace Sulu\Page\Tests\Unit\Application\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Page\Application\MessageHandler\ApplyWorkflowTransitionPageMessageHandler;
use Sulu\Page\Domain\Model\Page;
use Sulu\Page\Domain\Model\PageDimensionContent;
use Sulu\Page\Domain\Repository\PageRepositoryInterface;

#[CoversClass(ApplyWorkflowTransitionPageMessageHandler::class)]
class ApplyWorkflowTransitionPageMessageHandlerTest extends TestCase
{
    use ProphecyTrait;

    private ApplyWorkflowTransitionPageMessageHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ApplyWorkflowTransitionPageMessageHandler(
            $this->prophesize(PageRepositoryInterface::class)->reveal(),
            $this->prophesize(ContentWorkflowInterface::class)->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal(),
            $this->prophesize(DomainEventCollectorInterface::class)->reveal(),
        );
    }

    /**
     * @param array<string, string> $shadowLocales locale => baseLocale
     */
    private function createPageWithUnlocalizedDraft(array $shadowLocales): Page
    {
        $page = new Page('page-123');

        $dimensionContent = new PageDimensionContent($page);
        $dimensionContent->setLocale(null);
        $dimensionContent->setStage(DimensionContentInterface::STAGE_DRAFT);
        foreach ($shadowLocales as $locale => $baseLocale) {
            $dimensionContent->addShadowLocale($locale, $baseLocale);
        }

        $page->addDimensionContent($dimensionContent);

        return $page;
    }

    /**
     * @return mixed[]
     */
    private function invokeResolveRelatedLocales(Page $page, string $locale): array
    {
        $method = new \ReflectionMethod(ApplyWorkflowTransitionPageMessageHandler::class, 'resolveRelatedLocales');

        $result = $method->invoke($this->handler, $page, $locale);
        self::assertIsArray($result);

        return $result;
    }

    public function testResolveRelatedLocalesReturnsTransitiveDependentsForRootLocale(): void
    {
        $page = $this->createPageWithUnlocalizedDraft(['de' => 'en', 'fr' => 'de']);

        $this->assertEqualsCanonicalizing(['de', 'fr'], $this->invokeResolveRelatedLocales($page, 'en'));
    }

    public function testResolveRelatedLocalesReturnsDependentsAndSourceForMidChainLocale(): void
    {
        $page = $this->createPageWithUnlocalizedDraft(['de' => 'en', 'fr' => 'de']);

        $this->assertEqualsCanonicalizing(['fr', 'en'], $this->invokeResolveRelatedLocales($page, 'de'));
    }
}
