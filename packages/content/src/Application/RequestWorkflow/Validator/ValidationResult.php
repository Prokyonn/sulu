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

final class ValidationResult
{
    /**
     * @param list<ValidationFailure> $failures
     */
    private function __construct(
        public readonly bool $passed,
        public readonly bool $pending,
        public readonly array $failures,
    ) {
    }

    public static function pass(): self
    {
        return new self(true, false, []);
    }

    public static function fail(ValidationFailure ...$failures): self
    {
        return new self(false, false, \array_values($failures));
    }

    /**
     * The validator cannot produce a verdict yet — typically because an async check is in flight
     * (e.g. an external service or LLM call). Pending wins over failures during aggregation: the
     * workflow stays in PENDING and the request is neither approved nor reported as failed until
     * every validator has settled.
     */
    public static function pending(): self
    {
        return new self(false, true, []);
    }
}
