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

use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

/**
 * What a validator is given: the request under review, which carries the resource key, id and locale
 * of the content, plus its own configuration.
 *
 * It deliberately carries no dimension content. A validator may run in a worker without an HTTP
 * request, so anything it needs beyond the request itself it loads through its own dependencies,
 * which keeps its verdict the same inline and on a worker.
 */
final class ValidationContext
{
    /**
     * @param array<string, mixed> $validatorConfig
     */
    public function __construct(
        public readonly WorkflowTransitionRequest $request,
        public readonly array $validatorConfig,
    ) {
    }
}
