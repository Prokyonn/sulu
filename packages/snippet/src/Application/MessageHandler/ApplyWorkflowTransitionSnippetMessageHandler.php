<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Snippet\Application\MessageHandler;

use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Content\Application\ContentManager\ContentManagerInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Infrastructure\Doctrine\DimensionContentQueryEnhancer;
use Sulu\Snippet\Application\Message\ApplyWorkflowTransitionSnippetMessage;
use Sulu\Snippet\Domain\Event\SnippetWorkflowTransitionAppliedEvent;
use Sulu\Snippet\Domain\Model\SnippetInterface;
use Sulu\Snippet\Domain\Repository\SnippetRepositoryInterface;

/**
 * @internal This class should not be instantiated by a project.
 *           Create your own Message and Handler instead.
 */
final class ApplyWorkflowTransitionSnippetMessageHandler
{
    public function __construct(
        private SnippetRepositoryInterface $snippetRepository,
        private ContentManagerInterface $contentManager,
        private DomainEventCollectorInterface $domainEventCollector
    ) {
    }

    public function __invoke(ApplyWorkflowTransitionSnippetMessage $message): SnippetInterface
    {
        $snippet = $this->snippetRepository->getOneBy(
            $message->getIdentifier(),
            [
                SnippetRepositoryInterface::SELECT_SNIPPET_CONTENT => [
                    'selects' => [DimensionContentQueryEnhancer::GROUP_SELECT_CONTENT_ADMIN => true],
                    'dimensionAttributes' => [
                        'locale' => $message->getLocale(),
                        'stage' => [DimensionContentInterface::STAGE_DRAFT, DimensionContentInterface::STAGE_LIVE],
                    ],
                ],
            ]
        );

        $this->contentManager->applyTransition(
            $snippet,
            ['locale' => $message->getLocale()],
            $message->getTransitionName(),
            [ContentWorkflowInterface::FORCE_CONTEXT_KEY => $message->isForced()]
        );

        $this->domainEventCollector->collect(new SnippetWorkflowTransitionAppliedEvent($snippet, $message->getTransitionName(), $message->getLocale()));

        return $snippet;
    }
}
