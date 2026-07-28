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

namespace Sulu\Page\Tests\Application;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Declares a request workflow covering pages so the review flow is actually active. The regular
 * page test kernel declares none, which is the "publishing stays direct" configuration.
 */
final class ReviewEnabledKernel extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(static function(ContainerBuilder $container): void {
            $container->prependExtensionConfig('sulu_content', [
                'request_workflows' => [
                    'default' => [
                        'resources' => ['pages'],
                        'validators' => [
                            'user_approvals' => ['count' => 1],
                        ],
                    ],
                ],
            ]);
        });
    }

    public function getBuildDir(): string
    {
        return parent::getBuildDir() . '_review';
    }

    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '_review';
    }
}
