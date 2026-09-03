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

namespace Sulu\Content\Application\Message;

/**
 * Runs the validators of a workflow transition request. Carries the id only, so the message survives
 * serialization onto a transport.
 *
 * Route this message to a transport to run every validator asynchronously, that routing entry is the
 * whole switch. Validators doing I/O beyond the local database must be routed this way; the transport
 * must be consumed through the admin kernel (`bin/adminconsole messenger:consume`).
 */
final class ValidateWorkflowTransitionRequestMessage
{
    public function __construct(private readonly string $workflowTransitionRequestId)
    {
    }

    public function getWorkflowTransitionRequestId(): string
    {
        return $this->workflowTransitionRequestId;
    }
}
