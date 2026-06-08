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
 * Thrown when a shadow locale is published while the locale it shadows (its source) has no
 * published content yet. A published shadow mirrors the published content of its source, so
 * the source has to be published first.
 */
class ShadowSourceNotPublishedException extends \Exception implements TranslationErrorMessageExceptionInterface
{
    public const EXCEPTION_CODE_SHADOW_SOURCE_NOT_PUBLISHED = 1107;

    public function __construct(
        private string $locale,
        private string $shadowLocale,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                'The shadow locale "%s" cannot be published because its source locale "%s" has not been published yet.',
                $this->locale,
                $this->shadowLocale,
            ),
            self::EXCEPTION_CODE_SHADOW_SOURCE_NOT_PUBLISHED,
            $previous,
        );
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getShadowLocale(): string
    {
        return $this->shadowLocale;
    }

    public function getMessageTranslationKey(): string
    {
        return 'sulu_content.shadow_source_not_published';
    }

    /**
     * @return array<string, string>
     */
    public function getMessageTranslationParameters(): array
    {
        return [
            '{locale}' => $this->locale,
            '{shadowLocale}' => $this->shadowLocale,
        ];
    }
}
