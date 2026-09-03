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

use Psr\Log\LoggerInterface;
use Sulu\Content\Application\Message\ValidateWorkflowTransitionRequestMessage;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationDecision;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Sulu\Content\Domain\Repository\WorkflowTransitionRequestRepositoryInterface;

/**
 * Runs every validator that still has a pending row and records its verdict on that row.
 *
 * A validator that crashes rejects with the exception message, so the reviewer is never left without
 * an answer. On a worker the exception escapes instead, so the transport's retry strategy gets its
 * turn; {@see ValidateWorkflowTransitionRequestFailureListener} writes the rejection once the
 * retries are used up.
 */
final class ValidateWorkflowTransitionRequestMessageHandler
{
    public function __construct(
        private readonly WorkflowTransitionRequestRepositoryInterface $workflowTransitionRequestRepository,
        private readonly RequestWorkflowRegistryInterface $requestWorkflowRegistry,
        private readonly LoggerInterface $logger,
        private readonly WorkerState $workerState,
    ) {
    }

    public function __invoke(ValidateWorkflowTransitionRequestMessage $message): void
    {
        $request = $this->workflowTransitionRequestRepository->findOneBy([
            'id' => $message->getWorkflowTransitionRequestId(),
        ]);

        if (null === $request || !$request->isOpen()) {
            return;
        }

        $workflowName = $request->getWorkflowName();
        if (!$this->requestWorkflowRegistry->has($workflowName)) {
            $this->logger->warning('Request workflow "{workflow}" is not registered, request "{request}" keeps its pending validators.', [
                'workflow' => $workflowName,
                'request' => $request->getId(),
            ]);

            return;
        }

        $entriesByKey = $this->requestWorkflowRegistry->get($workflowName)->validators;

        foreach ($request->getReviewers() as $reviewer) {
            if (!$reviewer->isValidator() || WorkflowTransitionRequestReviewerStatusEnum::PENDING !== $reviewer->getStatus()) {
                continue;
            }

            /** @var string $validatorKey */
            $validatorKey = $reviewer->getValidatorKey();
            $entry = $entriesByKey[$validatorKey] ?? null;

            if (null === $entry) {
                $decision = ValidationDecision::reject(\sprintf("Check failed: validator '%s' is not registered", $validatorKey));
            } else {
                try {
                    $decision = $entry['validator']->check(new ValidationContext($request, $entry['config']));
                } catch (\Throwable $throwable) {
                    if ($this->workerState->isRunning()) {
                        throw $throwable;
                    }

                    $this->logger->error('Request workflow validator "{validator}" failed on request "{request}".', [
                        'validator' => $validatorKey,
                        'request' => $request->getId(),
                        'exception' => $throwable,
                    ]);

                    $decision = ValidationDecision::reject('Check failed: ' . $throwable->getMessage());
                }
            }

            $this->workflowTransitionRequestRepository->settleValidatorReviewer(
                $reviewer,
                $decision->approved
                    ? WorkflowTransitionRequestReviewerStatusEnum::APPROVED
                    : WorkflowTransitionRequestReviewerStatusEnum::REJECTED,
                $decision->comment,
            );
        }
    }
}
