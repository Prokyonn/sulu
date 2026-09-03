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

/**
 * Thrown when a prevalidator rejects content on its way into review. The workflow transition is
 * aborted, so no workflow transition request is created.
 *
 * The admin shows one message, so every failure is translated where the prevalidators run and
 * joined into one line. That line is handed over as the translation id, because a translator
 * returns an id it does not know unchanged, and a container translation around it would only
 * repeat what the individual messages already say.
 */
class WorkflowTransitionRequestPrevalidationFailedException extends \RuntimeException implements TranslationErrorMessageExceptionInterface
{
    public function __construct(
        private readonly string $translatedMessages,
    ) {
        parent::__construct('Content cannot be sent to review: ' . $translatedMessages);
    }

    public function getMessageTranslationKey(): string
    {
        return $this->translatedMessages;
    }

    public function getMessageTranslationParameters(): array
    {
        return [];
    }
}
