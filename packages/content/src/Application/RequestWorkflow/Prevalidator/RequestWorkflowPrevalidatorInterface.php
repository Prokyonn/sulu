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

/**
 * A rule that content must satisfy *before* it can be sent to review at all.
 *
 * Prevalidators are always synchronous and always run inside the `request_for_review` transition:
 * a failing one aborts the transition, so no workflow transition request is ever created. Use them
 * for cheap, deterministic checks on the content itself (SEO, excerpt, required fields). Anything
 * slow, external or human belongs in a validator instead, which runs against an existing request
 * and can be moved to an async transport.
 *
 * Implementing the interface is enough to register a prevalidator: autoconfiguration adds the
 * `sulu_content.request_workflow_prevalidator` tag and takes the key from {@see self::getKey()}.
 * Per-workflow config is passed through from the bundle config unvalidated, so every implementation
 * must supply its own defaults and fail closed when a setting is missing.
 */
interface RequestWorkflowPrevalidatorInterface
{
    /**
     * The name this prevalidator is referenced by in the workflow config. Unique across all
     * prevalidators, a duplicate is reported at compile time.
     */
    public static function getKey(): string;

    /**
     * Run the rule against the content. Pure function: must not mutate the content or trigger side
     * effects (DB writes, message dispatches).
     *
     * @return list<PrevalidationFailure> empty when the content may enter review
     */
    public function check(PrevalidationContext $context): array;
}
