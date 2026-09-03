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

namespace Sulu\Content\Application\RequestWorkflow\Prevalidator\Builtin;

use Sulu\Content\Application\RequestWorkflow\Prevalidator\PrevalidationContext;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\PrevalidationFailure;
use Sulu\Content\Application\RequestWorkflow\Prevalidator\RequestWorkflowPrevalidatorInterface;
use Sulu\Content\Domain\Model\ExcerptInterface;

/**
 * Requires the configured set of excerpt fields to be filled before content may be sent to review.
 * Passes silently when the dimension content does not implement {@see ExcerptInterface}.
 */
final class ExcerptRequiredPrevalidator implements RequestWorkflowPrevalidatorInterface
{
    private const DEFAULT_FIELDS = ['title', 'description'];

    public static function getKey(): string
    {
        return 'excerpt_required';
    }

    public function check(PrevalidationContext $context): array
    {
        $dimensionContent = $context->dimensionContent;
        if (!$dimensionContent instanceof ExcerptInterface) {
            return [];
        }

        /** @var array{fields?: list<string>} $config */
        $config = $context->config;
        $fields = $config['fields'] ?? self::DEFAULT_FIELDS;

        $missing = [];
        foreach ($fields as $field) {
            $value = match ($field) {
                'title' => $dimensionContent->getExcerptTitle(),
                'description' => $dimensionContent->getExcerptDescription(),
                'more' => $dimensionContent->getExcerptMore(),
                default => throw new \LogicException(\sprintf(
                    'Unknown excerpt field "%s", allowed are: title, description, more.',
                    $field,
                )),
            };
            if (null === $value || '' === \trim($value)) {
                $missing[] = $field;
            }
        }

        if ([] === $missing) {
            return [];
        }

        return [new PrevalidationFailure(
            'sulu_content.workflow_transition_request.excerpt_required.missing',
            ['fields' => \implode(', ', $missing)],
        )];
    }
}
