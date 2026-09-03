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

namespace Sulu\Content\Tests\Unit\Content\Application\ContentPersister;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Sulu\Content\Application\ContentMerger\ContentMergerInterface;
use Sulu\Content\Application\ContentPersister\ContentPersister;
use Sulu\Content\Application\ContentPersister\ContentPersisterInterface;
use Sulu\Content\Application\ContentWorkflow\ContentWorkflowInterface;
use Sulu\Content\Domain\Exception\UnavailableContentTransitionException;
use Sulu\Content\Domain\Factory\DimensionContentCollectionFactoryInterface;
use Sulu\Content\Domain\Model\DimensionContentCollection;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;

class ContentPersisterTest extends TestCase
{
    use \Prophecy\PhpUnit\ProphecyTrait;

    protected function createContentPersisterInstance(
        DimensionContentCollectionFactoryInterface $dimensionContentCollectionFactory,
        ContentMergerInterface $contentMerger,
        ?ContentWorkflowInterface $contentWorkflow = null
    ): ContentPersisterInterface {
        return new ContentPersister(
            $dimensionContentCollectionFactory,
            $contentMerger,
            $contentWorkflow ?? $this->prophesize(ContentWorkflowInterface::class)->reveal()
        );
    }

    public function testPersist(): void
    {
        $attributes = [
            'locale' => 'de',
        ];
        $data = [
            'data' => 'value',
        ];
        $expectedAttributes = [
            'locale' => 'de',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ];

        $example = new Example();
        $dimensionContent1 = new ExampleDimensionContent($example);
        $dimensionContent1->setLocale(null);
        $dimensionContent1->setStage(DimensionContentInterface::STAGE_DRAFT);
        $dimensionContent2 = new ExampleDimensionContent($example);
        $dimensionContent2->setLocale('de');
        $dimensionContent2->setStage(DimensionContentInterface::STAGE_DRAFT);

        $dimensionContentCollection = new DimensionContentCollection(new ArrayCollection([
            $dimensionContent1,
            $dimensionContent2,
        ]), $expectedAttributes, ExampleDimensionContent::class);

        $dimensionContentCollectionFactory = $this->prophesize(DimensionContentCollectionFactoryInterface::class);
        $dimensionContentCollectionFactory->create($example, $attributes, $data)
            ->willReturn($dimensionContentCollection)
            ->shouldBeCalled();

        $mergedDimensionContent = $this->prophesize(DimensionContentInterface::class);
        $contentMerger = $this->prophesize(ContentMergerInterface::class);
        $contentMerger->merge($dimensionContentCollection)->willReturn($mergedDimensionContent->reveal())->shouldBeCalled();

        $createContentMessageHandler = $this->createContentPersisterInstance(
            $dimensionContentCollectionFactory->reveal(),
            $contentMerger->reveal()
        );

        $this->assertSame(
            $mergedDimensionContent->reveal(),
            $createContentMessageHandler->persist($example, $data, $attributes)
        );
    }

    public function testPersistAppliesEditTransitionForPublishedContent(): void
    {
        $attributes = ['locale' => 'de'];
        $data = ['data' => 'value'];
        $expectedAttributes = [
            'locale' => 'de',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ];

        $example = new Example();
        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale('de');
        $dimensionContent->setStage(DimensionContentInterface::STAGE_DRAFT);
        $dimensionContent->setWorkflowPlace(WorkflowInterface::WORKFLOW_PLACE_PUBLISHED);

        $dimensionContentCollection = new DimensionContentCollection(
            new ArrayCollection([$dimensionContent]),
            $expectedAttributes,
            ExampleDimensionContent::class
        );

        $dimensionContentCollectionFactory = $this->prophesize(DimensionContentCollectionFactoryInterface::class);
        $dimensionContentCollectionFactory->create($example, $attributes, $data)
            ->willReturn($dimensionContentCollection);

        $contentMerger = $this->prophesize(ContentMergerInterface::class);
        $contentMerger->merge($dimensionContentCollection)
            ->willReturn($this->prophesize(DimensionContentInterface::class)->reveal());

        $contentWorkflow = $this->prophesize(ContentWorkflowInterface::class);
        $contentWorkflow->apply(
            $example,
            [
                'stage' => DimensionContentInterface::STAGE_DRAFT,
                'locale' => 'de',
                'version' => DimensionContentInterface::CURRENT_VERSION,
            ],
            ExampleDimensionContent::getWorkflowTransitionEdit()
        )->shouldBeCalled()->willReturn($dimensionContent);

        $this->createContentPersisterInstance(
            $dimensionContentCollectionFactory->reveal(),
            $contentMerger->reveal(),
            $contentWorkflow->reveal()
        )->persist($example, $data, $attributes);
    }

    /**
     * The invariant this whole placement exists for: a persist against content under review reaches
     * the workflow, which defines no `edit` transition out of `review`, so the write is rejected
     * regardless of which caller attempted it. Paired with ContentWorkflowTest, which asserts the
     * transition graph has no such edge.
     */
    public function testPersistAppliesEditTransitionForContentInReview(): void
    {
        $attributes = ['locale' => 'de'];
        $data = ['data' => 'value'];
        $expectedAttributes = [
            'locale' => 'de',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ];

        $example = new Example();
        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale('de');
        $dimensionContent->setStage(DimensionContentInterface::STAGE_DRAFT);
        $dimensionContent->setWorkflowPlace(WorkflowInterface::WORKFLOW_PLACE_REVIEW);

        $dimensionContentCollection = new DimensionContentCollection(
            new ArrayCollection([$dimensionContent]),
            $expectedAttributes,
            ExampleDimensionContent::class
        );

        $dimensionContentCollectionFactory = $this->prophesize(DimensionContentCollectionFactoryInterface::class);
        $dimensionContentCollectionFactory->create($example, $attributes, $data)
            ->willReturn($dimensionContentCollection);

        $contentMerger = $this->prophesize(ContentMergerInterface::class);
        $contentMerger->merge($dimensionContentCollection)
            ->willReturn($this->prophesize(DimensionContentInterface::class)->reveal());

        $contentWorkflow = $this->prophesize(ContentWorkflowInterface::class);
        $contentWorkflow->apply(
            $example,
            Argument::any(),
            ExampleDimensionContent::getWorkflowTransitionEdit()
        )->shouldBeCalled()->willThrow(new UnavailableContentTransitionException('no edit out of review'));

        $this->expectException(UnavailableContentTransitionException::class);

        $this->createContentPersisterInstance(
            $dimensionContentCollectionFactory->reveal(),
            $contentMerger->reveal(),
            $contentWorkflow->reveal()
        )->persist($example, $data, $attributes);
    }

    public function testPersistSkipsEditTransitionForUnpublishedContent(): void
    {
        $attributes = ['locale' => 'de'];
        $data = ['data' => 'value'];
        $expectedAttributes = [
            'locale' => 'de',
            'stage' => DimensionContentInterface::STAGE_DRAFT,
        ];

        $example = new Example();
        $dimensionContent = new ExampleDimensionContent($example);
        $dimensionContent->setLocale('de');
        $dimensionContent->setStage(DimensionContentInterface::STAGE_DRAFT);
        $dimensionContent->setWorkflowPlace(WorkflowInterface::WORKFLOW_PLACE_UNPUBLISHED);

        $dimensionContentCollection = new DimensionContentCollection(
            new ArrayCollection([$dimensionContent]),
            $expectedAttributes,
            ExampleDimensionContent::class
        );

        $dimensionContentCollectionFactory = $this->prophesize(DimensionContentCollectionFactoryInterface::class);
        $dimensionContentCollectionFactory->create($example, $attributes, $data)
            ->willReturn($dimensionContentCollection);

        $contentMerger = $this->prophesize(ContentMergerInterface::class);
        $contentMerger->merge($dimensionContentCollection)
            ->willReturn($this->prophesize(DimensionContentInterface::class)->reveal());

        $contentWorkflow = $this->prophesize(ContentWorkflowInterface::class);
        $contentWorkflow->apply(Argument::cetera())->shouldNotBeCalled();

        $this->createContentPersisterInstance(
            $dimensionContentCollectionFactory->reveal(),
            $contentMerger->reveal(),
            $contentWorkflow->reveal()
        )->persist($example, $data, $attributes);
    }
}
