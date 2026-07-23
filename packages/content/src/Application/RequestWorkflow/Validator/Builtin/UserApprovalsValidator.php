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

namespace Sulu\Content\Application\RequestWorkflow\Validator\Builtin;

use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationFailure;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationResult;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;

/**
 * Requires N distinct user approvals (default 1). Rejections are non-blocking feedback and do not
 * count toward the required approvals — publishing is gated solely on the approval count.
 */
final class UserApprovalsValidator implements RequestWorkflowValidatorInterface
{
    public const KEY = 'user_approvals';

    public function getKey(): string
    {
        return self::KEY;
    }

    public function configure(NodeBuilder $builder): void
    {
        $builder
            ->arrayNode(self::KEY)
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('count')->min(1)->defaultValue(1)->end()
                ->end()
            ->end();
    }

    public function check(ValidationContext $context): ValidationResult
    {
        /** @var array{count: int} $config */
        $config = $context->validatorConfig;
        // Prefer the count snapshotted onto the request at creation so the gate stays stable even if
        // the workflow config changes mid-review; fall back to live config when no snapshot exists.
        $required = $context->request->getRequiredApprovalCount() ?? $config['count'];

        $approvals = 0;
        foreach ($context->request->getReviewers() as $reviewer) {
            if (WorkflowTransitionRequestReviewerStatusEnum::APPROVED === $reviewer->getStatus()) {
                ++$approvals;
            }
        }

        if ($approvals >= $required) {
            return ValidationResult::pass();
        }

        return ValidationResult::fail(new ValidationFailure(
            self::KEY,
            'sulu_content.workflow_transition_request.user_approvals.insufficient',
            ['required' => $required, 'current' => $approvals],
            ['required' => $required, 'current' => $approvals],
        ));
    }
}
