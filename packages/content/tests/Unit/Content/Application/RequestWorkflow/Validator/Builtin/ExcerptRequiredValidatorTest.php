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

namespace Sulu\Content\Tests\Unit\Content\Application\RequestWorkflow\Validator\Builtin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\ExcerptRequiredValidator;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\ExcerptInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

#[CoversClass(ExcerptRequiredValidator::class)]
class ExcerptRequiredValidatorTest extends TestCase
{
    use ProphecyTrait;

    private function createRequest(): WorkflowTransitionRequest
    {
        $request = new WorkflowTransitionRequest('pages', 'test-id', 'en');
        $request->setCreator($this->prophesize(UserInterface::class)->reveal());

        return $request;
    }

    public function testPassesWhenDimensionContentIsNull(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();
        $context = new ValidationContext($request, ['fields' => ['title', 'description']]);

        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testPassesWhenContentDoesNotImplementExcerptInterface(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(DimensionContentInterface::class)->reveal();
        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent,
        );

        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testPassesWhenAllConfiguredExcerptFieldsAreFilled(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(ExcerptInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getExcerptTitle()->willReturn('Excerpt Title');
        $dimensionContent->getExcerptDescription()->willReturn('Excerpt description text');

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testFailsWhenExcerptTitleIsMissing(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(ExcerptInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getExcerptTitle()->willReturn(null);
        $dimensionContent->getExcerptDescription()->willReturn('Some description');

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertSame(ExcerptRequiredValidator::KEY, $result->failures[0]->validatorKey);
        $this->assertSame('sulu_content.workflow_transition_request.excerpt_required.missing', $result->failures[0]->messageKey);
        $this->assertContains('title', (array) $result->failures[0]->details['missing']);
    }

    public function testFailsWhenExcerptDescriptionIsEmptyWhitespace(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(ExcerptInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getExcerptTitle()->willReturn('My Excerpt');
        $dimensionContent->getExcerptDescription()->willReturn('   ');

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertContains('description', (array) $result->failures[0]->details['missing']);
    }

    public function testFailsListingAllMissingExcerptFields(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(ExcerptInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getExcerptTitle()->willReturn('');
        $dimensionContent->getExcerptDescription()->willReturn(null);

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        /** @var array<string> $missing */
        $missing = $result->failures[0]->details['missing'];
        $this->assertContains('title', $missing);
        $this->assertContains('description', $missing);
    }

    public function testPassesWhenMoreFieldFilledWithMoreConfigured(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(ExcerptInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getExcerptMore()->willReturn('Read more text');

        $context = new ValidationContext(
            $request,
            ['fields' => ['more']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testFailsWhenMoreFieldIsMissing(): void
    {
        $validator = new ExcerptRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(ExcerptInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getExcerptMore()->willReturn(null);

        $context = new ValidationContext(
            $request,
            ['fields' => ['more']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertContains('more', (array) $result->failures[0]->details['missing']);
    }

    public function testGetKey(): void
    {
        $validator = new ExcerptRequiredValidator();
        $this->assertSame('excerpt_required', $validator->getKey());
    }
}
