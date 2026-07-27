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
 * Pluggable rule that reports whether a workflow transition request satisfies it.
 *
 * Validators are registered with tag `sulu_content.request_workflow_validator` and a `key`
 * attribute matching {@see self::getKey()}. Their per-workflow config (e.g. `user_approvals.count`)
 * is passed through from the bundle config unvalidated, so every implementation must supply its own
 * defaults in {@see self::check()} and fail closed when a setting is missing.
 */
interface RequestWorkflowValidatorInterface
{
    /**
     * The YAML config key for this validator (e.g. `user_approvals`). Must be unique across
     * all registered validators.
     */
    public function getKey(): string;

    /**
     * Run the validator against the request. Pure function: must not mutate the request
     * or trigger side effects (DB writes, message dispatches).
     */
    public function check(ValidationContext $context): ValidationResult;
}
