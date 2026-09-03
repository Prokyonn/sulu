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

namespace Sulu\Content\Application\RequestWorkflow\Validator;

/**
 * A validator's verdict, recorded on its reviewer row exactly like a person's. The comment is plain
 * text, not a translation key: it is written next to the reviewers' comments and read by a human.
 */
final class ValidationDecision
{
    private function __construct(
        public readonly bool $approved,
        public readonly ?string $comment,
    ) {
    }

    public static function approve(?string $comment = null): self
    {
        return new self(true, $comment);
    }

    public static function reject(string $comment): self
    {
        if ('' === \trim($comment)) {
            throw new \InvalidArgumentException('A rejecting validator must say what is wrong.');
        }

        return new self(false, $comment);
    }
}
