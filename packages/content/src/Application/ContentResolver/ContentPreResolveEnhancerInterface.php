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

namespace Sulu\Content\Application\ContentResolver;

use Sulu\Content\Domain\Model\DimensionContentInterface;

interface ContentPreResolveEnhancerInterface
{
    /**
     * @template T of DimensionContentInterface
     *
     * @param T $dimensionContent
     *
     * @return T The enhanced dimension content (may be a different instance)
     */
    public function enhance(DimensionContentInterface $dimensionContent): DimensionContentInterface;
}
