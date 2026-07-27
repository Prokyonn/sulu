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

namespace Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler;

use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Translates the `sulu_content.request_workflows` config tree into one
 * {@see RequestWorkflow} service per workflow name. Each is registered with the
 * `sulu_content.request_workflow` tag so {@see \Sulu\Content\Application\RequestWorkflow\RequestWorkflowRegistry}
 * can pick them up via tagged_iterator.
 *
 * Validators are matched by their tag attribute `key` (which must equal
 * {@see \Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface::getKey()}).
 */
final class RequestWorkflowsCompilerPass implements CompilerPassInterface
{
    public const VALIDATOR_TAG = 'sulu_content.request_workflow_validator';
    public const WORKFLOW_TAG = 'sulu_content.request_workflow';
    public const CONFIG_PARAMETER = 'sulu_content.request_workflows.config';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::CONFIG_PARAMETER)) {
            return;
        }

        // Workflow services and validators are tagged `sulu.context => admin` and get removed by
        // RemoveForeignContextServicesPass (priority 99) in website/preview contexts. Without this guard,
        // the pass would run after that removal and fail with "(none registered)" on every non-admin boot.
        if ($container->hasParameter('sulu.context') && 'admin' !== $container->getParameter('sulu.context')) {
            return;
        }

        /** @var array<string, array{label?: ?string, resources?: list<string>, validators?: array<string, array<string, mixed>|null>}> $workflows */
        $workflows = $container->getParameter(self::CONFIG_PARAMETER);

        $validatorIdsByKey = $this->buildValidatorMap($container);

        foreach ($workflows as $name => $workflowConfig) {
            $validators = [];
            foreach (($workflowConfig['validators'] ?? []) as $validatorKey => $validatorConfig) {
                if (!isset($validatorIdsByKey[$validatorKey])) {
                    throw new \LogicException(\sprintf(
                        'Workflow "%s" references unknown validator "%s". Known validators: %s',
                        $name,
                        $validatorKey,
                        '' === \implode(', ', \array_keys($validatorIdsByKey))
                            ? '(none registered)'
                            : \implode(', ', \array_keys($validatorIdsByKey)),
                    ));
                }

                $validators[] = [
                    'validator' => new Reference($validatorIdsByKey[$validatorKey]),
                    'config' => \is_array($validatorConfig) ? $validatorConfig : [],
                ];
            }

            $definition = new Definition(RequestWorkflow::class, [
                $name,
                $workflowConfig['label'] ?? null,
                $validators,
                $workflowConfig['resources'] ?? [],
            ]);
            $definition->addTag(self::WORKFLOW_TAG);
            $definition->setPublic(false);

            $container->setDefinition('sulu_content.request_workflow.' . $name, $definition);
        }
    }

    /**
     * @return array<string, string> validator key → service id
     */
    private function buildValidatorMap(ContainerBuilder $container): array
    {
        $map = [];
        foreach ($container->findTaggedServiceIds(self::VALIDATOR_TAG) as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $key = \is_array($tag) ? ($tag['key'] ?? null) : null;
                if (!\is_string($key) || '' === $key) {
                    throw new \LogicException(\sprintf(
                        'Service "%s" tagged with "%s" must declare a non-empty "key" attribute matching its validator key.',
                        $serviceId,
                        self::VALIDATOR_TAG,
                    ));
                }
                if (isset($map[$key])) {
                    throw new \LogicException(\sprintf(
                        'Validator key "%s" is registered by multiple services ("%s" and "%s"). Keys must be unique.',
                        $key,
                        $map[$key],
                        $serviceId,
                    ));
                }
                $map[$key] = $serviceId;
            }
        }

        return $map;
    }
}
