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
use Psr\Container\ContainerInterface;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\FormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TagMetadata;
use Sulu\Bundle\AdminBundle\Metadata\FormMetadata\TypedFormMetadata;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderInterface;
use Sulu\Bundle\AdminBundle\Metadata\MetadataProviderRegistry;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistryInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowResolver;
use Sulu\Content\Domain\Exception\UnknownRequestWorkflowException;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\Example;
use Sulu\Content\Tests\Application\ExampleTestBundle\Entity\ExampleDimensionContent;
use Sulu\Content\Tests\Application\ExampleTestBundle\Fixture\NonTemplateDimensionContent;

#[CoversClass(RequestWorkflowResolver::class)]
class RequestWorkflowResolverTest extends TestCase
{
    use ProphecyTrait;

    private function makeWorkflow(string $name): RequestWorkflow
    {
        return new RequestWorkflow($name, null, []);
    }

    private function makeMetadataProviderRegistry(?MetadataProviderInterface $provider = null): MetadataProviderRegistry
    {
        $container = $this->prophesize(ContainerInterface::class);

        if (null !== $provider) {
            $container->has('form')->willReturn(true);
            $container->get('form')->willReturn($provider);
        } else {
            $container->has('form')->willReturn(false);
        }

        return new MetadataProviderRegistry($container->reveal());
    }

    private function makeMetadataProviderWithTypedForm(string $templateType, string $templateKey, ?string $workflowName): MetadataProviderInterface
    {
        $provider = $this->prophesize(MetadataProviderInterface::class);
        $typed = new TypedFormMetadata();

        $form = new FormMetadata();
        $form->setKey($templateKey);

        if (null !== $workflowName) {
            $tag = new TagMetadata();
            $tag->setName(RequestWorkflowResolver::TEMPLATE_TAG);
            $tag->setAttributes([RequestWorkflowResolver::TEMPLATE_TAG_ATTRIBUTE => $workflowName]);
            $form->addTag($tag);
        }

        $typed->addForm($templateKey, $form);

        $provider->getMetadata($templateType, 'en', [])->willReturn($typed);

        return $provider->reveal();
    }

    public function testResolveForContentWithNonTemplateInterfaceReturnsDefaultWorkflow(): void
    {
        $defaultWorkflow = $this->makeWorkflow(RequestWorkflow::DEFAULT_NAME);

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has(RequestWorkflow::DEFAULT_NAME)->willReturn(true);
        $registry->get(RequestWorkflow::DEFAULT_NAME)->willReturn($defaultWorkflow);

        $metadataRegistry = $this->makeMetadataProviderRegistry();

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        $dimensionContent = new NonTemplateDimensionContent(new Example());

        $result = $resolver->resolveForContent($dimensionContent);

        $this->assertSame($defaultWorkflow, $result);
    }

    public function testResolveForContentWithNonTemplateInterfaceReturnsNullWhenNoDefaultRegistered(): void
    {
        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has(RequestWorkflow::DEFAULT_NAME)->willReturn(false);

        $metadataRegistry = $this->makeMetadataProviderRegistry();

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        $dimensionContent = new NonTemplateDimensionContent(new Example());

        $result = $resolver->resolveForContent($dimensionContent);

        $this->assertNull($result);
    }

    public function testResolveForContentWithTagPointingToRegisteredWorkflowReturnsIt(): void
    {
        $blogWorkflow = $this->makeWorkflow('blog');

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->get('blog')->willReturn($blogWorkflow);

        $provider = $this->makeMetadataProviderWithTypedForm(Example::TEMPLATE_TYPE, 'blog_article', 'blog');
        $metadataRegistry = $this->makeMetadataProviderRegistry($provider);

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        $result = $resolver->resolveForContent($this->makeDimensionContent('blog_article'));

        $this->assertSame($blogWorkflow, $result);
    }

    public function testResolveForContentWithTagPointingToUnregisteredWorkflowThrows(): void
    {
        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->get('nonexistent')->willThrow(new UnknownRequestWorkflowException('nonexistent'));

        $provider = $this->makeMetadataProviderWithTypedForm(Example::TEMPLATE_TYPE, 'article', 'nonexistent');
        $metadataRegistry = $this->makeMetadataProviderRegistry($provider);

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        $this->expectException(UnknownRequestWorkflowException::class);
        $resolver->resolveForContent($this->makeDimensionContent('article'));
    }

    public function testResolveForContentWithNoTagFallsBackToDefault(): void
    {
        $defaultWorkflow = $this->makeWorkflow(RequestWorkflow::DEFAULT_NAME);

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has(RequestWorkflow::DEFAULT_NAME)->willReturn(true);
        $registry->get(RequestWorkflow::DEFAULT_NAME)->willReturn($defaultWorkflow);

        $provider = $this->makeMetadataProviderWithTypedForm(Example::TEMPLATE_TYPE, 'simple', null);
        $metadataRegistry = $this->makeMetadataProviderRegistry($provider);

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        $result = $resolver->resolveForContent($this->makeDimensionContent('simple'));

        $this->assertSame($defaultWorkflow, $result);
    }

    public function testResolveForContentWhenMetadataProviderThrowsFallsBackToDefault(): void
    {
        $defaultWorkflow = $this->makeWorkflow(RequestWorkflow::DEFAULT_NAME);

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has(RequestWorkflow::DEFAULT_NAME)->willReturn(true);
        $registry->get(RequestWorkflow::DEFAULT_NAME)->willReturn($defaultWorkflow);

        $provider = $this->prophesize(MetadataProviderInterface::class);
        $provider->getMetadata(Example::TEMPLATE_TYPE, 'en', [])->willThrow(new \RuntimeException('Not found'));

        $metadataRegistry = $this->makeMetadataProviderRegistry($provider->reveal());

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        $result = $resolver->resolveForContent($this->makeDimensionContent('some_template'));

        $this->assertSame($defaultWorkflow, $result);
    }

    public function testResolveForContentReturnsNullWhenDefaultWorkflowDoesNotCoverTheResource(): void
    {
        $defaultWorkflow = new RequestWorkflow(RequestWorkflow::DEFAULT_NAME, null, [], ['pages', 'articles']);

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has(RequestWorkflow::DEFAULT_NAME)->willReturn(true);
        $registry->get(RequestWorkflow::DEFAULT_NAME)->willReturn($defaultWorkflow);

        $provider = $this->makeMetadataProviderWithTypedForm(Example::TEMPLATE_TYPE, 'simple', null);
        $metadataRegistry = $this->makeMetadataProviderRegistry($provider);

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        // ExampleDimensionContent resolves to the `examples` resource key, which the workflow
        // does not list, so the implicit default must not apply.
        $this->assertNull($resolver->resolveForContent($this->makeDimensionContent('simple')));
    }

    public function testResolveForContentReturnsDefaultWhenItCoversTheResource(): void
    {
        $defaultWorkflow = new RequestWorkflow(RequestWorkflow::DEFAULT_NAME, null, [], [Example::RESOURCE_KEY]);

        $registry = $this->prophesize(RequestWorkflowRegistryInterface::class);
        $registry->has(RequestWorkflow::DEFAULT_NAME)->willReturn(true);
        $registry->get(RequestWorkflow::DEFAULT_NAME)->willReturn($defaultWorkflow);

        $provider = $this->makeMetadataProviderWithTypedForm(Example::TEMPLATE_TYPE, 'simple', null);
        $metadataRegistry = $this->makeMetadataProviderRegistry($provider);

        $resolver = new RequestWorkflowResolver($registry->reveal(), $metadataRegistry);

        $this->assertSame($defaultWorkflow, $resolver->resolveForContent($this->makeDimensionContent('simple')));
    }

    private function makeDimensionContent(string $templateKey): ExampleDimensionContent
    {
        $dimensionContent = new ExampleDimensionContent(new Example());
        $dimensionContent->setTemplateKey($templateKey);

        return $dimensionContent;
    }
}
