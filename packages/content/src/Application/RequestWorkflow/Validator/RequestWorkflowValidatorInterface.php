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
 * Pluggable rule that reviews a workflow transition request like a person does: it approves or it
 * rejects with a comment, and its approval counts towards the workflow's `required_approvals`.
 *
 * Implementing the interface is enough to register a validator: autoconfiguration adds the
 * `sulu_content.request_workflow_validator` tag and takes the key from {@see self::getKey()}. Their
 * per-workflow config is passed through from the bundle config unvalidated, so every implementation
 * must supply its own defaults and fail closed when a setting is missing.
 */
interface RequestWorkflowValidatorInterface
{
    /**
     * The name this validator is referenced by in the workflow config and stored under on the
     * reviewer rows. Unique across all validators, a duplicate is reported at compile time.
     */
    public static function getKey(): string;

    /**
     * Review the request. Must not mutate the request or write to the database: the caller owns the
     * reviewer row, and a validator may be retried.
     *
     * Anything beyond the request itself, the content, a remote service, is loaded through this
     * validator's own dependencies, so the verdict does not depend on whether it ran inline or on a
     * worker. Throwing is recorded as a rejection carrying the exception message.
     */
    public function check(ValidationContext $context): ValidationDecision;
}
