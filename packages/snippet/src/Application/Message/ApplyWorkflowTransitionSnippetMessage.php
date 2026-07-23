<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Snippet\Application\Message;

class ApplyWorkflowTransitionSnippetMessage
{
    /**
     * @var array{
     *     uuid?: string,
     * }
     */
    private array $identifier;

    private string $locale;

    private string $transitionName;

    private bool $force;

    /**
     * @param array{
     *     uuid?: string
     * } $identifier
     */
    public function __construct(array $identifier, string $locale, string $transitionName, bool $force = false)
    {
        $this->identifier = $identifier;
        $this->locale = $locale;
        $this->transitionName = $transitionName;
        $this->force = $force;
    }

    /**
     * @return array{
     *     uuid?: string
     * }
     */
    public function getIdentifier(): array
    {
        return $this->identifier;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getTransitionName(): string
    {
        return $this->transitionName;
    }

    public function isForced(): bool
    {
        return $this->force;
    }
}
