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
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowEvaluator;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationFailure;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationResult;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestStatusEnum;

#[CoversClass(RequestWorkflowEvaluator::class)]
class RequestWorkflowEvaluatorTest extends TestCase
{
    use ProphecyTrait;

    private function createRequest(string $workflowName = RequestWorkflow::DEFAULT_NAME): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest('pages', 'test-id', 'en', $workflowName);
        $request->setCreator($this->prophesize(UserInterface::class)->reveal());

        return $request;
    }

    private function makePassingValidator(string $key = 'passing'): RequestWorkflowValidatorInterface
    {
        $validator = $this->prophesize(RequestWorkflowValidatorInterface::class);
        $validator->getKey()->willReturn($key);
        $validator->check(\Prophecy\Argument::type(ValidationContext::class))->willReturn(ValidationResult::pass());

        return $validator->reveal();
    }

    private function makeFailingValidator(ValidationFailure $failure, string $key = 'failing'): RequestWorkflowValidatorInterface
    {
        $validator = $this->prophesize(RequestWorkflowValidatorInterface::class);
        $validator->getKey()->willReturn($key);
        $validator->check(\Prophecy\Argument::type(ValidationContext::class))->willReturn(ValidationResult::fail($failure));

        return $validator->reveal();
    }

    private function makePendingValidator(string $key = 'pending'): RequestWorkflowValidatorInterface
    {
        $validator = $this->prophesize(RequestWorkflowValidatorInterface::class);
        $validator->getKey()->willReturn($key);
        $validator->check(\Prophecy\Argument::type(ValidationContext::class))->willReturn(ValidationResult::pending());

        return $validator->reveal();
    }

    private function makeRegistry(RequestWorkflow $workflow): RequestWorkflowRegistryInterface
    {
        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->get($workflow->name)->willReturn($workflow);

        return $registry->reveal();
    }

    public function testEvaluateWithNoValidatorsPassesImmediately(): void
    {
        $workflow = new RequestWorkflow(RequestWorkflow::DEFAULT_NAME, null, []);
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $result = $evaluator->evaluate($request);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->failures);
    }

    public function testEvaluateWithAllPassingValidatorsReturnsPass(): void
    {
        $validator = $this->makePassingValidator();
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [['validator' => $validator, 'config' => []]],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $result = $evaluator->evaluate($request);

        $this->assertTrue($result->passed);
    }

    public function testEvaluateWithOneFailingValidatorReturnsFail(): void
    {
        $failure = new ValidationFailure('some_key', 'some.message.key');
        $failingValidator = $this->makeFailingValidator($failure);
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [['validator' => $failingValidator, 'config' => []]],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $result = $evaluator->evaluate($request);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertSame($failure, $result->failures[0]);
    }

    public function testEvaluateAggregatesFailuresAcrossMultipleValidators(): void
    {
        $failure1 = new ValidationFailure('key1', 'message.key1');
        $failure2 = new ValidationFailure('key2', 'message.key2');

        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [
                ['validator' => $this->makeFailingValidator($failure1), 'config' => []],
                ['validator' => $this->makeFailingValidator($failure2), 'config' => []],
            ],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $result = $evaluator->evaluate($request);

        $this->assertFalse($result->passed);
        $this->assertCount(2, $result->failures);
        $this->assertContains($failure1, $result->failures);
        $this->assertContains($failure2, $result->failures);
    }

    public function testEvaluateWithPendingValidatorReturnsPending(): void
    {
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [
                ['validator' => $this->makePassingValidator(), 'config' => []],
                ['validator' => $this->makePendingValidator(), 'config' => []],
            ],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $result = $evaluator->evaluate($request);

        $this->assertFalse($result->passed);
        $this->assertTrue($result->pending);
        $this->assertSame([], $result->failures);
    }

    public function testEvaluatePendingWinsOverFailures(): void
    {
        $failure = new ValidationFailure('failed_key', 'message.failed');
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [
                ['validator' => $this->makeFailingValidator($failure), 'config' => []],
                ['validator' => $this->makePendingValidator(), 'config' => []],
            ],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $result = $evaluator->evaluate($request);

        $this->assertFalse($result->passed);
        $this->assertTrue($result->pending);
        $this->assertSame([], $result->failures);
    }

    public function testEvaluateOutcomesSurfacesPendingPerValidator(): void
    {
        $failure = new ValidationFailure('failed_key', 'message.failed');
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [
                ['validator' => $this->makePassingValidator(), 'config' => []],
                ['validator' => $this->makePendingValidator(), 'config' => []],
                ['validator' => $this->makeFailingValidator($failure), 'config' => []],
            ],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $outcomes = $evaluator->evaluateOutcomes($request);

        $this->assertCount(3, $outcomes);
        $this->assertTrue($outcomes[0]->passed);
        $this->assertFalse($outcomes[0]->pending);
        $this->assertFalse($outcomes[1]->passed);
        $this->assertTrue($outcomes[1]->pending);
        $this->assertFalse($outcomes[2]->passed);
        $this->assertFalse($outcomes[2]->pending);
        $this->assertCount(1, $outcomes[2]->failures);
    }

    public function testRefreshStatusIsNoOpWhenRequestIsCancelled(): void
    {
        $workflow = new RequestWorkflow(RequestWorkflow::DEFAULT_NAME, null, []);
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();
        $request->cancel();

        $evaluator->refreshStatus($request);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::CANCELLED, $request->getStatus());
    }

    public function testRefreshStatusIsNoOpWhenRequestIsPublished(): void
    {
        $workflow = new RequestWorkflow(RequestWorkflow::DEFAULT_NAME, null, []);
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        // Need 1 approval to get to APPROVED, then publish
        $approver = $this->prophesize(UserInterface::class)->reveal();
        $request->addApproval($approver);
        $request->publish();

        $evaluator->refreshStatus($request);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::PUBLISHED, $request->getStatus());
    }

    public function testRefreshStatusSetsRejectedWhenBelowThresholdAndRejected(): void
    {
        $failure = new ValidationFailure('user_approvals', 'some.message');
        $validator = $this->makeFailingValidator($failure);
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [['validator' => $validator, 'config' => []]],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $rejecter = $this->prophesize(UserInterface::class)->reveal();
        $request->addRejection($rejecter, 'Needs changes');

        $evaluator->refreshStatus($request);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::REJECTED, $request->getStatus());
    }

    public function testRefreshStatusApprovedWinsOverRejectionWhenThresholdMet(): void
    {
        $validator = $this->makePassingValidator();
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [['validator' => $validator, 'config' => []]],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $rejecter = $this->prophesize(UserInterface::class)->reveal();
        $request->addRejection($rejecter, 'Needs changes');

        $evaluator->refreshStatus($request);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::APPROVED, $request->getStatus());
    }

    public function testRefreshStatusSetsApprovedWhenValidatorsPass(): void
    {
        $validator = $this->makePassingValidator();
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [['validator' => $validator, 'config' => []]],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $evaluator->refreshStatus($request);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::APPROVED, $request->getStatus());
    }

    public function testRefreshStatusSetsPendingWhenValidatorsFail(): void
    {
        $failure = new ValidationFailure('some_key', 'some.message');
        $failingValidator = $this->makeFailingValidator($failure);
        $workflow = new RequestWorkflow(
            RequestWorkflow::DEFAULT_NAME,
            null,
            [['validator' => $failingValidator, 'config' => []]],
        );
        $evaluator = new RequestWorkflowEvaluator($this->makeRegistry($workflow));
        $request = $this->createRequest();

        $evaluator->refreshStatus($request);

        $this->assertSame(WorkflowTransitionRequestStatusEnum::PENDING, $request->getStatus());
    }
}
