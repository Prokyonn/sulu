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
     * Whether the content payload of the current request should be persisted. The admin sends the
     * whole form with every toolbar action, so while a review is open only the actions that resolve
     * it may pass, and their payload is discarded.
     *
     * @param string|null $action the `action` query parameter of the current request, if any
     *
     * @throws ContentInReviewException when a review is open and the request would modify content
     */
    public function shouldPersistContent(string $resourceKey, string $resourceId, string $locale, ?string $action): bool;

    /**
     * Guards actions that write content without carrying the form, such as copying a locale or
     * restoring a version. Their target locale is not the locale of the current request, so they
     * ask for it explicitly.
     *
     * @throws ContentInReviewException when a review is open for the given locale
     */
    public function assertNotInReview(string $resourceKey, string $resourceId, string $locale): void;
}
