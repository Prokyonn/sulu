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
use Sulu\Content\Domain\Model\SeoInterface;

/**
 * Requires the configured set of SEO fields to be filled before content may be sent to review.
 * Passes silently when the dimension content does not implement {@see SeoInterface}, so workflows
 * covering content without SEO data are not blocked.
 */
final class SeoRequiredPrevalidator implements RequestWorkflowPrevalidatorInterface
{
    private const DEFAULT_FIELDS = ['title', 'description'];

    public static function getKey(): string
    {
        return 'seo_required';
    }

    public function check(PrevalidationContext $context): array
    {
        $dimensionContent = $context->dimensionContent;
        if (!$dimensionContent instanceof SeoInterface) {
            return [];
        }

        /** @var array{fields?: list<string>} $config */
        $config = $context->config;
        $fields = $config['fields'] ?? self::DEFAULT_FIELDS;

        $missing = [];
        foreach ($fields as $field) {
            $value = match ($field) {
                'title' => $dimensionContent->getSeoTitle(),
                'description' => $dimensionContent->getSeoDescription(),
                'keywords' => $dimensionContent->getSeoKeywords(),
                default => throw new \LogicException(\sprintf(
                    'Unknown seo field "%s", allowed are: title, description, keywords.',
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
            'sulu_content.workflow_transition_request.seo_required.missing',
            ['fields' => \implode(', ', $missing)],
        )];
    }
}
