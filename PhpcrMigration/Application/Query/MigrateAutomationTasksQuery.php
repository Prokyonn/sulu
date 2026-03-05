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
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\ArticlePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Persister\PagePersister;
use Sulu\Bundle\PhpcrMigrationBundle\PhpcrMigration\Application\Repository\EntityRepositoryInterface;

/**
 * Migrates automation tables from Sulu 2.6 to Sulu 3.0.
 *
 * Updates document handler to new page and article handlers
 */
class MigrateAutomationTasksQuery implements PostMigrationQueryInterface
{
    private const ARTICLE_INTERFACE_CLASS = 'Sulu\Article\Domain\Model\ArticleInterface';
    private const PAGE_INTERFACE_CLASS = 'Sulu\Page\Domain\Model\PageInterface';
    private const HANDLER_CLASS_BASE = 'Sulu\Bundle\AutomationBundle\TaskHandler';
    private const DOCUMENT_PUBLISH_HANDLER = 'Sulu\Bundle\AutomationBundle\Handler\DocumentPublishHandler';
    private const ARTICLE_UNPUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\ArticleUnpublishTaskHandler';
    private const ARTICLE_PUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\ArticlePublishTaskHandler';
    private const PAGE_UNPUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\PageUnpublishTaskHandler';
    private const PAGE_PUBLISH_HANDLER = self::HANDLER_CLASS_BASE . '\PagePublishTaskHandler';

    public function __construct(
        private readonly EntityRepositoryInterface $entityRepository,
        private readonly PagePersister $pagePersister,
        private readonly ArticlePersister $articlePersister,
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
            $isPage = $this->entityRepository->exists($this->pagePersister->getEntityTableName(), ['uuid' => $oldContext['entityId']]);
            $isArticle = false;

            if (!$isPage) {
                $isArticle = $this->entityRepository->exists($this->articlePersister->getEntityTableName(), ['uuid' => $oldContext['entityId']]);
            }

            if (!$isPage && !$isArticle) {
                // Remove task if entity does not exist anymore, happens when pages/articles were deleted.
                $this->entityRepository->removeBy(tableName: 'au_task', where: ['task_id' => $oldContext['task_id']]);

                continue;
            }

            if ($isPage) {
                $entityClass = self::PAGE_INTERFACE_CLASS;
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $oldContext['handlerClass'] ? self::PAGE_PUBLISH_HANDLER : self::PAGE_UNPUBLISH_HANDLER;
            } else {
                $entityClass = self::ARTICLE_INTERFACE_CLASS;
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $oldContext['handlerClass'] ? self::ARTICLE_PUBLISH_HANDLER : self::ARTICLE_UNPUBLISH_HANDLER;
            }

            $this->entityRepository->insertOrUpdate(
                data: [
                    'entityClass' => $entityClass,
                    'handlerClass' => $handlerClass,
                ],
                tableName: 'au_task',
                types: [
                    'entityClass' => 'string',
                    'handlerClass' => 'string',
                ],
                where: [
                    'task_id' => $oldContext['task_id'],
                ],
            );

            $typeMapping[$oldContext['task_id']] = [
                'entityType' => $isPage ? $this->pagePersister->getType() : $this->articlePersister->getType(),
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
            if (!isset($typeMapping[$taskExecution['task_id']])) {
                // Remove task execution if the related page/article was deleted.
                $this->entityRepository->removeBy(tableName: 'ta_task_executions', where: ['task_id' => $taskExecution['task_id']]);

                continue;
            }

            $type = $typeMapping[$taskExecution['task_id']]['entityType'];
            /** @var mixed[] $workload */
            $workload = @\unserialize($taskExecution['workload']);

            if ($this->pagePersister->getType() === $type) {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $taskExecution['handler_class'] ? self::PAGE_PUBLISH_HANDLER : self::PAGE_UNPUBLISH_HANDLER;
                $entityClass = self::PAGE_INTERFACE_CLASS;
            } else {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $taskExecution['handler_class'] ? self::ARTICLE_PUBLISH_HANDLER : self::ARTICLE_UNPUBLISH_HANDLER;
                $entityClass = self::ARTICLE_INTERFACE_CLASS;
            }

            $workload['class'] = $entityClass;

            $this->entityRepository->insertOrUpdate(
                data: [
                    'handler_class' => $handlerClass,
                    'workload' => @\serialize($workload),
                ],
                tableName: 'ta_task_executions',
                types: [
                    'handler_class' => 'string',
                    'workload' => 'string',
                ],
                where: [
                    'task_id' => $taskExecution['task_id'],
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
            if (!isset($typeMapping[$task['uuid']])) {
                // Remove task if the related page/article was deleted.
                $this->entityRepository->removeBy(tableName: 'ta_tasks', where: ['uuid' => $task['uuid']]);

                continue;
            }

            $type = $typeMapping[$task['uuid']]['entityType'];
            /** @var mixed[] $workload */
            $workload = @\unserialize($task['workload']);

            if ($this->pagePersister->getType() === $type) {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $task['handler_class'] ? self::PAGE_PUBLISH_HANDLER : self::PAGE_UNPUBLISH_HANDLER;
                $entityClass = self::PAGE_INTERFACE_CLASS;
            } else {
                $handlerClass = self::DOCUMENT_PUBLISH_HANDLER === $task['handler_class'] ? self::ARTICLE_PUBLISH_HANDLER : self::ARTICLE_UNPUBLISH_HANDLER;
                $entityClass = self::ARTICLE_INTERFACE_CLASS;
            }

            $workload['class'] = $entityClass;

            $this->entityRepository->insertOrUpdate(
                data: [
                    'handler_class' => $handlerClass,
                    'workload' => @\serialize($workload),
                ],
                tableName: 'ta_tasks',
                types: [
                    'handler_class' => 'string',
                    'workload' => 'string',
                ],
                where: [
                    'uuid' => $task['uuid'],
                ],
            );
        }
    }
}
