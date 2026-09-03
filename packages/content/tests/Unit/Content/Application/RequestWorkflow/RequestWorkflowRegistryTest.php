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
use Sulu\Content\Application\RequestWorkflow\Prevalidator\Builtin\SeoRequiredPrevalidator;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistry;
use Sulu\Content\Domain\Exception\UnknownRequestWorkflowException;
use Sulu\Content\Tests\Application\ExampleTestBundle\RequestWorkflow\ConfiguredResultValidator;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(RequestWorkflowRegistry::class)]
class RequestWorkflowRegistryTest extends TestCase
{
    /**
     * @param array<string, array{resources?: list<string>, validators?: array<string, array<string, mixed>|null>, prevalidators?: array<string, array<string, mixed>|null>, required_approvals?: int}> $config
     */
    private function createRegistry(array $config): RequestWorkflowRegistry
    {
        /** @var ServiceLocator<ConfiguredResultValidator> $validators */
        $validators = new ServiceLocator(['test_configured_result' => static fn () => new ConfiguredResultValidator()]);
        /** @var ServiceLocator<SeoRequiredPrevalidator> $prevalidators */
        $prevalidators = new ServiceLocator(['seo_required' => static fn () => new SeoRequiredPrevalidator()]);

        return new RequestWorkflowRegistry($config, $validators, $prevalidators);
    }

    public function testBuildsWorkflowFromConfig(): void
    {
        $registry = $this->createRegistry([
            'default' => [
                'resources' => ['articles'],
                'validators' => ['test_configured_result' => ['result' => 'reject']],
                'prevalidators' => ['seo_required' => ['fields' => ['title']]],
                'required_approvals' => 2,
            ],
        ]);

        $workflow = $registry->get(RequestWorkflow::DEFAULT_NAME);

        $this->assertSame(RequestWorkflow::DEFAULT_NAME, $workflow->name);
        $this->assertSame(['articles'], $workflow->resources);
        $this->assertSame(2, $workflow->getRequiredApprovalCount());
        $this->assertInstanceOf(ConfiguredResultValidator::class, $workflow->validators['test_configured_result']['validator']);
        $this->assertSame(['result' => 'reject'], $workflow->validators['test_configured_result']['config']);
        $this->assertInstanceOf(SeoRequiredPrevalidator::class, $workflow->prevalidators['seo_required']['prevalidator']);
        $this->assertSame(['fields' => ['title']], $workflow->prevalidators['seo_required']['config']);
    }

    public function testResourcesOnANamedWorkflowThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Workflow "blog" declares `resources`, which is only supported on the `default` workflow;'
            . ' a named workflow is selected by its template tag.',
        );

        $this->createRegistry(['blog' => ['resources' => ['articles']]]);
    }

    public function testWorkflowWithoutValidatorsIsBuiltWithTheDefaults(): void
    {
        $workflow = $this->createRegistry(['default' => []])->get(RequestWorkflow::DEFAULT_NAME);

        $this->assertSame([], $workflow->validators);
        $this->assertSame([], $workflow->prevalidators);
        $this->assertSame([], $workflow->resources);
        $this->assertSame(1, $workflow->getRequiredApprovalCount());
    }

    public function testUnknownValidatorKeyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Workflow "blog" references unknown validator "typo". Known validators: test_configured_result',
        );

        $this->createRegistry(['blog' => ['validators' => ['typo' => []]]]);
    }

    public function testUnknownPrevalidatorKeyThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Workflow "blog" references unknown prevalidator "typo". Known prevalidators: seo_required',
        );

        $this->createRegistry(['blog' => ['prevalidators' => ['typo' => []]]]);
    }

    public function testReservedWorkflowNameThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Workflow name "none" is reserved');

        $this->createRegistry([RequestWorkflow::NONE_NAME => []]);
    }

    public function testGetThrowsForUnknownWorkflow(): void
    {
        $registry = $this->createRegistry([]);

        $this->assertFalse($registry->has('nonexistent'));

        try {
            $registry->get('nonexistent');
            $this->fail('Expected UnknownRequestWorkflowException');
        } catch (UnknownRequestWorkflowException $exception) {
            $this->assertSame('nonexistent', $exception->workflowName);
        }
    }
}
