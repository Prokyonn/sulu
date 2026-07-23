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

interface WorkflowTransitionRequestListEnhancerInterface
{
    /**
     * Adds a `workflowTransitionRequestStatus` value to each row reflecting the active workflow transition request status,
     * or `null` when no active request exists for the row's locale. Rows are matched by their identifier
     * field (defaults to `id`). When `$locale` is null, the rows are returned unchanged because publication
     * requests are always locale scoped.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function enhanceRows(array $rows, string $resourceKey, ?string $locale, string $idField = 'id'): array;

    /**
     * Returns the resource ids that currently have at least one active workflow transition request for the given
     * resource key (and locale, when provided). Useful for restricting a content list query to a "review
     * queue" subset.
     *
     * @return list<string>
     */
    public function findResourceIdsWithActiveRequest(string $resourceKey, ?string $locale): array;
}
