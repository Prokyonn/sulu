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

namespace Sulu\Content\Tests\Unit\Content\Application\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Sulu\Content\Application\Security\ResourceSecurityContextProviderInterface;
use Sulu\Content\Application\Security\WorkflowTransitionRequestSecurityContextResolver;

#[CoversClass(WorkflowTransitionRequestSecurityContextResolver::class)]
class WorkflowTransitionRequestSecurityContextResolverTest extends TestCase
{
    use ProphecyTrait;

    public function testResolveDelegatesToMatchingProvider(): void
    {
        $pageProvider = $this->createProvider('pages', 'sulu.webspaces.example');
        $articleProvider = $this->createProvider('articles', 'sulu.article.articles');

        $resolver = new WorkflowTransitionRequestSecurityContextResolver([$pageProvider, $articleProvider]);

        $this->assertSame('sulu.webspaces.example', $resolver->resolve('pages', 'page-id-1'));
        $this->assertSame('sulu.article.articles', $resolver->resolve('articles', 'article-id-1'));
    }

    public function testResolveThrowsWhenNoProviderMatches(): void
    {
        $resolver = new WorkflowTransitionRequestSecurityContextResolver([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No security context provider registered for resource key "unknown"');

        $resolver->resolve('unknown', 'anything');
    }

    private function createProvider(string $resourceKey, string $context): ResourceSecurityContextProviderInterface
    {
        $provider = $this->prophesize(ResourceSecurityContextProviderInterface::class);
        $provider->getResourceKey()->willReturn($resourceKey);
        $provider->resolve(\Prophecy\Argument::any())->willReturn($context);

        return $provider->reveal();
    }
}
