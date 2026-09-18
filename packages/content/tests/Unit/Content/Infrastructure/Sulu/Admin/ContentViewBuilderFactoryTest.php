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

namespace Sulu\Content\Tests\Unit\Content\Infrastructure\Sulu\Admin;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Bundle\AdminBundle\Admin\View\FormViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\PreviewFormViewBuilderInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactory;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Bundle\PreviewBundle\Preview\Object\PreviewObjectProviderRegistry;
use Sulu\Bundle\PreviewBundle\Preview\Object\PreviewObjectProviderRegistryInterface;
use Sulu\Bundle\PreviewBundle\Preview\Provider\PreviewDefaultsProviderInterface;
use Sulu\Component\Security\Authorization\PermissionTypes;
use Sulu\Component\Security\Authorization\SecurityCheckerInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentDataMapper\ContentDataMapperInterface;
use Sulu\Content\Application\ContentMetadataInspector\ContentMetadataInspectorInterface;
use Sulu\Content\Domain\Model\AuthorInterface;
use Sulu\Content\Domain\Model\ContentRichEntityInterface;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\DimensionContentTrait;
use Sulu\Content\Domain\Model\ExcerptInterface;
use Sulu\Content\Domain\Model\ExcerptTrait;
use Sulu\Content\Domain\Model\SeoInterface;
use Sulu\Content\Domain\Model\SeoTrait;
use Sulu\Content\Domain\Model\TemplateInterface;
use Sulu\Content\Domain\Model\TemplateTrait;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Content\Domain\Model\WorkflowTrait;
use Sulu\Content\Infrastructure\Sulu\Admin\ContentViewBuilderFactory;
use Sulu\Content\Infrastructure\Sulu\Admin\ContentViewBuilderFactoryInterface;
use Sulu\Content\Infrastructure\Sulu\Preview\ContentObjectProvider;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;
use Symfony\Component\DependencyInjection\Container;

class ContentViewBuilderFactoryTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @param array<string, array{instanceOf: class-string}> $settingsForms
     */
    protected function createContentViewBuilder(
        ContentMetadataInspectorInterface $contentMetadataInspector,
        SecurityCheckerInterface $securityChecker,
        ?PreviewObjectProviderRegistryInterface $previewObjectProviderRegistry = null,
        array $settingsForms = []
    ): ContentViewBuilderFactoryInterface {
        if (null === $previewObjectProviderRegistry) {
            $previewObjectProviderRegistry = $this->createPreviewObjectProviderRegistry([]);
        }

        return new ContentViewBuilderFactory(
            new ViewBuilderFactory(),
            $previewObjectProviderRegistry,
            $contentMetadataInspector,
            $securityChecker,
            $settingsForms
        );
    }

    /**
     * @param array<string, PreviewDefaultsProviderInterface> $providers
     */
    protected function createPreviewObjectProviderRegistry(array $providers): PreviewObjectProviderRegistryInterface
    {
        return new PreviewObjectProviderRegistry($providers);
    }

    /**
     * @template B of DimensionContentInterface
     * @template T of ContentRichEntityInterface<B>
     *
     * @param class-string<T> $entityClass
     *
     * @return ContentObjectProvider<B, T>
     */
    protected function createContentObjectProvider(
        MetadataProviderRegistry $metadataProviderRegistry,
        EntityManagerInterface $entityManager,
        ContentAggregatorInterface $contentAggregator,
        ContentDataMapperInterface $contentDataMapper,
        string $entityClass
    ): ContentObjectProvider {
        return new ContentObjectProvider(
            $metadataProviderRegistry,
            $entityManager,
            $contentAggregator,
            $contentDataMapper,
            $entityClass
        );
    }

    public function testCreateViews(): void
    {
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);

        $contentMetadataInspector = $this->prophesize(ContentMetadataInspectorInterface::class);
        $contentMetadataInspector->getDimensionContentClass(Example::class)
            ->willReturn(ExampleDimensionContent::class);

        $settingsForms = [
            'content_settings_author' => [
                'instanceOf' => AuthorInterface::class,
                'priority' => 128,
            ],
        ];
        $contentViewBuilder = $this->createContentViewBuilder($contentMetadataInspector->reveal(), $securityChecker->reveal(), null, $settingsForms);

        $views = $contentViewBuilder->createViews(Example::class, 'edit_parent_key');

        $this->assertCount(7, $views);

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[0]);
        $this->assertSame('edit_parent_key.content', $views[0]->getName());
        $this->assertSame(Example::TEMPLATE_TYPE, $views[0]->getView()->getOption('formKey'));

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[1]);
        $this->assertSame('edit_parent_key.seo', $views[1]->getName());
        $this->assertSame('content_seo', $views[1]->getView()->getOption('formKey'));

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[2]);
        $this->assertSame('edit_parent_key.excerpt', $views[2]->getName());
        $this->assertSame('content_excerpt', $views[2]->getView()->getOption('formKey'));

        $views = $contentViewBuilder->createViews(Example::class, 'edit_parent_key', 'add_parent_key');

        $this->assertCount(8, $views);

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[0]);
        $this->assertSame('add_parent_key.content', $views[0]->getName());
        $this->assertSame(Example::TEMPLATE_TYPE, $views[0]->getView()->getOption('formKey'));
        $this->assertSame('shadowOn != true', $views[0]->getView()->getOption('tabCondition'));

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[1]);
        $this->assertSame('edit_parent_key.content', $views[1]->getName());
        $this->assertSame(Example::TEMPLATE_TYPE, $views[1]->getView()->getOption('formKey'));
        $this->assertSame('shadowOn != true', $views[1]->getView()->getOption('tabCondition'));

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[2]);
        $this->assertSame('edit_parent_key.seo', $views[2]->getName());
        $this->assertSame('content_seo', $views[2]->getView()->getOption('formKey'));
        $this->assertSame('shadowOn != true', $views[2]->getView()->getOption('tabCondition'));

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[3]);
        $this->assertSame('edit_parent_key.excerpt', $views[3]->getName());
        $this->assertSame('content_excerpt', $views[3]->getView()->getOption('formKey'));
        $this->assertSame('shadowOn != true', $views[3]->getView()->getOption('tabCondition'));

        $this->assertInstanceOf(FormViewBuilderInterface::class, $views[4]);
        $this->assertSame('edit_parent_key.settings', $views[4]->getName());
        $this->assertSame('content_settings', $views[4]->getView()->getOption('formKey'));
        $this->assertNull($views[4]->getView()->getOption('tabCondition'));
    }

    public function testCreateViewsWithPreview(): void
    {
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);

        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $contentMetadataInspector = $this->prophesize(ContentMetadataInspectorInterface::class);
        $contentMetadataInspector->getDimensionContentClass(Example::class)
            ->willReturn(ExampleDimensionContent::class);

        $contentAggregator = $this->prophesize(ContentAggregatorInterface::class);
        $contentDataMapper = $this->prophesize(ContentDataMapperInterface::class);

        $contentObjectProvider = $this->createContentObjectProvider(
            new MetadataProviderRegistry(new Container()),
            $entityManager->reveal(),
            $contentAggregator->reveal(),
            $contentDataMapper->reveal(),
            Example::class
        );

        $previewObjectProviders = ['examples' => $contentObjectProvider];
        $previewObjectProviderRegistry = $this->createPreviewObjectProviderRegistry($previewObjectProviders);
        $contentViewBuilder = $this->createContentViewBuilder(
            $contentMetadataInspector->reveal(),
            $securityChecker->reveal(),
            $previewObjectProviderRegistry
        );

        $views = $contentViewBuilder->createViews(Example::class, 'edit_parent_key');

        $this->assertCount(7, $views);
        $this->assertInstanceOf(PreviewFormViewBuilderInterface::class, $views[0]);
        $this->assertInstanceOf(PreviewFormViewBuilderInterface::class, $views[1]);
        $this->assertInstanceOf(PreviewFormViewBuilderInterface::class, $views[2]);
        $this->assertInstanceOf(PreviewFormViewBuilderInterface::class, $views[3]);
    }

    /**
     * @return mixed[]
     */
    public static function getSecurityContextData(): array
    {
        return [
            [
                [
                    PermissionTypes::ADD => true,
                    PermissionTypes::EDIT => true,
                    PermissionTypes::LIVE => true,
                    PermissionTypes::DELETE => true,
                ],
                [
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    [],
                    [],
                    [],
                ],
            ],
            [
                [
                    PermissionTypes::ADD => false,
                    PermissionTypes::EDIT => true,
                    PermissionTypes::LIVE => true,
                    PermissionTypes::DELETE => true,
                ],
                [
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    [],
                    [],
                    [],
                ],
            ],
            [
                [
                    PermissionTypes::ADD => false,
                    PermissionTypes::EDIT => true,
                    PermissionTypes::LIVE => false,
                    PermissionTypes::DELETE => false,
                ],
                [
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    [],
                    [],
                    [],
                ],
            ],
            [
                [
                    PermissionTypes::ADD => true,
                    PermissionTypes::EDIT => false,
                    PermissionTypes::LIVE => true,
                    PermissionTypes::DELETE => true,
                ],
                [
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete', 'sulu_admin.dropdown'],
                ],
            ],
            [
                [
                    PermissionTypes::ADD => true,
                    PermissionTypes::EDIT => true,
                    PermissionTypes::LIVE => false,
                    PermissionTypes::DELETE => true,
                ],
                [
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    [],
                    [],
                    [],
                ],
            ],
            [
                [
                    PermissionTypes::ADD => true,
                    PermissionTypes::EDIT => true,
                    PermissionTypes::LIVE => true,
                    PermissionTypes::DELETE => false,
                ],
                [
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    [],
                    [],
                    [],
                ],
            ],
        ];
    }

    /**
     * @param mixed[] $permissions
     * @param mixed[] $expectedToolbarActions
     */
    #[DataProvider('getSecurityContextData')]
    public function testCreateViewsWithSecurityContext(array $permissions, array $expectedToolbarActions): void
    {
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);

        $contentMetadataInspector = $this->prophesize(ContentMetadataInspectorInterface::class);
        $contentMetadataInspector->getDimensionContentClass(Example::class)
            ->willReturn(ExampleDimensionContent::class);

        $contentViewBuilder = $this->createContentViewBuilder($contentMetadataInspector->reveal(), $securityChecker->reveal());

        foreach ($permissions as $permissionType => $permission) {
            $securityChecker->hasPermission('test_context', $permissionType)->willReturn($permission);
        }

        $views = $contentViewBuilder->createViews(
            Example::class,
            'edit_parent_key',
            'add_parent_key',
            'test_context'
        );

        $this->assertCount(\count($expectedToolbarActions), $views);

        foreach ($views as $index => $viewBuilder) {
            /** @var ToolbarAction[] $toolbarActions */
            $toolbarActions = $viewBuilder->getView()->getOption('toolbarActions') ?? [];
            $toolbarActionTypes = \array_map(function($toolbarAction) {
                return $toolbarAction->getType();
            }, $toolbarActions);

            $this->assertSame($expectedToolbarActions[$index], $toolbarActionTypes);
        }
    }

    /**
     * @return mixed[]
     */
    public static function getContentRichEntityClassData(): array
    {
        return [
            [
                new class() implements DimensionContentInterface, SeoInterface, ExcerptInterface {
                    use DimensionContentTrait;
                    use ExcerptTrait;
                    use SeoTrait;

                    public static function getResourceKey(): string
                    {
                        return 'mock-resource-key';
                    }

                    /**
                     * @return never
                     */
                    public function getResource(): ContentRichEntityInterface
                    {
                        throw new \RuntimeException('Should not be called while executing tests.');
                    }
                },
                [
                    ['sulu_admin.save'],
                    ['sulu_admin.save'],
                    ['sulu_admin.save'],
                    [],
                    [],
                    [],
                ],
            ],
            [
                new class() implements DimensionContentInterface, TemplateInterface, SeoInterface, ExcerptInterface {
                    use DimensionContentTrait;
                    use ExcerptTrait;
                    use SeoTrait;
                    use TemplateTrait;

                    /**
                     * @return never
                     */
                    public function getResource(): ContentRichEntityInterface
                    {
                        throw new \RuntimeException('Should not be called while executing tests.');
                    }

                    public static function getTemplateType(): string
                    {
                        return 'mock-template-type';
                    }

                    public static function getResourceKey(): string
                    {
                        return 'mock-resource-key';
                    }
                },
                [
                    ['sulu_admin.save', 'sulu_admin.type', 'sulu_admin.delete'],
                    ['sulu_admin.save', 'sulu_admin.type', 'sulu_admin.delete'],
                    ['sulu_admin.save'],
                    ['sulu_admin.save'],
                    ['sulu_admin.save'],
                    [],
                    [],
                    [],
                ],
            ],
            [
                new class() implements DimensionContentInterface, TemplateInterface, WorkflowInterface, SeoInterface, ExcerptInterface {
                    use DimensionContentTrait;
                    use ExcerptTrait;
                    use SeoTrait;
                    use TemplateTrait;
                    use WorkflowTrait;

                    /**
                     * @return never
                     */
                    public function getResource(): ContentRichEntityInterface
                    {
                        throw new \RuntimeException('Should not be called while executing tests.');
                    }

                    public static function getTemplateType(): string
                    {
                        return 'mock-template-type';
                    }

                    public static function getResourceKey(): string
                    {
                        return 'mock-resource-key';
                    }
                },
                [
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.type', 'sulu_admin.delete', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    ['sulu_admin.save_with_publishing', 'sulu_admin.dropdown'],
                    [],
                    [],
                    [],
                ],
            ],
        ];
    }

    /**
     * @template T of DimensionContentInterface
     *
     * @param T $dimensionContentObject
     * @param mixed[] $expectedToolbarActions
     */
    #[DataProvider('getContentRichEntityClassData')]
    public function testCreateViewsWithContentRichEntityClass(DimensionContentInterface $dimensionContentObject, array $expectedToolbarActions): void
    {
        $securityChecker = $this->prophesize(SecurityCheckerInterface::class);

        $contentMetadataInspector = $this->prophesize(ContentMetadataInspectorInterface::class);
        $contentMetadataInspector->getDimensionContentClass(Example::class)
            ->willReturn($dimensionContentObject::class);

        $contentViewBuilder = $this->createContentViewBuilder($contentMetadataInspector->reveal(), $securityChecker->reveal());

        $views = $contentViewBuilder->createViews(
            Example::class,
            'edit_parent_key',
            'add_parent_key'
        );

        $this->assertCount(\count($expectedToolbarActions), $views);

        foreach ($views as $index => $viewBuilder) {
            /** @var ToolbarAction[] $toolbarActions */
            $toolbarActions = $viewBuilder->getView()->getOption('toolbarActions') ?? [];
            $toolbarActionTypes = \array_map(function($toolbarAction) {
                return $toolbarAction->getType();
            }, $toolbarActions);

            $this->assertSame($expectedToolbarActions[$index], $toolbarActionTypes);
        }
    }

    /**
     * With no request open the guard has nothing to hold, so workflow and non-workflow content share
     * one plain `publish` route and who may take it is the authorizer's answer, not the toolbar's.
     */
    public function testPublishWithoutARequestUsesPlainPublish(): void
    {
        $factory = $this->createContentViewBuilder(
            $this->prophesize(ContentMetadataInspectorInterface::class)->reveal(),
            $this->prophesize(SecurityCheckerInterface::class)->reveal(),
        );

        /** @var array<string, mixed> $options */
        $options = $factory->getWorkflowTransitionRequestToolbarActions('examples', 'example')['save']->getOptions();
        /** @var list<\Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction> $children */
        $children = $options['toolbarActions'];

        $publishActions = [];
        foreach ($children as $child) {
            if ('sulu_admin.publish' !== $child->getType()) {
                continue;
            }

            $childOptions = $child->getOptions();
            $actionOptions = $childOptions['options'] ?? [];
            $this->assertIsArray($actionOptions);

            $action = $actionOptions['action'] ?? 'publish';
            $this->assertIsString($action);

            $visibleCondition = $childOptions['visible_condition'];
            $this->assertIsString($visibleCondition);

            $publishActions[] = [$action, $visibleCondition];
        }

        $this->assertCount(1, $publishActions, 'One publish route, whether or not a workflow applies.');

        [$action, $condition] = $publishActions[0];
        $this->assertSame('publish', $action);
        $this->assertStringContainsString('_permissions.live', $condition);
        $this->assertStringContainsString('!activeWorkflowTransitionRequest', $condition);
        $this->assertStringNotContainsString(
            'workflowTransitionRequestEnabled',
            $condition,
            'The publish route no longer branches on whether a workflow applies.',
        );
    }
}
