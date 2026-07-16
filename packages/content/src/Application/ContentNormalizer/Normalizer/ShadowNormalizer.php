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

namespace Sulu\Content\Application\ContentNormalizer\Normalizer;

use Sulu\Content\Domain\Model\DimensionContentInterface;
use Sulu\Content\Domain\Model\ShadowInterface;
use Webmozart\Assert\Assert;

class ShadowNormalizer implements NormalizerInterface
{
    public function enhance(object $object, array $normalizedData): array
    {
        if (!$object instanceof ShadowInterface) {
            return $normalizedData;
        }

        Assert::isInstanceOf($object, DimensionContentInterface::class);

        $normalizedData['shadowOn'] = null !== $object->getShadowLocale();
        $normalizedData['shadowLocales'] = $normalizedData['shadowLocales'] ?? [];
        $normalizedData['contentLocales'] = $this->filterSelectableLocales(
            $object->getAvailableLocales() ?? [],
            $object->getShadowLocales() ?? [],
            $object->getLocale()
        );

        return $normalizedData;
    }

    public function getIgnoredAttributes(object $object): array
    {
        if (!$object instanceof ShadowInterface) {
            return [];
        }

        return [];
    }

    /**
     * A candidate base locale is invalid for $editedLocale iff a walk from the candidate
     * over the draft shadow map reaches $editedLocale (rejects self, direct and transitive cycles).
     *
     * @param string[] $availableLocales
     * @param array<string, string> $shadowLocales draft map: locale => baseLocale
     *
     * @return string[]
     */
    private function filterSelectableLocales(array $availableLocales, array $shadowLocales, ?string $editedLocale): array
    {
        if (null === $editedLocale) {
            return \array_values($availableLocales);
        }

        return \array_values(\array_filter(
            $availableLocales,
            function(string $candidate) use ($shadowLocales, $editedLocale): bool {
                $current = $candidate;
                $visited = [];
                while (\is_string($current)) {
                    if ($current === $editedLocale) {
                        return false;
                    }
                    if (isset($visited[$current])) {
                        break;
                    }
                    $visited[$current] = true;
                    $current = $shadowLocales[$current] ?? null;
                }

                return true;
            }
        ));
    }
}
