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

interface RequestWorkflowRegistryInterface
{
    /**
     * @throws UnknownRequestWorkflowException when no workflow is registered for the given name
     */
    public function get(string $name): RequestWorkflow;

    public function has(string $name): bool;

    /**
     * @return list<RequestWorkflow>
     */
    public function all(): array;
}
