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

namespace Sulu\Content\Application\RequestWorkflow\Prevalidator;

final class PrevalidationFailure
{
    /**
     * @param array<string, float|int|string> $messageParameters
     */
    public function __construct(
        public readonly string $messageKey,
        public readonly array $messageParameters = [],
    ) {
    }

    /**
     * @return array<string, float|int|string>
     */
    public function getTranslationParameters(): array
    {
        $parameters = [];
        foreach ($this->messageParameters as $name => $value) {
            $parameters['{' . $name . '}'] = $value;
        }

        return $parameters;
    }
}
