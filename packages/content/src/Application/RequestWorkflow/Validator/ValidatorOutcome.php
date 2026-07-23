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

/**
 * Per-validator outcome emitted by {@see RequestWorkflowEvaluatorInterface::evaluateOutcomes()}.
 * The aggregated {@see ValidationResult} only carries failures; this object also captures the
 * `passed` validators so consumers (e.g. the admin overlay) can present a complete checklist.
 */
final class ValidatorOutcome
{
    /**
     * @param list<ValidationFailure> $failures
     */
    public function __construct(
        public readonly string $validatorKey,
        public readonly bool $passed,
        public readonly bool $pending,
        public readonly array $failures,
    ) {
    }
}
