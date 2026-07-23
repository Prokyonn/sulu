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

/**
 * Resolved configuration for a single named workflow. Built once at container compile-time
 * via {@see RequestWorkflowRegistry} from the bundle config tree.
 */
final class RequestWorkflow
{
    public const DEFAULT_NAME = 'default';

    /**
     * @param list<array{validator: RequestWorkflowValidatorInterface, config: array<string, mixed>}> $validators
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $label,
        public readonly array $validators,
    ) {
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

                return $config['count'] ?? 0;
            }
        }

        return 0;
    }
}
