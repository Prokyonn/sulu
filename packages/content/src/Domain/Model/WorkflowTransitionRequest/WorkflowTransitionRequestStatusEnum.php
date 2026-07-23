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

namespace Sulu\Content\Domain\Model\WorkflowTransitionRequest;

enum WorkflowTransitionRequestStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';
    case PUBLISHED = 'published';

    public function isActive(): bool
    {
        return !$this->isClosed();
    }

    /**
     * Closed states are terminal: once entered the request cannot accept further reviewer decisions and
     * its `activeKey` is cleared so a new request can be created for the same scope.
     *
     * Note: REJECTED is intentionally NOT closed — a rejection can be lifted by the same reviewer (the
     * domain model treats reviewers as upsert-by-creator), and the request returns to PENDING/APPROVED
     * based on the remaining decisions. Both CANCELLED and PUBLISHED are terminal because they are driven
     * by the requester or the workflow rather than reviewers.
     */
    public function isClosed(): bool
    {
        return \in_array($this, [self::CANCELLED, self::PUBLISHED], true);
    }
}
