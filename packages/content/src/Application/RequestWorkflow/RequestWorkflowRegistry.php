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

use Sulu\Content\Domain\Exception\UnknownRequestWorkflowException;

final class RequestWorkflowRegistry implements RequestWorkflowRegistryInterface
{
    /**
     * @var array<string, RequestWorkflow>
     */
    private readonly array $workflows;

    /**
     * @param iterable<RequestWorkflow> $workflows
     */
    public function __construct(iterable $workflows)
    {
        $byName = [];
        foreach ($workflows as $workflow) {
            $byName[$workflow->name] = $workflow;
        }
        $this->workflows = $byName;
    }

    public function get(string $name): RequestWorkflow
    {
        if (!isset($this->workflows[$name])) {
            throw new UnknownRequestWorkflowException($name);
        }

        return $this->workflows[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->workflows[$name]);
    }

    public function all(): array
    {
        return \array_values($this->workflows);
    }
}
