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
use Sulu\Content\Application\ResourceLoader\Loader\ResourceLoaderInterface;
use Sulu\Content\Application\Security\ResourceSecurityContextProviderInterface;
use Sulu\Content\Domain\Exception\ShadowSourceNotPublishedException;
use Sulu\Content\Infrastructure\Doctrine\EventListener\RouteCleanupListener;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\ExcerptFormPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\RequestWorkflowsCompilerPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\ResourceLoaderCacheCompilerPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\SeoFormPass;
use Sulu\Content\Infrastructure\Symfony\HttpKernel\Compiler\SettingsFormPass;
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
                // Named request workflows. Each workflow declares which validators must pass before a
                // workflow transition request is considered approved. Validators are registered as services
                // tagged `sulu_content.request_workflow_validator` (key matches the YAML key). The
                // `validators` map is intentionally permissive (each validator owns its own schema); the
                // {@see RequestWorkflowsCompilerPass} resolves it at compile time and fails loudly on
                // unknown validator keys.
                //
                // Declaring no workflows keeps direct publishing: the resolver returns null for every
                // content, so no request is ever created and nothing blocks a publish.
                ->arrayNode('request_workflows')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('label')->defaultNull()->end()
                            // Resource keys the `default` workflow covers when a template carries no
                            // explicit `sulu_content.request_workflow` tag, e.g. ['pages', 'articles'] to
                            // review pages and articles but not snippets. Empty means every resource.
                            // Workflows assigned explicitly via a template tag always apply.
                            ->arrayNode('resources')
                                ->scalarPrototype()->end()
                                ->defaultValue([])
                            ->end()
                            ->arrayNode('validators')
                                ->normalizeKeys(false)
                                ->variablePrototype()->end()
                                ->defaultValue([])
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

        // Hand the raw `request_workflows` config off to RequestWorkflowsCompilerPass. Hosts that do not
        // declare any workflows get no workflows registered; the resolver returns null in that case, so
        // the subscribers stay registered but never create or enforce a request.
        /** @var array<string, array{label?: string|null, resources?: list<string>, validators?: array<string, array<string, mixed>>}> $requestWorkflowsConfig */
        $requestWorkflowsConfig = $config['request_workflows'] ?? [];
        $builder->setParameter(RequestWorkflowsCompilerPass::CONFIG_PARAMETER, $requestWorkflowsConfig);

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

        if ($builder->hasExtension('sulu_admin')) {
            $builder->prependExtensionConfig(
                'sulu_admin',
                [
                    'forms' => [
                        'directories' => [
                            \dirname(__DIR__, 4) . '/config/forms',
                        ],
                    ],
                    'resources' => [
                        'workflow_transition_requests' => [
                            'routes' => [
                                'detail' => 'sulu_content.get_workflow_transition_request',
                            ],
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
        $container->addCompilerPass(new RequestWorkflowsCompilerPass());

        // Validators must declare their key via the tag attribute so the compiler pass can map
        // YAML keys to service IDs. The base interface does not know its own key, so autoconfiguration
        // tags it without an attribute and individual implementations override the tag in their
        // service definition (see workflow-transition-request.php).
        // No autoconfiguration for RequestWorkflowValidatorInterface: the tag carries a required
        // `key` attribute that autoconfiguration cannot supply, and a key-less tag makes
        // RequestWorkflowsCompilerPass fail. Validators are tagged explicitly instead.

        $container->registerForAutoconfiguration(ResourceLoaderInterface::class)
            ->addTag('sulu_content.resource_loader');

        $container->registerForAutoconfiguration(PropertyResolverInterface::class)
            ->addTag('sulu_content.property_resolver');

        $container->registerForAutoconfiguration(ResourceSecurityContextProviderInterface::class)
            ->addTag('sulu_content.workflow_transition_request_security_context_provider');

        // ensure metadata-aware property resolvers are also tagged
        $container->registerForAutoconfiguration(PropertyResolverMetadataAwareInterface::class)
            ->addTag('sulu_content.property_resolver');
    }
}
