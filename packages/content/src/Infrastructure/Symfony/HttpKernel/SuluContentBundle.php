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

namespace Sulu\Content\Infrastructure\Symfony\HttpKernel;

use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverInterface;
use Sulu\Content\Application\PropertyResolver\Resolver\PropertyResolverMetadataAwareInterface;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\RequestWorkflowPrevalidatorInterface;
use Sulu\Content\Application\RequestWorkflow\Validator\RequestWorkflowValidatorInterface;
use Sulu\Content\Application\ResourceLoader\Loader\ResourceLoaderInterface;
use Sulu\Content\Domain\Exception\DuplicateActiveWorkflowTransitionRequestException;
use Sulu\Content\Domain\Exception\MissingAuthenticatedUserException;
use Sulu\Content\Domain\Exception\NoRequestWorkflowException;
use Sulu\Content\Domain\Exception\SelfReviewNotAllowedException;
use Sulu\Content\Domain\Exception\ShadowSourceNotPublishedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestCancelNotAllowedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestClosedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotApprovedException;
use Sulu\Content\Domain\Exception\WorkflowTransitionRequestPrevalidationFailedException;
use Sulu\Content\Infrastructure\Doctrine\EventListener\RouteCleanupListener;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\ExcerptFormPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\ResourceLoaderCacheCompilerPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\SeoFormPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\SettingsFormPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\ValidateRequestWorkflowsPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * @codeCoverageIgnore
 */
final class SuluContentBundle extends AbstractBundle
{
    /**
     * @internal this method is not part of the public API and should only be called by the Symfony framework classes
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode() // @phpstan-ignore-line
            ->children()
                ->arrayNode('content_resolver')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('max_depth')->defaultValue(5)->end()
                    ->end()
                ->end()
                // Named review workflows, keyed by name. Declaring none keeps publishing direct.
                // `none` is reserved for the template opt-out tag and must not be used as a name.
                ->arrayNode('request_workflows')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            // Resource keys the `default` workflow covers for untagged templates, empty means all.
                            // Only `default` reads it, a named workflow is selected by its template tag and refuses it.
                            ->arrayNode('resources')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                            ->end()
                            // Sync gates keyed by prevalidator key, a failure aborts `request_for_review`.
                            ->arrayNode('prevalidators')
                                ->normalizeKeys(false)
                                ->variablePrototype()->end()
                                ->defaultValue([])
                            ->end()
                            // Checks keyed by validator key, run over the message bus once the request exists.
                            ->arrayNode('validators')
                                ->normalizeKeys(false)
                                ->variablePrototype()->end()
                                ->defaultValue([])
                            ->end()
                            // Approvals needed, a passed validator counts as one of them.
                            ->integerNode('required_approvals')
                                ->min(0)
                                ->defaultValue(1)
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @internal this method is not part of the public API and should only be called by the Symfony framework classes
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $loader = new PhpFileLoader($builder, new FileLocator(\dirname(__DIR__, 4) . '/config'));
        $loader->load('workflow-transition-request.php');
        $loader->load('data-mapper.php');
        $loader->load('merger.php');
        $loader->load('normalizer.php');
        $loader->load('services.php');
        $loader->load('form-visitor.php');
        $loader->load('controller.php');
        $loader->load('resolvers.php');
        $loader->load('resource-loader.php');
        $loader->load('reference.php');

        $services = $container->services();

        /** @var array{max_depth: int} $contentResolverConfig */
        $contentResolverConfig = $config['content_resolver'];

        $builder->getDefinition('sulu_content.content_resolver')
            ->setArgument('$maxDepth', $contentResolverConfig['max_depth']);

        /** @var array<string, array{resources?: list<string>, validators?: array<string, array<string, mixed>>, prevalidators?: array<string, array<string, mixed>>, required_approvals?: int}> $requestWorkflowsConfig */
        $requestWorkflowsConfig = $config['request_workflows'] ?? [];
        $builder->setParameter('sulu_content.request_workflows', $requestWorkflowsConfig);

        $services->set('sulu_content.doctrine_route_cleanup_listener')
            ->class(RouteCleanupListener::class)
            ->tag('doctrine.event_listener', ['event' => 'preRemove', 'method' => 'preRemove'])
            ->tag('doctrine.event_listener', ['event' => 'postFlush', 'method' => 'postFlush'])
            ->tag('doctrine.event_listener', ['event' => 'onClear', 'method' => 'onClear'])
            ->tag('kernel.reset', ['method' => 'reset']);
    }

    /**
     * @internal this method is not part of the public API and should only be called by the Symfony framework classes
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $builder->prependExtensionConfig(
                'doctrine',
                [
                    'orm' => [
                        'mappings' => [
                            'SuluContentWorkflowTransitionRequest' => [
                                'type' => 'xml',
                                'prefix' => 'Sulu\Content\Domain\Model\WorkflowTransitionRequest',
                                'dir' => \dirname(__DIR__, 4) . '/config/doctrine/WorkflowTransitionRequest',
                                'alias' => 'SuluContentWorkflowTransitionRequest',
                                'is_bundle' => false,
                                'mapping' => true,
                            ],
                        ],
                    ],
                ],
            );
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $builder->prependExtensionConfig(
                'doctrine_migrations',
                [
                    'migrations_paths' => [
                        'Sulu\\Content\\Migrations' => \dirname(__DIR__, 4) . '/migrations',
                    ],
                ],
            );
        }

        if ($builder->hasExtension('sulu_admin')) {
            $builder->prependExtensionConfig(
                'sulu_admin',
                [
                    'forms' => [
                        'directories' => [
                            \dirname(__DIR__, 4) . '/config/forms',
                        ],
                    ],
                ]
            );
        }

        if ($builder->hasExtension('fos_rest')) {
            $builder->prependExtensionConfig(
                'fos_rest',
                [
                    'exception' => [
                        'codes' => [
                            ShadowSourceNotPublishedException::class => 400,
                            MissingAuthenticatedUserException::class => 401,
                            SelfReviewNotAllowedException::class => 403,
                            WorkflowTransitionRequestCancelNotAllowedException::class => 403,
                            WorkflowTransitionRequestClosedException::class => 400,
                            DuplicateActiveWorkflowTransitionRequestException::class => 409,
                            WorkflowTransitionRequestNotApprovedException::class => 409,
                            WorkflowTransitionRequestPrevalidationFailedException::class => 422,
                            NoRequestWorkflowException::class => 422,
                        ],
                    ],
                ]
            );
        }
    }

    /**
     * @internal this method is not part of the public API and should only be called by the Symfony framework classes
     */
    public function getPath(): string
    {
        return \dirname(__DIR__, 4); // target the root of the library where config, src, ... is located
    }

    /**
     * @internal this method is not part of the public API and should only be called by the Symfony framework classes
     */
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new SettingsFormPass());
        $container->addCompilerPass(new ExcerptFormPass());
        $container->addCompilerPass(new SeoFormPass());
        $container->addCompilerPass(new ResourceLoaderCacheCompilerPass());
        $container->addCompilerPass(new ValidateRequestWorkflowsPass());

        $container->registerForAutoconfiguration(ResourceLoaderInterface::class)
            ->addTag('sulu_content.resource_loader');

        $container->registerForAutoconfiguration(PropertyResolverInterface::class)
            ->addTag('sulu_content.property_resolver');

        // ensure metadata-aware property resolvers are also tagged
        $container->registerForAutoconfiguration(PropertyResolverMetadataAwareInterface::class)
            ->addTag('sulu_content.property_resolver');

        // the locators index these by their static getKey(), so no tag attribute is needed
        $container->registerForAutoconfiguration(RequestWorkflowValidatorInterface::class)
            ->addTag('sulu_content.request_workflow_validator');

        $container->registerForAutoconfiguration(RequestWorkflowPrevalidatorInterface::class)
            ->addTag('sulu_content.request_workflow_prevalidator');
    }
}
