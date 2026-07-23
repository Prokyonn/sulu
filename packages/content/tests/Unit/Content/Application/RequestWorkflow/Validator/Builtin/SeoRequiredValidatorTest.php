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
use Sulu\Content\Application\RequestWorkflow\Validator\Builtin\SeoRequiredValidator;
use Sulu\Content\Application\RequestWorkflow\Validator\ValidationContext;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\SeoInterface;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;

#[CoversClass(SeoRequiredValidator::class)]
class SeoRequiredValidatorTest extends TestCase
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
        $validator = new SeoRequiredValidator();
        $request = $this->createRequest();
        $context = new ValidationContext($request, ['fields' => ['title', 'description']]);

        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testPassesWhenContentDoesNotImplementSeoInterface(): void
    {
        $validator = new SeoRequiredValidator();
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

    public function testPassesWhenAllConfiguredSeoFieldsAreFilled(): void
    {
        $validator = new SeoRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(SeoInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getSeoTitle()->willReturn('My Title');
        $dimensionContent->getSeoDescription()->willReturn('My Description');

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testFailsWhenSeoTitleIsMissing(): void
    {
        $validator = new SeoRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(SeoInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getSeoTitle()->willReturn(null);
        $dimensionContent->getSeoDescription()->willReturn('A description');

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        $this->assertSame(SeoRequiredValidator::KEY, $result->failures[0]->validatorKey);
        $this->assertSame('sulu_content.workflow_transition_request.seo_required.missing', $result->failures[0]->messageKey);
        $this->assertContains('title', (array) $result->failures[0]->details['missing']);
    }

    public function testFailsWhenSeoDescriptionIsEmptyWhitespace(): void
    {
        $validator = new SeoRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(SeoInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getSeoTitle()->willReturn('My Title');
        $dimensionContent->getSeoDescription()->willReturn('   ');

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertContains('description', (array) $result->failures[0]->details['missing']);
    }

    public function testFailsListingAllMissingFields(): void
    {
        $validator = new SeoRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(SeoInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getSeoTitle()->willReturn(null);
        $dimensionContent->getSeoDescription()->willReturn('');

        $context = new ValidationContext(
            $request,
            ['fields' => ['title', 'description']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->failures);
        /** @var array<string> $missing */
        $missing = $result->failures[0]->details['missing'];
        $this->assertContains('title', $missing);
        $this->assertContains('description', $missing);
    }

    public function testPassesWhenKeywordsFieldFilledWithKeywordsConfigured(): void
    {
        $validator = new SeoRequiredValidator();
        $request = $this->createRequest();

        $dimensionContent = $this->prophesize(SeoInterface::class);
        $dimensionContent->willImplement(DimensionContentInterface::class);
        $dimensionContent->getSeoKeywords()->willReturn('php, symfony');

        $context = new ValidationContext(
            $request,
            ['fields' => ['keywords']],
            $dimensionContent->reveal(),
        );

        $result = $validator->check($context);

        $this->assertTrue($result->passed);
    }

    public function testGetKey(): void
    {
        $validator = new SeoRequiredValidator();
        $this->assertSame('seo_required', $validator->getKey());
    }
}
