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

namespace Sulu\Content\Application\RequestWorkflow;

use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\UserApprovalsValidator;
use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

/**
 * Resolved configuration for a single named workflow. Built once at container compile-time
 * via {@see RequestWorkflowRegistry} from the bundle config tree.
 */
final class RequestWorkflow
{
    public const DEFAULT_NAME = WorkflowTransitionRequest::DEFAULT_WORKFLOW_NAME;

    /**
     * @param list<array{validator: RequestWorkflowValidatorInterface, config: array<string, mixed>}> $validators
     * @param list<string> $resources resource keys this workflow covers as the implicit default; empty means all
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $label,
        public readonly array $validators,
        public readonly array $resources = [],
    ) {
    }

    /**
     * Whether this workflow covers the given resource key. An empty `resources` list means the
     * workflow is not restricted to specific resources.
     */
    public function appliesToResource(string $resourceKey): bool
    {
        return [] === $this->resources || \in_array($resourceKey, $this->resources, true);
    }

    /**
     * Number of approvals this workflow requires, read from the built-in `user_approvals` validator
     * config. Zero when the workflow has no approval gate. Snapshotted onto a request at creation.
     */
    public function getRequiredApprovalCount(): int
    {
        foreach ($this->validators as $entry) {
            if (UserApprovalsValidator::KEY === $entry['validator']->getKey()) {
                /** @var array{count?: int} $config */
                $config = $entry['config'];

                // Fall back to a single approval rather than zero: a configured approval gate
                // without an explicit count must never auto-approve.
                return $config['count'] ?? 1;
            }
        }

        return 0;
    }
}
