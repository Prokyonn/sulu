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

namespace Sulu\Content\Tests\Application;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class WorkflowTransitionRequestSeoRequiredKernel extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(static function(ContainerBuilder $container): void {
            $container->prependExtensionConfig('sulu_content', [
                'workflow_transition_request' => [
                    'publish_guard' => true,
                ],
                'request_workflows' => [
                    'default' => [
                        'validators' => [
                            'user_approvals' => ['count' => 1],
                            'seo_required' => ['fields' => ['title']],
                        ],
                    ],
                ],
            ]);
        });
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '_workflow_transition_request_seo';
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '_workflow_transition_request_seo';
    }
}
