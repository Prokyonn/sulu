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

use Sulu\Component\HttpKernel\SuluKernel;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\RequestWorkflowPrevalidatorInterface;
use Sulu\Content\Application\RequestWorkflow\RequestWorkflow;
use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Util\Exception\XmlParsingException;
use Symfony\Component\Config\Util\XmlUtils;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Finder\Finder;

/**
 * @internal
 */
final class ValidateRequestWorkflowsPass implements CompilerPassInterface
{
    private const WORKFLOWS_PARAMETER = 'sulu_content.request_workflows';

    private const TEMPLATE_NAMESPACE = 'http://schemas.sulu.io/template/template';

    private const TEMPLATE_TAG = 'sulu_content.request_workflow';

    public function process(ContainerBuilder $container): void
    {
        // the validators and the workflow config are admin-only, so the website container has
        // nothing to check and would report every template tag as unconfigured
        if (!$container->hasParameter('sulu.context') || SuluKernel::CONTEXT_ADMIN !== $container->getParameter('sulu.context')) {
            return;
        }

        if (!$container->hasParameter(self::WORKFLOWS_PARAMETER)) {
            return;
        }

        /** @var array<string, array{validators?: array<string, mixed>, prevalidators?: array<string, mixed>}> $workflows */
        $workflows = $container->getParameter(self::WORKFLOWS_PARAMETER);

        $errors = [];
        $validators = $this->collectKeys($container, 'sulu_content.request_workflow_validator', RequestWorkflowValidatorInterface::class);
        $prevalidators = $this->collectKeys($container, 'sulu_content.request_workflow_prevalidator', RequestWorkflowPrevalidatorInterface::class);

        $this->collectDuplicateKeyErrors($validators, 'validator', $errors);
        $this->collectDuplicateKeyErrors($prevalidators, 'prevalidator', $errors);

        foreach ($workflows as $name => $workflowConfig) {
            $this->collectUnknownKeyErrors($name, \array_keys($workflowConfig['validators'] ?? []), $validators, 'validator', $errors);
            $this->collectUnknownKeyErrors($name, \array_keys($workflowConfig['prevalidators'] ?? []), $prevalidators, 'prevalidator', $errors);
        }

        $this->collectTemplateErrors($container, \array_keys($workflows), $errors);

        if ([] !== $errors) {
            throw new \RuntimeException(
                "Invalid request workflow configuration:\n\n" . \implode("\n", $errors)
            );
        }
    }

    /**
     * @param class-string<RequestWorkflowValidatorInterface>|class-string<RequestWorkflowPrevalidatorInterface> $interface
     *
     * @return array<string, list<string>> key => service ids registering it
     */
    private function collectKeys(ContainerBuilder $container, string $tag, string $interface): array
    {
        $keys = [];

        foreach ($container->findTaggedServiceIds($tag) as $id => $tags) {
            $definition = $container->getDefinition($id);

            // autoconfiguration leaves an abstract copy of every service it touched behind, and it
            // carries the same tags until the child definitions are resolved
            if ($definition->isAbstract()) {
                continue;
            }

            $class = $definition->getClass();
            $class = null !== $class ? $container->getParameterBag()->resolveValue($class) : null;

            $key = null;
            foreach ($tags as $attributes) {
                if (\is_array($attributes) && \is_string($attributes['key'] ?? null)) {
                    $key = $attributes['key'];

                    break;
                }
            }

            if (null === $key && \is_string($class) && \is_a($class, $interface, true)) {
                $key = $class::getKey();
            }

            // A service with neither is reported by the service locator itself.
            if (null !== $key) {
                $keys[$key][] = (string) $id;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, list<string>> $keys
     * @param list<string> $errors
     */
    private function collectDuplicateKeyErrors(array $keys, string $type, array &$errors): void
    {
        foreach ($keys as $key => $ids) {
            if (\count($ids) > 1) {
                $errors[] = \sprintf('- The %s key "%s" is registered by several services: %s', $type, $key, \implode(', ', $ids));
            }
        }
    }

    /**
     * @param list<string> $configuredKeys
     * @param array<string, list<string>> $keys
     * @param list<string> $errors
     */
    private function collectUnknownKeyErrors(string $workflowName, array $configuredKeys, array $keys, string $type, array &$errors): void
    {
        foreach ($configuredKeys as $configuredKey) {
            if (!isset($keys[$configuredKey])) {
                $errors[] = \sprintf(
                    '- Workflow "%s" references the %s "%s", which no service registers. Registered: %s',
                    $workflowName,
                    $type,
                    $configuredKey,
                    [] === $keys ? '(none)' : \implode(', ', \array_keys($keys)),
                );
            }
        }
    }

    /**
     * @param list<string> $workflowNames
     * @param list<string> $errors
     */
    private function collectTemplateErrors(ContainerBuilder $container, array $workflowNames, array &$errors): void
    {
        $directories = $this->resolveDirectories($container);
        if ([] === $directories) {
            return;
        }

        foreach ((new Finder())->in($directories)->name('*.xml') as $file) {
            $container->addResource(new FileResource($file->getPathname()));

            $name = $this->readWorkflowName($file->getPathname());
            if (null === $name || RequestWorkflow::NONE_NAME === $name || \in_array($name, $workflowNames, true)) {
                continue;
            }

            $errors[] = \sprintf(
                '- The template tag "%s" names the workflow "%s", which is not configured. Configured: %s (file: %s)',
                self::TEMPLATE_TAG,
                $name,
                [] === $workflowNames ? '(none)' : \implode(', ', $workflowNames),
                $file->getPathname(),
            );
        }
    }

    /**
     * @return list<string>
     */
    private function resolveDirectories(ContainerBuilder $container): array
    {
        $directories = [];

        if ($container->hasParameter('sulu_admin.templates.configuration')) {
            /** @var array<string, array{directories: array<string>}> $configuration */
            $configuration = $container->getParameter('sulu_admin.templates.configuration');

            foreach ($configuration as $config) {
                foreach ($config['directories'] as $directory) {
                    $directories[] = $directory;
                }
            }
        }

        if ($container->hasParameter('sulu_admin.forms.directories')) {
            /** @var array<string> $formDirectories */
            $formDirectories = $container->getParameter('sulu_admin.forms.directories');

            foreach ($formDirectories as $directory) {
                $directories[] = $directory;
            }
        }

        $resolved = [];
        foreach ($directories as $directory) {
            $realPath = \realpath($directory);
            if (false === $realPath) {
                continue;
            }

            $resolved[$realPath] = true;
        }

        return \array_keys($resolved);
    }

    private function readWorkflowName(string $filePath): ?string
    {
        try {
            $document = XmlUtils::loadFile($filePath);
        } catch (XmlParsingException|\InvalidArgumentException) {
            return null;
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('t', self::TEMPLATE_NAMESPACE);

        $tags = $xpath->query(\sprintf('/t:template/t:tag[@name="%s"] | /t:form/t:tag[@name="%s"]', self::TEMPLATE_TAG, self::TEMPLATE_TAG));
        $tag = false !== $tags ? $tags->item(0) : null;

        if (!$tag instanceof \DOMElement) {
            return null;
        }

        $name = $tag->getAttribute('workflow');

        return '' !== $name ? $name : null;
    }
}
