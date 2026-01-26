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

namespace Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Query;

use Doctrine\DBAL\Connection;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Exception\EntityNotFoundException;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

/**
 * Migrates automation tables from Sulu 2.6 to Sulu 3.0.
 *
 * Updates document handler to new page and article handlers
 */
class MigrateAutomationTasksQuery implements PostMigrationQueryInterface
{
    private const PAGE_TABLE = 'pa_pages';
    private const ARTICLE_TABLE = 'ar_articles';
    private const ENTITY_TYPE_PAGE = 'page';
    private const ENTITY_TYPE_ARTICLE = 'article';
    private const ARTICLE_ENTITY_CLASS = 'Sulu\Article\Domain\Model\Article';
    private const PAGE_ENTITY_CLASS = 'Sulu\Page\Domain\Model\Page';
    private const HANDLER_CLASS_BASE = 'Sulu\Bundle\AutomationBundle\TaskHandler';
    private const DOCUMENT_PUBLISH_HANDLER = 'Sulu\Bundle\AutomationBundle\Handler\DocumentPublishHandler';
    private const ARTICLE_UNPUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\ArticleUnpublishTaskHandler';
    private const ARTICLE_PUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\ArticlePublishTaskHandler';
    private const PAGE_UNPUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\PageUnpublishTaskHandler';
    private const PAGE_PUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\PagePublishTaskHandler';

    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
    ) {
    }

    public function execute(Connection $connection): void
    {
        $typeMapping = $this->migrateAutomationBundleTask($connection);
        $this->migrateTaskExecutions($connection, $typeMapping);
        $this->migrateTask($connection, $typeMapping);
    }

    /**
     * @return array<string, array{entityType: string}>
     */
    private function migrateAutomationBundleTask(Connection $connection): array
    {
        /** @var array<array{entityClass: string, handlerClass: string, entityId: string, task_id: string}> $oldContexts */
        $oldContexts = $connection->fetchAllAssociative(
            'SELECT entityClass, handlerClass, entityId, task_id FROM au_task',
        );
        $typeMapping = [];

        foreach ($oldContexts as $oldContext) {
            $isPage = $this->entityRepository->exists(self::PAGE_TABLE, ['uuid' => $oldContext['entityId']]);
            $isArticle = false;

            if (!$isPage) {
                $isArticle = $this->entityRepository->exists(self::ARTICLE_TABLE, ['uuid' => $oldContext['entityId']]);
            }

            if (!$isPage && !$isArticle) {
                throw new EntityNotFoundException($oldContext['entityId']);
            }

            if ($isPage) {
                $entityClass = self::PAGE_ENTITY_CLASS;
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $oldContext['handlerClass'] ? self::PAGE_PUBLISH_HANDLER : self::PAGE_UNPUBLISH_HANDLER;
            } else {
                $entityClass = self::ARTICLE_ENTITY_CLASS;
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $oldContext['handlerClass'] ? self::ARTICLE_PUBLISH_HANDLER : self::ARTICLE_UNPUBLISH_HANDLER;
            }

            $connection->executeStatement(
                'UPDATE au_task SET entityClass = :newEntityClass, handlerClass = :newHandlerClass WHERE task_id = :taskId',
                [
                    'newEntityClass' => $entityClass,
                    'newHandlerClass' => $handlerClass,
                    'taskId' => $oldContext['task_id'],
                ],
            );
            $typeMapping[$oldContext['task_id']] = [
                'entityType' => $isPage ? self::ENTITY_TYPE_PAGE : self::ENTITY_TYPE_ARTICLE,
            ];
        }

        return $typeMapping;
    }

    /**
     * @param array<string, array{entityType: string}> $typeMapping
     */
    private function migrateTaskExecutions(Connection $connection, array $typeMapping): void
    {
        /** @var array<array{uuid: string, handler_class: string, workload: string, task_id: string}> $taskExecutions */
        $taskExecutions = $connection->fetchAllAssociative(
            'SELECT uuid, handler_class, workload, task_id FROM ta_task_executions',
        );

        foreach ($taskExecutions as $taskExecution) {
            $type = $typeMapping[$taskExecution['task_id']]['entityType'];
            /** @var mixed[] $workload */
            $workload = @\unserialize($taskExecution['workload']);

            if (self::ENTITY_TYPE_PAGE === $type) {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $taskExecution['handler_class'] ? self::PAGE_PUBLISH_HANDLER : self::PAGE_UNPUBLISH_HANDLER;
                $entityClass = self::PAGE_ENTITY_CLASS;
            } else {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $taskExecution['handler_class'] ? self::ARTICLE_PUBLISH_HANDLER : self::ARTICLE_UNPUBLISH_HANDLER;
                $entityClass = self::ARTICLE_ENTITY_CLASS;
            }

            $workload['class'] = $entityClass;

            $connection->executeStatement(
                'UPDATE ta_task_executions SET handler_class = :newHandlerClass, workload = :newWorkload WHERE task_id = :taskId',
                [
                    'newHandlerClass' => $handlerClass,
                    'newWorkload' => @\serialize($workload),
                    'taskId' => $taskExecution['task_id'],
                ],
            );
        }
    }

    /**
     * @param array<string, array{entityType: string}> $typeMapping
     */
    private function migrateTask(Connection $connection, array $typeMapping): void
    {
        /** @var array<array{uuid: string, handler_class: string, workload: string}> $tasks */
        $tasks = $connection->fetchAllAssociative(
            'SELECT uuid, handler_class, workload FROM ta_tasks',
        );

        foreach ($tasks as $task) {
            $type = $typeMapping[$task['uuid']]['entityType'];
            /** @var mixed[] $workload */
            $workload = @\unserialize($task['workload']);

            if (self::ENTITY_TYPE_PAGE === $type) {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $task['handler_class'] ? self::PAGE_PUBLISH_HANDLER : self::PAGE_UNPUBLISH_HANDLER;
                $entityClass = self::PAGE_ENTITY_CLASS;
            } else {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $task['handler_class'] ? self::ARTICLE_PUBLISH_HANDLER : self::ARTICLE_UNPUBLISH_HANDLER;
                $entityClass = self::ARTICLE_ENTITY_CLASS;
            }

            $workload['class'] = $entityClass;

            $connection->executeStatement(
                'UPDATE ta_tasks SET handler_class = :newHandlerClass, workload = :newWorkload WHERE uuid = :uuid',
                [
                    'newHandlerClass' => $handlerClass,
                    'newWorkload' => @\serialize($workload),
                    'uuid' => $task['uuid'],
                ],
            );
        }
    }
}
