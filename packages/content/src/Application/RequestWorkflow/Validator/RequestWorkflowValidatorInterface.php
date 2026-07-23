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

use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * Pluggable validator that contributes both:
 *   - a config schema (e.g. `user_approvals.count: 2`) that hosts can supply per workflow, and
 *   - a runtime check that reports whether the workflow transition request satisfies its rule.
 *
 * Validators are autoconfigured via tag `sulu_content.request_workflow_validator`.
 */
interface RequestWorkflowValidatorInterface
{
    /**
     * The YAML config key for this validator (e.g. `user_approvals`). Must be unique across
     * all registered validators.
     */
    public function getKey(): string;

    /**
     * Contribute this validator's config schema to the parent `validators:` node.
     * Implementations should add a single child node whose name matches `getKey()`.
     */
    public function configure(NodeBuilder $builder): void;

    /**
     * Run the validator against the request. Pure function: must not mutate the request
     * or trigger side effects (DB writes, message dispatches).
     */
    public function check(ValidationContext $context): ValidationResult;
}
