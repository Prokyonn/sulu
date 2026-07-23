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

use Sulu\Component\Rest\Exception\TranslationErrorMessageExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal
 */
final class TranslatableHttpException extends HttpException implements TranslationErrorMessageExceptionInterface
{
    public function __construct(
        int $statusCode,
        private readonly TranslationErrorMessageExceptionInterface&\Throwable $translationException,
    ) {
        parent::__construct($statusCode, $translationException->getMessage(), $translationException);
    }

    public function getMessageTranslationKey(): string
    {
        return $this->translationException->getMessageTranslationKey();
    }

    public function getMessageTranslationParameters(): array
    {
        return $this->translationException->getMessageTranslationParameters();
    }
}
