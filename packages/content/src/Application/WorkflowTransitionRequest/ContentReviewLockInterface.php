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

namespace Sulu\Content\Application\WorkflowTransitionRequest;

use Sulu\Content\Domain\Exception\ContentInReviewException;

interface ContentReviewLockInterface
{
    /**
     * Whether the content payload of the current request should be persisted.
     *
     * The admin submits every toolbar action as one request that carries the whole form *and* a
     * workflow action, and the controllers persist before applying the action. While a review is
     * open the form is read-only, so a request that only resolves the review carries nothing worth
     * saving — persisting it would apply the `edit` transition, which the content workflow does not
     * define for a review place, and the review could never be left.
     *
     * @param string|null $action the `action` query parameter of the current request, if any
     *
     * @throws ContentInReviewException when a review is open and the request would modify content
     */
    public function shouldPersistContent(string $resourceKey, string $resourceId, string $locale, ?string $action): bool;
}
