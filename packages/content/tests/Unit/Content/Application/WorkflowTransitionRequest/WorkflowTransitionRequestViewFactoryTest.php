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

namespace Sulu\Content\Tests\Unit\Content\Application\WorkflowTransitionRequest;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\WorkflowTransitionRequest\WorkflowTransitionRequestViewFactory;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

#[CoversClass(WorkflowTransitionRequestViewFactory::class)]
class WorkflowTransitionRequestViewFactoryTest extends TestCase
{
    use ProphecyTrait;

    public function testValidatorRowsComeFirstAndPeopleFollowInDecisionOrder(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(3);
        $request->addValidator('unpublished_references');
        $request->addValidator('llm_review');
        $request->getValidatorReviewer('unpublished_references')?->approve();
        $request->getValidatorReviewer('llm_review')?->reject('The intro contradicts the headline.');
        $request->addApproval($this->user(2, 'Reviewer A'), 'Fine by me');
        $request->addRejection($this->user(3, 'Reviewer B'), 'Please fix the intro');

        $view = $this->createFactory()->build($request);

        $this->assertSame('pending', $view['status']);
        $this->assertSame(['required' => 3, 'approved' => 2, 'rejected' => 2], $view['approvalProgress']);

        /** @var list<array<string, mixed>> $reviewers */
        $reviewers = $view['reviewers'];
        $this->assertSame(
            [
                ['validator', 'unpublished_references', 'approved'],
                ['validator', 'llm_review', 'rejected'],
                ['user', null, 'approved'],
                ['user', null, 'rejected'],
            ],
            \array_map(
                static fn (array $reviewer) => [$reviewer['type'], $reviewer['validatorKey'], $reviewer['status']],
                $reviewers,
            ),
        );

        $this->assertSame('The intro contradicts the headline.', $reviewers[1]['comment']);
        $this->assertNull($reviewers[1]['reviewer']);
        $this->assertSame(['id' => 2, 'fullName' => 'Reviewer A'], $reviewers[2]['reviewer']);
        $this->assertNotNull($reviewers[2]['decidedAt']);
    }

    public function testPendingValidatorRowCarriesNoDecision(): void
    {
        $request = $this->createRequest();
        $request->setRequiredApprovalCount(1);
        $request->addValidator('unpublished_references');

        $view = $this->createFactory()->build($request);

        /** @var list<array<string, mixed>> $reviewers */
        $reviewers = $view['reviewers'];
        $this->assertCount(1, $reviewers);
        $this->assertSame('pending', $reviewers[0]['status']);
        $this->assertNull($reviewers[0]['comment']);
        $this->assertNull($reviewers[0]['decidedAt']);
        $this->assertSame(['required' => 1, 'approved' => 0, 'rejected' => 0], $view['approvalProgress']);
        $this->assertSame(['id' => 1, 'fullName' => 'Creator'], $view['createdBy']);
    }

    private function createFactory(): WorkflowTransitionRequestViewFactory
    {
        return new WorkflowTransitionRequestViewFactory();
    }

    private function createRequest(): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest('pages', 'test-id', 'en');
        $request->setCreator($this->user(1, 'Creator'));

        return $request;
    }

    private function user(int $id, string $fullName): UserInterface
    {
        $user = $this->prophesize(UserInterface::class);
        $user->getId()->willReturn($id);
        $user->getFullName()->willReturn($fullName);

        return $user->reveal();
    }
}
