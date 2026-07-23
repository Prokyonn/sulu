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

namespace Sulu\Content\Application\RequestWorkflow\Validator;

final class ValidationFailure
{
    /**
     * @param array<string, scalar|null> $messageParameters
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $validatorKey,
        public readonly string $messageKey,
        public readonly array $messageParameters = [],
        public readonly array $details = [],
    ) {
    }
}
