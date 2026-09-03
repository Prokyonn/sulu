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

use Sulu\Content\Domain\Model\DimensionContentInterface;

/**
 * Everything a prevalidator needs to decide whether content may enter review. Unlike the
 * validators that run against an existing request, the dimension content is always present:
 * prevalidation happens while the `request_for_review` transition is being applied, before any
 * {@see \Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest} exists.
 */
final class PrevalidationContext
{
    /**
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     * @param array<string, mixed> $config the resolved per-workflow config block for this prevalidator
     */
    public function __construct(
        public readonly DimensionContentInterface $dimensionContent,
        public readonly array $config,
        public readonly string $workflowName,
    ) {
    }
}
