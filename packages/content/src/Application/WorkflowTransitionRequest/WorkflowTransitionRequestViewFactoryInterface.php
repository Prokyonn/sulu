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

namespace Sulu\Content\Application\WorkflowTransitionRequest;

use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

/**
 * Builds the single canonical array representation of a {@see WorkflowTransitionRequest} shared by the
 * content normalizer (form view) and the admin controller (by-id / history). Passing the current draft
 * dimension content additionally surfaces the `publishValidation` checklist; without it that block is null.
 */
interface WorkflowTransitionRequestViewFactoryInterface
{
    /**
     * @template T of \Sulu\Content\Domain\Model\ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T>|null $dimensionContent
     *
     * @return array<string, mixed>
     */
    public function build(WorkflowTransitionRequest $request, ?DimensionContentInterface $dimensionContent = null): array;
}
