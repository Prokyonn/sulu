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
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Application\Security\WorkflowTransitionAuthorizerInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
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
        private ContentWorkflowInterface $contentWorkflow,
        private DomainEventCollectorInterface $domainEventCollector,
        private ?WorkflowTransitionAuthorizerInterface $workflowTransitionAuthorizer = null,
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

        // Authorized here so every caller of the bus is covered; null outside the admin context.
        // Relies on the message staying synchronous: on a worker there is no token to check.
        if (WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH === $message->getTransitionName()) {
            $this->workflowTransitionAuthorizer?->assertCanPublish(SnippetInterface::RESOURCE_KEY, $snippet->getUuid(), $message->getLocale());
        } elseif (\in_array($message->getTransitionName(), [
            WorkflowInterface::WORKFLOW_TRANSITION_REJECT,
            WorkflowInterface::WORKFLOW_TRANSITION_REJECT_DRAFT,
        ], true)) {
            $this->workflowTransitionAuthorizer?->assertCanReject(SnippetInterface::RESOURCE_KEY, $snippet->getUuid(), $message->getLocale());
        }

        $this->contentWorkflow->apply(
            $snippet,
            ['locale' => $message->getLocale()],
            $message->getTransitionName()
        );

        $this->domainEventCollector->collect(new SnippetWorkflowTransitionAppliedEvent($snippet, $message->getTransitionName(), $message->getLocale()));

        return $snippet;
    }
}
