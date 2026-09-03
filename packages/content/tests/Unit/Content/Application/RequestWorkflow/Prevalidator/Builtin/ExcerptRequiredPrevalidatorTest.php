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

namespace Sulu\Content\Tests\Unit\Content\Application\RequestWorkflow\Prevalidator\Builtin;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\Builtin\ExcerptRequiredPrevalidator;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\PrevalidationContext;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;
use Sulu\Content\Tests\Application\ExampleTestBundle\Fixture\NonTemplateDimensionContent;

#[CoversClass(ExcerptRequiredPrevalidator::class)]
final class ExcerptRequiredPrevalidatorTest extends TestCase
{
    /**
     * @template T of ContentRichEntityInterface
     *
     * @param DimensionContentInterface<T> $dimensionContent
     * @param array<string, mixed> $config
     */
    private function createContext(DimensionContentInterface $dimensionContent, array $config): PrevalidationContext
    {
        return new PrevalidationContext($dimensionContent, $config, 'default');
    }

    public function testPassesWhenContentDoesNotImplementExcerptInterface(): void
    {
        $dimensionContent = new NonTemplateDimensionContent(new Example());
        $context = $this->createContext($dimensionContent, ['fields' => ['title']]);

        $this->assertSame([], (new ExcerptRequiredPrevalidator())->check($context));
    }

    public function testPassesWhenAllConfiguredFieldsAreFilled(): void
    {
        $dimensionContent = new ExampleDimensionContent(new Example());
        $dimensionContent->setExcerptData(['title' => 'Excerpt Title', 'description' => 'Excerpt Description']);

        $context = $this->createContext($dimensionContent, ['fields' => ['title', 'description']]);

        $this->assertSame([], (new ExcerptRequiredPrevalidator())->check($context));
    }

    public function testFailsAndNamesEveryMissingField(): void
    {
        $dimensionContent = new ExampleDimensionContent(new Example());
        $dimensionContent->setExcerptData(['more' => '  ']);

        $context = $this->createContext($dimensionContent, ['fields' => ['title', 'more']]);

        $failures = (new ExcerptRequiredPrevalidator())->check($context);

        $this->assertCount(1, $failures);
        $this->assertSame(
            'sulu_content.workflow_transition_request.excerpt_required.missing',
            $failures[0]->messageKey,
        );
        $this->assertSame(['fields' => 'title, more'], $failures[0]->messageParameters);
    }

    public function testUnknownConfiguredFieldThrows(): void
    {
        $dimensionContent = new ExampleDimensionContent(new Example());
        $context = $this->createContext($dimensionContent, ['fields' => ['titel']]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown excerpt field "titel", allowed are: title, description, more.');

        (new ExcerptRequiredPrevalidator())->check($context);
    }
}
