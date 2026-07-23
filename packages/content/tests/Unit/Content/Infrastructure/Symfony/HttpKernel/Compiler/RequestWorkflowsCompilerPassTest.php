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

namespace Sulu\Content\Tests\Unit\Content\Infrastructure\Symfony\HttpKernel\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\RequestWorkflowsCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(RequestWorkflowsCompilerPass::class)]
class RequestWorkflowsCompilerPassTest extends TestCase
{
    private function buildContainer(): ContainerBuilder
    {
        return new ContainerBuilder();
    }

    public function testProcessIsNoOpWhenConfigParameterAbsent(): void
    {
        $container = $this->buildContainer();
        $pass = new RequestWorkflowsCompilerPass();

        // Should not throw, and no workflow definitions are added
        $pass->process($container);

        $this->assertFalse($container->hasDefinition('sulu_content.request_workflow.default'));
    }

    public function testBuildsOneWorkflowDefinitionPerWorkflowEntry(): void
    {
        $container = $this->buildContainer();

        // Register a validator tagged service
        $validatorDef = new Definition();
        $validatorDef->addTag(RequestWorkflowsCompilerPass::VALIDATOR_TAG, ['key' => 'user_approvals']);
        $container->setDefinition('sulu_content.validator.user_approvals', $validatorDef);

        $container->setParameter(RequestWorkflowsCompilerPass::CONFIG_PARAMETER, [
            'default' => [
                'label' => 'Default Workflow',
                'validators' => [
                    'user_approvals' => ['count' => 1],
                ],
            ],
            'blog' => [
                'label' => null,
                'validators' => [],
            ],
        ]);

        $pass = new RequestWorkflowsCompilerPass();
        $pass->process($container);

        $this->assertTrue($container->hasDefinition('sulu_content.request_workflow.default'));
        $this->assertTrue($container->hasDefinition('sulu_content.request_workflow.blog'));

        $defaultDef = $container->getDefinition('sulu_content.request_workflow.default');
        $this->assertSame(RequestWorkflow::class, $defaultDef->getClass());
        $this->assertTrue($defaultDef->hasTag(RequestWorkflowsCompilerPass::WORKFLOW_TAG));
        $this->assertFalse($defaultDef->isPublic());

        // First arg is name, second is label, third is validators list
        $this->assertSame('default', $defaultDef->getArgument(0));
        $this->assertSame('Default Workflow', $defaultDef->getArgument(1));
        /** @var array<mixed> $validators */
        $validators = $defaultDef->getArgument(2);
        $this->assertCount(1, $validators);
    }

    public function testThrowsLogicExceptionWhenWorkflowReferencesUnknownValidatorKey(): void
    {
        $container = $this->buildContainer();

        $container->setParameter(RequestWorkflowsCompilerPass::CONFIG_PARAMETER, [
            'default' => [
                'label' => null,
                'validators' => [
                    'nonexistent_validator' => [],
                ],
            ],
        ]);

        $pass = new RequestWorkflowsCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nonexistent_validator');
        $pass->process($container);
    }

    public function testThrowsLogicExceptionWhenTaggedValidatorMissingKeyAttribute(): void
    {
        $container = $this->buildContainer();

        // Tag without 'key' attribute
        $validatorDef = new Definition();
        $validatorDef->addTag(RequestWorkflowsCompilerPass::VALIDATOR_TAG, []);
        $container->setDefinition('sulu_content.validator.missing_key', $validatorDef);

        $container->setParameter(RequestWorkflowsCompilerPass::CONFIG_PARAMETER, [
            'default' => ['label' => null, 'validators' => []],
        ]);

        $pass = new RequestWorkflowsCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('sulu_content.validator.missing_key');
        $pass->process($container);
    }

    public function testThrowsLogicExceptionWhenTwoServicesDeclareSameValidatorKey(): void
    {
        $container = $this->buildContainer();

        $validatorDef1 = new Definition();
        $validatorDef1->addTag(RequestWorkflowsCompilerPass::VALIDATOR_TAG, ['key' => 'duplicate_key']);
        $container->setDefinition('sulu_content.validator.first', $validatorDef1);

        $validatorDef2 = new Definition();
        $validatorDef2->addTag(RequestWorkflowsCompilerPass::VALIDATOR_TAG, ['key' => 'duplicate_key']);
        $container->setDefinition('sulu_content.validator.second', $validatorDef2);

        $container->setParameter(RequestWorkflowsCompilerPass::CONFIG_PARAMETER, [
            'default' => ['label' => null, 'validators' => []],
        ]);

        $pass = new RequestWorkflowsCompilerPass();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('duplicate_key');
        $pass->process($container);
    }

    public function testWorkflowWithNoValidatorsHasEmptyValidatorsList(): void
    {
        $container = $this->buildContainer();

        $container->setParameter(RequestWorkflowsCompilerPass::CONFIG_PARAMETER, [
            'simple' => ['label' => 'Simple', 'validators' => []],
        ]);

        $pass = new RequestWorkflowsCompilerPass();
        $pass->process($container);

        $def = $container->getDefinition('sulu_content.request_workflow.simple');
        $this->assertSame([], $def->getArgument(2));
    }

    public function testWorkflowDefinitionIsTaggedWithWorkflowTag(): void
    {
        $container = $this->buildContainer();

        $container->setParameter(RequestWorkflowsCompilerPass::CONFIG_PARAMETER, [
            'my_workflow' => ['label' => null, 'validators' => []],
        ]);

        $pass = new RequestWorkflowsCompilerPass();
        $pass->process($container);

        $def = $container->getDefinition('sulu_content.request_workflow.my_workflow');
        $tags = $def->getTag(RequestWorkflowsCompilerPass::WORKFLOW_TAG);
        $this->assertCount(1, $tags);
    }
}
