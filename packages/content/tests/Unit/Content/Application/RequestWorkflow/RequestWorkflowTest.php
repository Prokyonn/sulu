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

namespace Sulu\Content\Tests\Unit\Content\Application\RequestWorkflow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\UserApprovalsValidator;

#[CoversClass(RequestWorkflow::class)]
class RequestWorkflowTest extends TestCase
{
    public function testGetRequiredApprovalCountReadsUserApprovalsConfig(): void
    {
        $workflow = new RequestWorkflow('default', null, [
            ['validator' => new UserApprovalsValidator(), 'config' => ['count' => 3]],
        ]);

        $this->assertSame(3, $workflow->getRequiredApprovalCount());
    }

    public function testGetRequiredApprovalCountIsZeroWithoutUserApprovalsValidator(): void
    {
        $workflow = new RequestWorkflow('default', null, []);

        $this->assertSame(0, $workflow->getRequiredApprovalCount());
    }
}
