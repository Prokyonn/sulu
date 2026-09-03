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

namespace Sulu\Content\Domain\Exception;

use Sulu\Component\Rest\Exception\TranslationErrorMessageExceptionInterface;

final class WorkflowTransitionRequestNotApprovedException extends \RuntimeException implements TranslationErrorMessageExceptionInterface
{
    private function __construct(
        private readonly string $translationKey,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function noRequest(): self
    {
        return new self(
            'sulu_content.workflow_transition_request.publish_blocked_no_request',
            'Publishing is blocked: no active workflow transition request exists for this content.',
        );
    }

    public static function notApproved(): self
    {
        return new self(
            'sulu_content.workflow_transition_request.publish_blocked_not_approved',
            'Publishing is blocked: the active workflow transition request has not been approved.',
        );
    }

    public function getMessageTranslationKey(): string
    {
        return $this->translationKey;
    }

    public function getMessageTranslationParameters(): array
    {
        return [];
    }
}
