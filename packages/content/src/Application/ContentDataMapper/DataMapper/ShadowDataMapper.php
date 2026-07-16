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

namespace Sulu\Content\Application\ContentDataMapper\DataMapper;

use Sulu\Content\Domain\Exception\ShadowLocaleCycleException;
use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\ShadowInterface;

class ShadowDataMapper implements DataMapperInterface
{
    public function map(
        DimensionContentInterface $unlocalizedDimensionContent,
        DimensionContentInterface $localizedDimensionContent,
        array $data
    ): void {
        if (!$unlocalizedDimensionContent instanceof ShadowInterface
            || !$localizedDimensionContent instanceof ShadowInterface
        ) {
            return;
        }

        if (\array_key_exists('shadowOn', $data) || \array_key_exists('shadowLocale', $data)) {
            /** @var bool $shadowOn */
            $shadowOn = $data['shadowOn'] ?? false;
            /** @var string|null $shadowLocale */
            $shadowLocale = $data['shadowLocale'] ?? null;

            $locale = $localizedDimensionContent->getLocale();

            $localizedDimensionContent->setShadowLocale(
                $shadowOn && $locale
                    ? $shadowLocale
                    : null
            );

            if ($locale && $shadowLocale) {
                $this->assertNoCycle($unlocalizedDimensionContent, $locale, $shadowLocale);
                $unlocalizedDimensionContent->addShadowLocale($locale, $shadowLocale);
            } elseif ($locale) {
                $unlocalizedDimensionContent->removeShadowLocale($locale);
            }
        }
    }

    /**
     * Reject a relation $locale => $shadowLocale whose prospective map is cyclic:
     * a walk from $shadowLocale must never reach $locale.
     */
    private function assertNoCycle(ShadowInterface $unlocalizedDimensionContent, string $locale, string $shadowLocale): void
    {
        $map = $unlocalizedDimensionContent->getShadowLocales() ?? [];
        $map[$locale] = $shadowLocale;

        $current = $shadowLocale;
        $visited = [];
        while (\is_string($current)) {
            if ($current === $locale) {
                throw new ShadowLocaleCycleException($locale, $shadowLocale);
            }
            if (isset($visited[$current])) {
                break; // pre-existing cycle not involving $locale — not this save's fault
            }
            $visited[$current] = true;
            $current = $map[$current] ?? null;
        }
    }
}
