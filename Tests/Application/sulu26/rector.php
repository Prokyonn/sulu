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

use Rector\Config\RectorConfig;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withRootFiles()
    ->withPHPStanConfigs([
        __DIR__ . '/phpstan.dist.neon',
        // rector does not load phpstan extension automatically so require them manually here:
        __DIR__ . '/vendor/phpstan/phpstan-doctrine/extension.neon',
        __DIR__ . '/vendor/phpstan/phpstan-symfony/extension.neon',
    ])
    ->withImportNames(importShortClasses: false)
    ->withPreparedSets(codeQuality: true, doctrineCodeQuality: true)
    ->withPhpSets()

    // symfony rules
    ->withSymfonyContainerXml(__DIR__ . '/var/cache/website/dev/App_KernelDevDebugContainer.xml')
    ->withSymfonyContainerPhp(__DIR__ . '/tests/rector/symfony-container.php')
    ->withSets([
        SymfonySetList::SYMFONY_CODE_QUALITY,
        SymfonySetList::SYMFONY_CONSTRUCTOR_INJECTION,
        // activate when doing updates:
        // SymfonyLevelSetList::UP_TO_SYMFONY_63,
        // activate when doing updates:
        // PHPUnitLevelSetList::UP_TO_PHPUNIT_90,
        // PHPUnitSetList::PHPUNIT_91,
        // sulu rules
        // activate for updates when doing updates:
        // SuluLevelSetList::UP_TO_SULU_25,
    ]);
