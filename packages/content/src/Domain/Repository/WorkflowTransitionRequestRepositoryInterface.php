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

namespace Sulu\Content\Domain\Repository;

use Sulu\Content\Domain\Exception\WorkflowTransitionRequestNotFoundException;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequest;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewer;
use Sulu\Content\Domain\Model\WorkflowTransitionRequest\WorkflowTransitionRequestReviewerStatusEnum;

interface WorkflowTransitionRequestRepositoryInterface
{
    /**
     * @param array{
     *     id?: string,
     *     resourceKey?: string,
     *     resourceId?: string,
     *     locale?: string,
     *     active?: bool,
     * } $filters
     *
     * @throws WorkflowTransitionRequestNotFoundException
     */
    public function getOneBy(array $filters): WorkflowTransitionRequest;

    /**
     * @param array{
     *     id?: string,
     *     resourceKey?: string,
     *     resourceId?: string,
     *     locale?: string,
     *     active?: bool,
     * } $filters
     */
    public function findOneBy(array $filters): ?WorkflowTransitionRequest;

    /**
     * @param array{
     *     id?: string,
     *     resourceKey?: string,
     *     resourceId?: string,
     *     locale?: string,
     *     active?: bool,
     * } $filters
     */
    public function countBy(array $filters = []): int;

    public function add(WorkflowTransitionRequest $workflowTransitionRequest): void;

    /**
     * Claims a still-pending validator row and writes its verdict in a single statement, so two
     * workers racing on the same row cannot overwrite each other. Whoever settles the row first wins,
     * a later run leaves that verdict standing.
     */
    public function settleValidatorReviewer(
        WorkflowTransitionRequestReviewer $reviewer,
        WorkflowTransitionRequestReviewerStatusEnum $status,
        ?string $comment,
    ): void;
}
