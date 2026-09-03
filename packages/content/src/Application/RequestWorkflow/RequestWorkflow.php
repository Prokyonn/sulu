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

use Sulu\Content\Application\RequestWorkflow\Prevalidator\RequestWorkflowPrevalidatorInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

/**
 * Resolved configuration for a single named workflow, built by {@see RequestWorkflowRegistry}
 * from the bundle config tree.
 */
final class RequestWorkflow
{
    public const DEFAULT_NAME = WorkflowTransitionRequest::DEFAULT_WORKFLOW_NAME;

    /**
     * Reserved: a template tagged with this name opts out of review, so no workflow may carry it.
     */
    public const NONE_NAME = 'none';

    /**
     * @param array<string, array{validator: RequestWorkflowValidatorInterface, config: array<string, mixed>}> $validators keyed by the validator's tag key, which is also its key on the reviewer rows
     * @param list<string> $resources resource keys this workflow covers as the implicit default; empty means all, only the `default` workflow reads it
     * @param array<string, array{prevalidator: RequestWorkflowPrevalidatorInterface, config: array<string, mixed>}> $prevalidators sync rules that must pass before a request is created at all, keyed by tag key
     */
    public function __construct(
        public readonly string $name,
        public readonly array $validators,
        public readonly array $resources = [],
        public readonly array $prevalidators = [],
        private readonly int $requiredApprovalCount = 1,
    ) {
    }

    public function appliesToResource(string $resourceKey): bool
    {
        return [] === $this->resources || \in_array($resourceKey, $this->resources, true);
    }

    /**
     * Snapshotted onto a request at creation, so a config change does not move the gate under an
     * in-flight review. Zero lets the validators alone decide.
     */
    public function getRequiredApprovalCount(): int
    {
        return $this->requiredApprovalCount;
    }
}
