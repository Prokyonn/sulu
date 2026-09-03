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

namespace Sulu\Content\Application\MessageHandler;

use Sulu\Content\Application\Message\ValidateWorkflowTransitionRequestMessage;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * Closes the loop the worker opened: once a validation message has used up the transport's retries,
 * the validators that still have not answered are rejected with the exception message, the same
 * comment the synchronous path stores right away.
 *
 * @internal
 */
final class ValidateWorkflowTransitionRequestFailureListener
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if ($event->willRetry() || !$message instanceof ValidateWorkflowTransitionRequestMessage) {
            return;
        }

        $request = $this->workflowTransitionRequestRepository->findOneBy([
            'id' => $message->getWorkflowTransitionRequestId(),
        ]);

        if (null === $request) {
            return;
        }

        $throwable = $event->getThrowable();
        // the worker reports the wrapping HandlerFailedException, the validator's own message is
        // the one a reviewer can act on
        if ($throwable instanceof HandlerFailedException) {
            $throwable = $throwable->getPrevious() ?? $throwable;
        }

        foreach ($request->getReviewers() as $reviewer) {
            if (!$reviewer->isValidator() || WorkflowTransitionRequestReviewerStatusEnum::PENDING !== $reviewer->getStatus()) {
                continue;
            }

            $this->workflowTransitionRequestRepository->settleValidatorReviewer(
                $reviewer,
                WorkflowTransitionRequestReviewerStatusEnum::REJECTED,
                'Check failed: ' . $throwable->getMessage(),
            );
        }
    }
}
