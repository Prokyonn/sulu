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

use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

/**
 * Carries everything a validator needs to evaluate a request without forcing it to
 * fetch the dimension content itself. The dimension content may be `null` when the
 * evaluator is invoked outside a workflow transition (e.g. on reviewer changes via
 * the REST controller); validators that need it must short-circuit gracefully.
 */
final class ValidationContext
{
    /**
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param array<string, mixed> $validatorConfig the resolved per-workflow config block for this validator
     * @param DimensionContentInterface<T>|null $dimensionContent
     */
    public function __construct(
        public readonly WorkflowTransitionRequest $request,
        public readonly array $validatorConfig,
        public readonly ?DimensionContentInterface $dimensionContent = null,
    ) {
    }
}
