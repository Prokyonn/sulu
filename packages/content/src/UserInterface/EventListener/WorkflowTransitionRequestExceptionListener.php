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

namespace Sulu\Content\UserInterface\EventListener;

use Sulu\Content\Domain\Exception\DuplicateActiveWorkflowTransitionRequestException;
use Sulu\Content\Domain\Exception\MissingAuthenticatedUserException;
use Sulu\Content\Domain\Exception\SelfReviewNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestCancelNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestClosedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestInProgressException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Maps workflow-transition-request domain exceptions to HTTP responses without dragging
 * Symfony HttpKernel into the Domain layer. The translation payload is rendered
 * by {@see \Sulu\Component\Rest\FlattenExceptionNormalizer} when the exception
 * implements TranslationErrorMessageExceptionInterface.
 *
 * @internal
 */
final class WorkflowTransitionRequestExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $httpException = $this->mapException($throwable);

        if (null === $httpException) {
            return;
        }

        $event->setThrowable($httpException);
    }

    private function mapException(\Throwable $throwable): ?HttpException
    {
        return match (true) {
            $throwable instanceof MissingAuthenticatedUserException => new TranslatableHttpException(401, $throwable),
            $throwable instanceof SelfReviewNotAllowedException,
            $throwable instanceof WorkflowTransitionRequestCancelNotAllowedException => new TranslatableHttpException(403, $throwable),
            $throwable instanceof WorkflowTransitionRequestClosedException => new TranslatableHttpException(400, $throwable),
            $throwable instanceof DuplicateActiveWorkflowTransitionRequestException,
            $throwable instanceof WorkflowTransitionRequestInProgressException,
            $throwable instanceof WorkflowTransitionRequestNotApprovedException => new TranslatableHttpException(409, $throwable),
            default => null,
        };
    }
}
