<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Rest\ListBuilder\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Sulu\Bundle\SecurityBundle\AccessControl\AccessControlQueryEnhancerInterface;
use Sulu\Component\Rest\ListBuilder\AbstractListBuilder;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineCaseFieldDescriptor;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineCountFieldDescriptor;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineFieldDescriptor;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineFieldDescriptorInterface;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineGroupConcatFieldDescriptor;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineJoinDescriptor;
use Sulu\Component\Rest\ListBuilder\Event\ListBuilderCreateEvent;
use Sulu\Component\Rest\ListBuilder\Event\ListBuilderEvents;
use Sulu\Component\Rest\ListBuilder\Expression\BasicExpressionInterface;
use Sulu\Component\Rest\ListBuilder\Expression\ConjunctionExpressionInterface;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\AbstractDoctrineExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineAndExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineBetweenExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineInExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineIsNotNullExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineIsNullExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineNotExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineOrExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Doctrine\DoctrineWhereExpression;
use Sulu\Component\Rest\ListBuilder\Expression\Exception\InvalidExpressionArgumentException;
use Sulu\Component\Rest\ListBuilder\Expression\ExpressionInterface;
use Sulu\Component\Rest\ListBuilder\FieldDescriptorInterface;
use Sulu\Component\Rest\ListBuilder\Filter\FilterTypeRegistry;
use Sulu\Component\Security\Authentication\UserInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The listbuilder implementation for doctrine.
 */
class DoctrineListBuilder extends AbstractListBuilder
{
    use EncodeAliasTrait;

    /**
     * @var DoctrineFieldDescriptorInterface[]
     */
    protected $selectFields = [];

    /**
     * @var DoctrineFieldDescriptorInterface[]
     */
    protected $searchFields = [];

    /**
     * @var AbstractDoctrineExpression[]
     */
    protected $expressions = [];

    /**
     * Array of unique field descriptors from expressions.
     *
     * @var array
     */
    protected $expressionFields = [];

    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var bool
     */
    private $distinct = false;

    /**
     * @var DoctrineFieldDescriptorInterface
     */
    private $idField;

    /**
     * @var bool
     */
    private $permissionCheckWithDynamicEntityClass = false;

    /**
     * @var class-string
     */
    private $securedEntityName;

    /**
     * @var string
     */
    private $securedEntityClassField;

    /**
     * @var string
     */
    private $securedEntityIdField;

    /**
     * Array of unique field descriptors needed for secure-check.
     *
     * @var array
     */
    private $permissionCheckFields = [];

    /**
     * @param class-string $entityName
     */
    public function __construct(
        private EntityManagerInterface $em,
        private $entityName,
        FilterTypeRegistry $filterTypeRegistry,
        private EventDispatcherInterface $eventDispatcher,
        private array $permissions,
        private AccessControlQueryEnhancerInterface $accessControlQueryEnhancer,
    ) {
        parent::__construct($filterTypeRegistry);
        $this->idField = new DoctrineFieldDescriptor(
            'id',
            'id',
            $this->entityName,
            'public.id'
        );

        $this->securedEntityName = $this->entityName;
    }

    public function setSelectFields($fieldDescriptors)
    {
        parent::setSelectFields($fieldDescriptors);
        $this->selectFields = \array_filter(
            $this->selectFields,
            function(FieldDescriptorInterface $fieldDescriptor) {
                return $fieldDescriptor instanceof DoctrineFieldDescriptorInterface;
            }
        );
    }

    public function addSelectField(FieldDescriptorInterface $fieldDescriptor)
    {
        if ($fieldDescriptor instanceof DoctrineFieldDescriptorInterface) {
            return parent::addSelectField($fieldDescriptor);
        }

        return $this;
    }

    /**
     * @param string $permission
     * @param string|null $securedEntityName
     *
     * @return self
     */
    public function setPermissionCheck(
        UserInterface $user,
        $permission,
        $securedEntityName = null
    ) {
        parent::setPermissionCheck($user, $permission);

        $this->permissionCheckWithDynamicEntityClass = false;
        $this->securedEntityName = $securedEntityName ?: $this->entityName;

        return $this;
    }

    public function setPermissionCheckWithDynamicEntityClass(
        UserInterface $user,
        string $permission,
        string $securedEntityClassField,
        string $securedEntityIdField
    ): self {
        parent::setPermissionCheck($user, $permission);

        $this->permissionCheckWithDynamicEntityClass = true;
        $this->securedEntityClassField = $securedEntityClassField;
        $this->securedEntityIdField = $securedEntityIdField;

        return $this;
    }

    public function addPermissionCheckField(DoctrineFieldDescriptor $fieldDescriptor)
    {
        $this->permissionCheckFields[$fieldDescriptor->getEntityName()] = $fieldDescriptor;
    }

    public function count()
    {
        $optimizedCount = $this->tryOptimizedExistsCount();
        if (null !== $optimizedCount) {
            return $optimizedCount;
        }

        $applyDistinct = $this->distinct || $this->hasJoins();

        $countExpression = $applyDistinct
            ? 'COUNT(DISTINCT ' . $this->idField->getSelect() . ')'
            : 'COUNT(' . $this->idField->getSelect() . ')';

        $subQueryBuilder = $this->createSubQueryBuilder($countExpression, false);

        $this->assignParameters($subQueryBuilder);

        $result = $subQueryBuilder->getQuery()->getScalarResult();
        $numResults = \count($result);
        if ($numResults > 1) {
            return $numResults;
        } elseif (1 == $numResults) {
            $result = \array_values($result[0]);

            return (int) $result[0];
        }

        return 0;
    }

    private function tryOptimizedExistsCount(): ?int
    {
        if ($this->user && $this->permission) {
            return null;
        }

        if (null !== $this->ids || !empty($this->excludedIds)) {
            return null;
        }

        if (empty($this->expressions)) {
            return null;
        }

        if (!$this->areAllExpressionTypesSupported($this->expressions)) {
            return null;
        }

        return $this->tryDirectDimensionContentCount();
    }

    /**
     * Detect the localized/ghost template filter pattern and generate a direct
     * count query on the dimension content table (no root table scan).
     *
     * Pattern: OR(AND(IS_NOT_NULL(A), IN(A, values)), AND(IS_NULL(A), IN(B, values)))
     * where A is on a single localized join and B is on the ghost join chain.
     */
    private function tryDirectDimensionContentCount(): ?int
    {
        if (1 !== \count($this->expressions)) {
            return null;
        }

        $expr = $this->expressions[0];
        if (!$expr instanceof DoctrineOrExpression) {
            return null;
        }

        $branches = $expr->getExpressions();
        if (2 !== \count($branches)) {
            return null;
        }

        // Identify localized branch: AND(IS_NOT_NULL, IN) and ghost branch: AND(IS_NULL, IN)
        $localizedBranch = null;
        $ghostBranch = null;
        foreach ($branches as $branch) {
            if (!$branch instanceof DoctrineAndExpression) {
                return null;
            }
            $subs = $branch->getExpressions();
            if (2 !== \count($subs)) {
                return null;
            }
            if ($subs[0] instanceof DoctrineIsNotNullExpression && $subs[1] instanceof DoctrineInExpression) {
                $localizedBranch = ['notNull' => $subs[0], 'in' => $subs[1]];
            } elseif ($subs[0] instanceof DoctrineIsNullExpression && $subs[1] instanceof DoctrineInExpression) {
                $ghostBranch = ['null' => $subs[0], 'in' => $subs[1]];
            } else {
                return null;
            }
        }

        if (!$localizedBranch || !$ghostBranch) {
            return null;
        }

        // IS_NOT_NULL and IS_NULL must reference the same field
        if ($localizedBranch['notNull']->getFieldName() !== $ghostBranch['null']->getFieldName()) {
            return null;
        }

        // Get the localized field descriptor (single join expected)
        $localizedField = $this->fieldDescriptors[$localizedBranch['in']->getFieldName()] ?? null;
        if (!$localizedField instanceof DoctrineFieldDescriptorInterface) {
            return null;
        }
        $localizedJoins = $localizedField->getJoins();
        if (1 !== \count($localizedJoins)) {
            return null;
        }
        $localizedJoinAlias = \array_key_first($localizedJoins);
        $localizedJoin = $localizedJoins[$localizedJoinAlias];

        if (DoctrineJoinDescriptor::JOIN_METHOD_INNER === $localizedJoin->getJoinMethod()) {
            return null;
        }

        // Resolve the dimension content table and FK column
        $dcTable = $this->resolveJoinedTableName($localizedJoin);
        if (null === $dcTable) {
            return null;
        }

        $fkColumn = $this->resolveJoinFkColumn($localizedJoin);
        if (null === $fkColumn) {
            return null;
        }

        $localizedAlias = $this->encodeAlias($localizedJoinAlias);
        $localizedCondition = $localizedJoin->getJoinCondition();
        $localizedFieldColumn = $localizedField->getFieldName();
        $localizedValues = $localizedBranch['in']->getValues();

        // Get ghost field descriptor
        $ghostField = $this->fieldDescriptors[$ghostBranch['in']->getFieldName()] ?? null;
        if (!$ghostField instanceof DoctrineFieldDescriptorInterface) {
            return null;
        }
        $ghostJoins = $ghostField->getJoins();
        $ghostJoinKeys = \array_keys($ghostJoins);

        // Ghost field needs at least 2 joins (the chain includes localized + others)
        if (\count($ghostJoinKeys) < 2) {
            return null;
        }

        // Find the unlocalized and ghost joins (skip the localized join in the chain)
        $unlocalizedJoin = null;
        $unlocalizedAlias = null;
        $ghostJoin = null;
        $ghostAlias = null;
        $ghostFieldColumn = $ghostField->getFieldName();

        foreach ($ghostJoins as $entityName => $join) {
            if ($entityName === $localizedJoinAlias) {
                continue;
            }
            $alias = $this->encodeAlias($entityName);
            $condition = $join->getJoinCondition() ?? '';

            // The unlocalized join has "locale IS NULL" in its condition
            if (\preg_match('/\blocale\s+IS\s+NULL\b/i', $condition)) {
                $unlocalizedJoin = $join;
                $unlocalizedAlias = $alias;
            } else {
                $ghostJoin = $join;
                $ghostAlias = $alias;
            }
        }

        if (!$unlocalizedJoin || !$ghostJoin || !$unlocalizedAlias || !$ghostAlias) {
            return null;
        }

        // Verify all joins reference the same table
        $unlocalizedTable = $this->resolveJoinedTableName($unlocalizedJoin);
        $ghostTable = $this->resolveJoinedTableName($ghostJoin);
        if ($dcTable !== $unlocalizedTable || $dcTable !== $ghostTable) {
            return null;
        }

        $unlocalizedFk = $this->resolveJoinFkColumn($unlocalizedJoin);
        $ghostFk = $this->resolveJoinFkColumn($ghostJoin);
        if (null === $unlocalizedFk || null === $ghostFk) {
            return null;
        }

        // Collect all join aliases for stripping bare alias IS NULL checks
        $allAliases = [$localizedAlias, $unlocalizedAlias, $ghostAlias];

        // Build parameters
        $params = [];
        $types = [];
        foreach ($this->parameters as $key => $value) {
            $params[$key] = $value;
        }

        // Build IN clause for template values
        $inParamName = 'opt_tpl_' . \count($params);
        $params[$inParamName] = $localizedValues;
        $types[$inParamName] = ArrayParameterType::STRING;

        // Build template filter conditions
        $part1Conditions = [];
        if ($localizedCondition) {
            $part1Conditions[] = $localizedCondition;
        }
        $part1Conditions[] = $localizedAlias . '.' . $localizedFieldColumn . ' IN (:' . $inParamName . ')';

        // Build search conditions if search is active
        $part1LeftJoins = '';
        $ghostExistsLeftJoins = '';

        if (null !== $this->search && !empty($this->searchFields)) {
            $params['search'] = '%' . \str_replace('*', '%', $this->search) . '%';
            $words = \preg_split('/\s+/', \trim($this->search), -1, \PREG_SPLIT_NO_EMPTY);
            $params['searchFulltext'] = \implode(' ', \array_map(static fn (string $w): string => '+' . $w, $words));
            $useFulltextFallback = \mb_strlen($this->search) < 3;

            $localizedSearchParts = [];
            $ghostSearchParts = [];

            foreach ($this->searchFields as $searchField) {
                if ($searchField instanceof DoctrineCaseFieldDescriptor) {
                    if ($useFulltextFallback) {
                        $fieldName = $searchField->getName();
                        $localizedSearchParts[] = $localizedAlias . '.' . $fieldName . ' LIKE :search';
                        $ghostSearchParts[] = $ghostAlias . '.' . $fieldName . ' LIKE :search';
                    }
                    continue;
                }

                if (!$searchField instanceof DoctrineFieldDescriptorInterface) {
                    return null;
                }

                $fieldJoins = $searchField->getJoins();
                if (empty($fieldJoins)) {
                    return null;
                }

                // Classify as ghost if any join in the chain has locale IS NULL
                $isGhost = false;
                foreach ($fieldJoins as $fj) {
                    if (\preg_match('/\blocale\s+IS\s+NULL\b/i', $fj->getJoinCondition() ?? '')) {
                        $isGhost = true;
                        break;
                    }
                }

                // Check if the field is on a separate table (FQCN join, no dot)
                $fieldJoinValues = \array_values($fieldJoins);
                $lastJoin = \end($fieldJoinValues);
                $joinFqcn = $lastJoin->getJoin();

                if ($joinFqcn && !\str_contains($joinFqcn, '.')) {
                    try {
                        $searchTableMeta = $this->em->getClassMetadata($joinFqcn);
                        $searchTableName = $searchTableMeta->getTableName();
                    } catch (\Doctrine\ORM\Mapping\MappingException|\Doctrine\Persistence\Mapping\MappingException) {
                        return null;
                    }

                    if ($searchTableName !== $dcTable) {
                        $stAlias = $isGhost ? 'opt_gst' : 'opt_st';
                        $dcRef = $isGhost ? $ghostAlias : $localizedAlias;

                        // Find the FK column that references the DC table
                        $fkCol = null;
                        $refCol = 'id';
                        foreach ($searchTableMeta->getAssociationMappings() as $assocMapping) {
                            if (!isset($assocMapping['joinColumns'][0])) {
                                continue;
                            }
                            try {
                                $targetMeta = $this->em->getClassMetadata($assocMapping['targetEntity']);
                                if ($targetMeta->getTableName() === $dcTable) {
                                    $fkCol = $assocMapping['joinColumns'][0]['name'];
                                    $refCol = $assocMapping['joinColumns'][0]['referencedColumnName'];
                                    break;
                                }
                            } catch (\Doctrine\ORM\Mapping\MappingException|\Doctrine\Persistence\Mapping\MappingException) {
                                continue;
                            }
                        }

                        if (null === $fkCol) {
                            return null;
                        }

                        $leftJoinSql = ' LEFT JOIN ' . $searchTableName . ' ' . $stAlias
                            . ' ON ' . $stAlias . '.' . $fkCol . ' = ' . $dcRef . '.' . $refCol;

                        if ($isGhost) {
                            $ghostExistsLeftJoins .= $leftJoinSql;
                        } else {
                            $part1LeftJoins .= $leftJoinSql;
                        }

                        // Build search expression with SQL alias and column name
                        $columnName = $searchTableMeta->getColumnName($searchField->getFieldName());
                        $dqlSelect = $searchField->getSelect();
                        $sqlSelect = $stAlias . '.' . $columnName;
                        $searchExpr = \str_replace($dqlSelect, $sqlSelect, $searchField->getSearch());
                    } else {
                        $dcRef = $isGhost ? $ghostAlias : $localizedAlias;
                        $searchExpr = $dcRef . '.' . $searchField->getFieldName() . ' LIKE :search';
                    }
                } else {
                    $dcRef = $isGhost ? $ghostAlias : $localizedAlias;
                    $searchExpr = $dcRef . '.' . $searchField->getFieldName() . ' LIKE :search';
                }

                if ($isGhost) {
                    $ghostSearchParts[] = $searchExpr;
                } else {
                    $localizedSearchParts[] = $searchExpr;
                }
            }

            if (!empty($localizedSearchParts)) {
                $part1Conditions[] = '(' . \implode(' OR ', $localizedSearchParts) . ')';
            }
        }

        // Build Part 1 subselect (localized count)
        $part1Subselect = '(SELECT COUNT(' . $localizedAlias . '.' . $fkColumn . ')'
            . ' FROM ' . $dcTable . ' ' . $localizedAlias
            . $part1LeftJoins
            . ' WHERE ' . \implode(' AND ', $part1Conditions) . ')';

        // Build Part 2 subselect (ghost count) using NOT IN
        $unlocalizedCondition = $unlocalizedJoin->getJoinCondition();

        $notInSubquery = 'SELECT ' . $localizedAlias . '.' . $fkColumn
            . ' FROM ' . $dcTable . ' ' . $localizedAlias;
        if ($localizedCondition) {
            $notInSubquery .= ' WHERE ' . $localizedCondition;
        }
        $notInSql = $unlocalizedAlias . '.' . $unlocalizedFk . ' NOT IN (' . $notInSubquery . ')';

        $ghostConditionRaw = $ghostJoin->getJoinCondition() ?? '';
        $ghostConditionClean = $this->stripBareAliasChecks($ghostConditionRaw, $allAliases);

        $part2Conditions = [];
        if ($unlocalizedCondition) {
            $part2Conditions[] = $unlocalizedCondition;
        }
        $part2Conditions[] = $notInSql;

        $ghostExistsConditions = [$ghostAlias . '.' . $ghostFk . ' = ' . $unlocalizedAlias . '.' . $unlocalizedFk];
        if ($ghostConditionClean) {
            $ghostExistsConditions[] = $ghostConditionClean;
        }
        $ghostExistsConditions[] = $ghostAlias . '.' . $ghostFieldColumn . ' IN (:' . $inParamName . ')';

        // Add ghost search conditions inside the EXISTS
        if (null !== $this->search && !empty($ghostSearchParts)) {
            $ghostExistsConditions[] = '(' . \implode(' OR ', $ghostSearchParts) . ')';
        }

        $part2Conditions[] = 'EXISTS (SELECT 1 FROM ' . $dcTable . ' ' . $ghostAlias
            . $ghostExistsLeftJoins
            . ' WHERE ' . \implode(' AND ', $ghostExistsConditions) . ')';

        $part2Subselect = '(SELECT COUNT(' . $unlocalizedAlias . '.' . $unlocalizedFk . ')'
            . ' FROM ' . $dcTable . ' ' . $unlocalizedAlias
            . ' WHERE ' . \implode(' AND ', $part2Conditions) . ')';

        // Single query: localized count + ghost count
        $sql = 'SELECT ' . $part1Subselect . ' + ' . $part2Subselect . ' AS total';

        $conn = $this->em->getConnection();

        return (int) $conn->fetchOne($sql, $params, $types);
    }

    /**
     * Resolve the FK column name that links the joined entity back to the root entity.
     */
    private function resolveJoinFkColumn(DoctrineJoinDescriptor $join): ?string
    {
        $joinPath = $join->getJoin();
        if (!$joinPath || !\str_contains($joinPath, '.')) {
            return null;
        }

        $associationName = \substr($joinPath, \strrpos($joinPath, '.') + 1);

        try {
            $rootMetadata = $this->em->getClassMetadata($this->entityName);
            if (!$rootMetadata->hasAssociation($associationName)) {
                return null;
            }

            $mapping = $rootMetadata->getAssociationMapping($associationName);

            if (isset($mapping['mappedBy'])) {
                $targetMetadata = $this->em->getClassMetadata($mapping['targetEntity']);
                $inverseMapping = $targetMetadata->getAssociationMapping($mapping['mappedBy']);

                return $inverseMapping['joinColumns'][0]['name'] ?? null;
            }

            return $mapping['joinColumns'][0]['referencedColumnName'] ?? null;
        } catch (\Doctrine\ORM\Mapping\MappingException|\Doctrine\Persistence\Mapping\MappingException) {
            return null;
        }
    }

    /**
     * @param AbstractDoctrineExpression[] $expressions
     */
    private function areAllExpressionTypesSupported(array $expressions): bool
    {
        foreach ($expressions as $expression) {
            if ($expression instanceof DoctrineOrExpression || $expression instanceof DoctrineAndExpression) {
                if (!$this->areAllExpressionTypesSupported($expression->getExpressions())) {
                    return false;
                }
                continue;
            }

            if ($expression instanceof DoctrineInExpression
                || $expression instanceof DoctrineIsNullExpression
                || $expression instanceof DoctrineIsNotNullExpression
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Strip bare alias IS NULL / IS NOT NULL checks from join conditions.
     *
     * In DQL, "dimensionContent IS NULL" means "the LEFT JOIN didn't match."
     * In raw SQL EXISTS subqueries, this doesn't work and the semantic is
     * already handled by the outer expression structure (NOT EXISTS).
     *
     * @param string[] $joinAliases Known join aliases to detect
     */
    private function stripBareAliasChecks(string $condition, array $joinAliases): string
    {
        foreach ($joinAliases as $alias) {
            // Strip "alias IS NULL" and "alias IS NOT NULL" (case-insensitive)
            $condition = \preg_replace(
                '/\b' . \preg_quote($alias, '/') . '\s+IS\s+(NOT\s+)?NULL\b/i',
                '1 = 1',
                $condition,
            );
        }

        // Clean up "AND 1 = 1" fragments
        $condition = \preg_replace('/\bAND\s+1\s*=\s*1\b/', '', $condition);
        $condition = \preg_replace('/\b1\s*=\s*1\s+AND\b/', '', $condition);

        return \trim($condition);
    }

    /**
     * Resolve the database table name for a joined entity via the association metadata.
     * Uses the join path (e.g. "Sulu_Article_Domain_Model_ArticleInterface.dimensionContents")
     * to find the target entity class, then gets its table name.
     *
     * Returns null if resolution fails (caller should bail to DQL fallback).
     */
    private function resolveJoinedTableName(DoctrineJoinDescriptor $join): ?string
    {
        $joinPath = $join->getJoin();
        if (!$joinPath || !\str_contains($joinPath, '.')) {
            return null;
        }

        $pathParts = \explode('.', $joinPath);
        $associationName = \array_pop($pathParts);
        $parentEncodedAlias = \implode('.', $pathParts);

        // Determine the real parent entity class
        $parentEntityClass = null;
        if ($parentEncodedAlias === $this->encodeAlias($this->entityName)) {
            $parentEntityClass = $this->entityName;
        }

        if (null === $parentEntityClass) {
            return null;
        }

        try {
            $parentMetadata = $this->em->getClassMetadata($parentEntityClass);
            if (!$parentMetadata->hasAssociation($associationName)) {
                return null;
            }

            $mapping = $parentMetadata->getAssociationMapping($associationName);
            $targetEntity = $mapping['targetEntity'] ?? null;
            if (!$targetEntity) {
                return null;
            }

            $targetMetadata = $this->em->getClassMetadata($targetEntity);

            return $targetMetadata->getTableName() ?: null;
        } catch (\Doctrine\ORM\Mapping\MappingException|\Doctrine\Persistence\Mapping\MappingException) {
            return null;
        }
    }

    /**
     * @return array<mixed>
     */
    public function execute()
    {
        parent::execute();

        // emit listbuilder.create event
        $event = new ListBuilderCreateEvent($this);
        $this->eventDispatcher->dispatch($event, ListBuilderEvents::LISTBUILDER_CREATE);
        $this->expressionFields = $this->getUniqueExpressionFieldDescriptors($this->expressions);

        // first create simplified id query
        // select ids with all necessary filter data
        $ids = $this->findIdsByGivenCriteria();

        // if no results are found - return
        if (\count($ids) < 1) {
            return [];
        }

        $this->queryBuilder = $this->createNonGroupQueryBuilder(
            $this->em->createQueryBuilder()->from($this->entityName, $this->encodeAlias($this->entityName))
        );

        // now select all data
        $this->assignJoins($this->queryBuilder, $this->getNonGroupJoins());

        // use ids previously selected ids for query
        $select = $this->idField->getSelect();
        $this->queryBuilder->where($select . ' IN (:ids)')->setParameter('ids', $ids);

        $sortFieldIsGrouped = \count(\array_filter($this->sortFields, fn (FieldDescriptorInterface $field) => $this->isGroupingFieldDescriptor($field))) > 0;
        // index the result to properly merge the grouped and non-grouped results and do not mess up sorting
        if ($sortFieldIsGrouped) {
            $this->queryBuilder->indexBy($this->encodeAlias($this->entityName), $this->idField->getSelect());
        }

        $this->assignParameters($this->queryBuilder);

        $nonGroupResult = $this->queryBuilder->getQuery()->getArrayResult();

        if (!$this->hasGroupingFieldDescriptor()) {
            return $nonGroupResult;
        }

        $this->queryBuilder = $this->createGroupQueryBuilder(
            $this->em->createQueryBuilder()->from($this->entityName, $this->encodeAlias($this->entityName))
        );

        // now select all data
        $this->assignJoins($this->queryBuilder, $this->getGroupJoins());

        // use ids previously selected ids for query
        $select = $this->idField->getSelect();
        $this->queryBuilder->where($select . ' IN (:ids)')->setParameter('ids', $ids);
        if (!$sortFieldIsGrouped) {
            $this->queryBuilder->indexBy($this->encodeAlias($this->entityName), $this->idField->getSelect());
        }

        $this->assignParameters($this->queryBuilder);

        $groupResult = $this->queryBuilder->getQuery()->getArrayResult();

        $result = [];
        $primaryResult = $sortFieldIsGrouped ? $groupResult : $nonGroupResult;
        $secondaryResult = $sortFieldIsGrouped ? $nonGroupResult : $groupResult;

        foreach ($primaryResult as $item) {
            $result[] = \array_merge($item, $secondaryResult[$item[$this->idField->getName()]] ?? []);
        }

        return $result;
    }

    protected function hasGroupingFieldDescriptor(): bool
    {
        foreach ($this->selectFields as $field) {
            if ($this->isGroupingFieldDescriptor($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return QueryBuilder
     */
    protected function createGroupQueryBuilder(QueryBuilder $queryBuilder)
    {
        $queryBuilder->addSelect($this->getSelectAs($this->idField));

        // Add all select fields
        foreach ($this->selectFields as $field) {
            if ($this->isGroupingFieldDescriptor($field)) {
                $queryBuilder->addSelect($this->getSelectAs($field));
            }
        }

        // group by
        $this->assignGroupBy($queryBuilder);

        $this->assignSortFields($queryBuilder);

        $queryBuilder->distinct($this->distinct);

        return $queryBuilder;
    }

    /**
     * @return QueryBuilder
     */
    protected function createNonGroupQueryBuilder(QueryBuilder $queryBuilder)
    {
        $hasId = false;

        // Add all select fields
        foreach ($this->selectFields as $field) {
            if (!$this->isGroupingFieldDescriptor($field)) {
                $queryBuilder->addSelect($this->getSelectAs($field));

                if ($field->getName() === $this->idField->getName()) {
                    $hasId = true;
                }
            }
        }

        if (!$hasId) {
            $queryBuilder->addSelect($this->getSelectAs($this->idField));
        }

        // assign sort-fields
        $this->assignSortFields($queryBuilder, false);

        $queryBuilder->distinct($this->distinct);

        return $queryBuilder;
    }

    /**
     * Function that finds all IDs of entities that match the
     * search criteria.
     *
     * @return array
     */
    protected function findIdsByGivenCriteria()
    {
        $applyDistinct = $this->distinct || $this->hasJoins();

        $subQueryBuilder = $this->createSubQueryBuilder(
            $this->getSelectAs($this->idField),
            true,
            $applyDistinct
        );
        if (null != $this->limit) {
            $subQueryBuilder->setMaxResults((int) $this->limit)->setFirstResult((int) ($this->limit * ($this->page - 1)));
        }

        foreach ($this->sortFields as $sortField) {
            if ($sortField->getName() !== $this->idField->getName()) {
                $subQueryBuilder->addSelect($this->getSelectAs($sortField));

                if ($this->isGroupingFieldDescriptor($sortField)) {
                    $subQueryBuilder->addGroupBy($this->idField->getSelect());
                }
            }
        }

        $this->assignSortFields($subQueryBuilder);
        $this->assignParameters($this->queryBuilder);

        $ids = $subQueryBuilder->getQuery()->getArrayResult();

        // if no results are found - return
        if (\count($ids) < 1) {
            return [];
        }

        $ids = \array_map(
            function($array) {
                return $array[$this->idField->getName()];
            },
            $ids
        );

        return $ids;
    }

    private function assignParameters(QueryBuilder $queryBuilder)
    {
        $dql = $queryBuilder->getDQL();

        foreach ($this->parameters as $key => $parameter) {
            if (false !== \strpos($dql, ':' . $key)) {
                $queryBuilder->setParameter($key, $parameter);
            }
        }
    }

    /**
     * Assigns ORDER BY clauses to querybuilder.
     *
     * @param QueryBuilder $queryBuilder
     */
    protected function assignSortFields($queryBuilder, bool $includeGroupBy = true)
    {
        // if no sort has been assigned add order by id ASC as default
        if (0 === \count($this->sortFields)) {
            $queryBuilder->addOrderBy($this->idField->getSelect(), 'ASC');
        }

        foreach ($this->sortFields as $index => $sortField) {
            $statement = $this->getSelectAs($sortField);
            if (!$includeGroupBy && $this->isGroupingFieldDescriptor($sortField)) {
                continue;
            }

            if (!$this->hasSelectStatement($queryBuilder, $statement)) {
                $queryBuilder->addSelect($this->getSelectAs($sortField, true));
            }

            $queryBuilder->addOrderBy($sortField->getName(), $this->sortOrders[$index]);
        }
    }

    protected function hasSelectStatement(QueryBuilder $queryBuilder, $statement)
    {
        foreach ($queryBuilder->getDQLPart('select') as $selectPart) {
            foreach ($selectPart->getParts() as $part) {
                if ($part === $statement) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Sets group by fields to querybuilder.
     *
     * @param QueryBuilder $queryBuilder
     */
    protected function assignGroupBy($queryBuilder)
    {
        $groupByFields = \array_merge($this->groupByFields, $this->sortFields);

        if (!empty($groupByFields)) {
            foreach ($groupByFields as $field) {
                if ($field instanceof DoctrineFieldDescriptor) {
                    $queryBuilder->addGroupBy($field->getSelect());
                }
            }
        }
    }

    /**
     * Returns all the joins required for the query.
     *
     * @return array<string, DoctrineJoinDescriptor>
     */
    protected function getJoins()
    {
        $joins = [];
        /** @var DoctrineFieldDescriptorInterface[] $fields */
        $fields = \array_merge($this->sortFields, $this->selectFields);

        foreach ($fields as $field) {
            $joins = \array_merge($joins, $field->getJoins());
        }

        return $joins;
    }

    protected function getGroupJoins()
    {
        $joins = [];

        foreach ($this->selectFields as $field) {
            if ($this->isGroupingFieldDescriptor($field)) {
                $joins = \array_merge($joins, $field->getJoins());
            }
        }

        /** @var DoctrineFieldDescriptorInterface $field */
        foreach ($this->sortFields as $field) {
            $joins = \array_merge($joins, $field->getJoins());
        }

        return $joins;
    }

    protected function getNonGroupJoins()
    {
        $joins = [];
        /** @var DoctrineFieldDescriptorInterface[] $fields */
        $fields = \array_merge($this->sortFields, $this->selectFields);

        foreach ($fields as $field) {
            if (!$this->isGroupingFieldDescriptor($field)) {
                $joins = \array_merge($joins, $field->getJoins());
            }
        }

        return $joins;
    }

    /**
     * Returns all DoctrineFieldDescriptors that were passed to list builder.
     *
     * @param bool $onlyReturnFilterFields Define if only filtering FieldDescriptors should be returned
     *
     * @return DoctrineFieldDescriptorInterface[]
     */
    protected function getAllFields(bool $onlyReturnFilterFields = false, bool $returnSortFields = true)
    {
        $fields = \array_merge(
            $this->searchFields,
            $this->getUniqueExpressionFieldDescriptors($this->expressions)
        );

        if ($returnSortFields) {
            $fields = \array_merge($fields, $this->sortFields);
        }

        if (true !== $onlyReturnFilterFields) {
            $fields = \array_merge($fields, $this->selectFields);
        }

        return \array_filter($fields, function(FieldDescriptorInterface $fieldDescriptor) {
            return $fieldDescriptor instanceof DoctrineFieldDescriptorInterface;
        });
    }

    /**
     * Creates a query-builder for sub-selecting ID's.
     *
     * @return QueryBuilder
     */
    protected function createSubQueryBuilder(string $select, bool $includeSortFields = true, bool $applyDistinct = false)
    {
        // get all filter-fields
        $filterFields = $this->getAllFields(true, $includeSortFields);

        // get entity names
        $entityNames = $this->getEntityNamesOfFieldDescriptors($filterFields);

        // get necessary joins to achieve filtering
        $addJoins = $this->getNecessaryJoins($entityNames);

        // create querybuilder and add select
        $queryBuilder = $this->createQueryBuilder($addJoins)->select($select);

        if ($applyDistinct) {
            $queryBuilder->distinct(true);
        }

        if ($this->user && $this->permission && \array_key_exists($this->permission, $this->permissions)) {
            if ($this->permissionCheckWithDynamicEntityClass) {
                $this->accessControlQueryEnhancer->enhanceWithDynamicEntityClass(
                    $queryBuilder,
                    $this->user,
                    $this->permissions[$this->permission],
                    $this->securedEntityName,
                    $this->encodeAlias($this->securedEntityName),
                    $this->securedEntityClassField,
                    $this->securedEntityIdField
                );
            } else {
                $this->accessControlQueryEnhancer->enhance(
                    $queryBuilder,
                    $this->user,
                    $this->permissions[$this->permission],
                    $this->securedEntityName,
                    $this->encodeAlias($this->securedEntityName)
                );
            }
        }

        return $queryBuilder;
    }

    /**
     * Function returns all necessary joins for filtering result.
     *
     * @param string[] $necessaryEntityNames
     *
     * @return DoctrineJoinDescriptor[]
     */
    protected function getNecessaryJoins($necessaryEntityNames)
    {
        $addJoins = [];

        // iterate through all field descriptors to find necessary joins
        foreach ($this->getAllFields() as $key => $field) {
            // if field is in any conditional clause -> add join
            if (($field instanceof DoctrineFieldDescriptor || $field instanceof DoctrineJoinDescriptor)
                && false !== \array_search($field->getEntityName(), $necessaryEntityNames)
                && $field->getEntityName() !== $this->entityName
            ) {
                $addJoins = \array_merge($addJoins, $field->getJoins());
            } else {
                // include inner joins
                foreach ($field->getJoins() as $entityName => $join) {
                    if (DoctrineJoinDescriptor::JOIN_METHOD_INNER !== $join->getJoinMethod()
                        && false === \array_search($entityName, $necessaryEntityNames)
                    ) {
                        break;
                    }
                    $addJoins = \array_merge($addJoins, [$entityName => $join]);
                }
            }
        }

        if ($this->user && $this->permission && \array_key_exists($this->permission, $this->permissions)) {
            foreach ($this->permissionCheckFields as $permissionCheckField) {
                $addJoins = \array_merge($addJoins, $permissionCheckField->getJoins());
            }
        }

        return $addJoins;
    }

    /**
     * Returns array of field-descriptor aliases.
     *
     * @param array $filterFields
     *
     * @return string[]
     */
    protected function getEntityNamesOfFieldDescriptors($filterFields)
    {
        $fields = [];

        // filter array for DoctrineFieldDescriptors
        foreach ($filterFields as $field) {
            // add joins of field
            $fields = \array_merge($fields, $field->getJoins());

            if ($field instanceof DoctrineFieldDescriptor
                || $field instanceof DoctrineJoinDescriptor
            ) {
                $fields[] = $field;
            }
        }

        $fieldEntityNames = [];
        foreach ($fields as $key => $field) {
            // special treatment for join descriptors
            if ($field instanceof DoctrineJoinDescriptor) {
                $fieldEntityNames[] = $key;
            }
            $fieldEntityNames[] = $field->getEntityName();
        }

        // unify result
        return \array_unique($fieldEntityNames);
    }

    /**
     * Creates Querybuilder.
     *
     * @param DoctrineJoinDescriptor[]|null $joins Define which joins should be made
     *
     * @return QueryBuilder
     */
    protected function createQueryBuilder($joins = null)
    {
        $this->queryBuilder = $this->em->createQueryBuilder()
            ->from($this->entityName, $this->encodeAlias($this->entityName));

        $this->assignJoins($this->queryBuilder, $joins);

        if (null !== $this->ids) {
            $this->in($this->idField, !empty($this->ids) ? $this->ids : [null]);
        }

        if (null !== $this->excludedIds && !empty($this->excludedIds)) {
            $this->notIn($this->idField, $this->excludedIds);
        }

        // set expressions
        if (!empty($this->expressions)) {
            foreach ($this->expressions as $expression) {
                $this->queryBuilder->andWhere('(' . $expression->getStatement($this->queryBuilder) . ')');
            }
        }

        if (null !== $this->search) {
            $searchParts = [];
            foreach ($this->searchFields as $searchField) {
                $searchParts[] = $searchField->getSearch();
            }

            $this->queryBuilder->andWhere('(' . \implode(' OR ', $searchParts) . ')');
            $this->queryBuilder->setParameter('search', '%' . \str_replace('*', '%', $this->search) . '%');

            $words = \preg_split('/\s+/', \trim($this->search), -1, \PREG_SPLIT_NO_EMPTY);
            $fulltextSearch = \implode(' ', \array_map(static fn (string $w): string => '+' . $w, $words));
            $this->queryBuilder->setParameter('searchFulltext', $fulltextSearch);
        }

        return $this->queryBuilder;
    }

    /**
     * Adds joins to querybuilder.
     *
     * @param array<string, DoctrineJoinDescriptor>|null $joins
     */
    protected function assignJoins(QueryBuilder $queryBuilder, ?array $joins = null)
    {
        if (null === $joins) {
            $joins = $this->getJoins();
        }

        foreach ($joins as $entity => $join) {
            switch ($join->getJoinMethod()) {
                case DoctrineJoinDescriptor::JOIN_METHOD_LEFT:
                    $queryBuilder->leftJoin(
                        $join->getJoin() ?: $entity,
                        $this->encodeAlias($entity),
                        $join->getJoinConditionMethod(),
                        $join->getJoinCondition()
                    );
                    break;
                case DoctrineJoinDescriptor::JOIN_METHOD_INNER:
                    $queryBuilder->innerJoin(
                        $join->getJoin() ?: $entity,
                        $this->encodeAlias($entity),
                        $join->getJoinConditionMethod(),
                        $join->getJoinCondition()
                    );
                    break;
            }
        }
    }

    public function createNotExpression(ExpressionInterface $expression)
    {
        if (!$expression instanceof AbstractDoctrineExpression) {
            throw new InvalidExpressionArgumentException('not', 'expression');
        }

        return new DoctrineNotExpression($expression);
    }

    public function createWhereExpression(FieldDescriptorInterface $fieldDescriptor, $value, $comparator)
    {
        if (!$fieldDescriptor instanceof DoctrineFieldDescriptorInterface) {
            throw new InvalidExpressionArgumentException('where', 'fieldDescriptor');
        }

        return new DoctrineWhereExpression($fieldDescriptor, $value, $comparator);
    }

    public function createInExpression(FieldDescriptorInterface $fieldDescriptor, array $values)
    {
        if (!$fieldDescriptor instanceof DoctrineFieldDescriptorInterface) {
            throw new InvalidExpressionArgumentException('in', 'fieldDescriptor');
        }

        return new DoctrineInExpression($fieldDescriptor, $values);
    }

    public function createBetweenExpression(FieldDescriptorInterface $fieldDescriptor, array $values)
    {
        if (!$fieldDescriptor instanceof DoctrineFieldDescriptorInterface) {
            throw new InvalidExpressionArgumentException('between', 'fieldDescriptor');
        }

        return new DoctrineBetweenExpression($fieldDescriptor, $values[0], $values[1]);
    }

    /**
     * Eliminates duplicated rows.
     *
     * @param bool $flag
     */
    public function distinct($flag = true)
    {
        $this->distinct = $flag;
    }

    /**
     * This is used to determine if DISTINCT should be applied to ID subqueries
     * to prevent duplicate IDs when filtering by joined fields.
     */
    protected function hasJoins(): bool
    {
        $filterFields = $this->getAllFields(true, true);
        foreach ($filterFields as $field) {
            if (!empty($field->getJoins())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Set id-field of the "root" entity.
     */
    public function setIdField(DoctrineFieldDescriptorInterface $idField)
    {
        $this->idField = $idField;
    }

    /**
     * Returns an array of unique expression field descriptors.
     *
     * @param AbstractDoctrineExpression[] $expressions
     *
     * @return array
     */
    protected function getUniqueExpressionFieldDescriptors(array $expressions)
    {
        if (0 === \count($this->expressionFields)) {
            $descriptors = [];
            $uniqueNames = \array_unique($this->getAllFieldNames($expressions));
            foreach ($uniqueNames as $uniqueName) {
                $descriptors[] = $this->fieldDescriptors[$uniqueName];
            }

            $this->expressionFields = $descriptors;

            return $descriptors;
        }

        return $this->expressionFields;
    }

    /**
     * Returns all fieldnames used in the expressions.
     *
     * @param AbstractDoctrineExpression[] $expressions
     *
     * @return array
     */
    protected function getAllFieldNames($expressions)
    {
        $fieldNames = [];
        foreach ($expressions as $expression) {
            if ($expression instanceof ConjunctionExpressionInterface) {
                $fieldNames = \array_merge($fieldNames, $expression->getFieldNames());
            } elseif ($expression instanceof BasicExpressionInterface) {
                $fieldNames[] = $expression->getFieldName();
            }
        }

        return $fieldNames;
    }

    public function createAndExpression(array $expressions)
    {
        if (\count($expressions) >= 2) {
            return new DoctrineAndExpression($expressions);
        }

        throw new InvalidExpressionArgumentException('and', 'expressions');
    }

    public function createOrExpression(array $expressions)
    {
        if (\count($expressions) >= 2) {
            return new DoctrineOrExpression($expressions);
        }

        throw new InvalidExpressionArgumentException('or', 'expressions');
    }

    /**
     * Get select as from doctrine field descriptor.
     *
     * @param bool $hidden
     *
     * @return string
     */
    private function getSelectAs(DoctrineFieldDescriptorInterface $field, $hidden = false)
    {
        $select = $field->getSelect() . ' AS ';

        if ($hidden) {
            $select .= 'HIDDEN ';
        }

        return $select . $field->getName();
    }

    protected function isGroupingFieldDescriptor(FieldDescriptorInterface $field): bool
    {
        return $field instanceof DoctrineCountFieldDescriptor
            || $field instanceof DoctrineGroupConcatFieldDescriptor;
    }

    public function createIsNullExpression(FieldDescriptorInterface $fieldDescriptor)
    {
        if (!$fieldDescriptor instanceof DoctrineFieldDescriptorInterface) {
            throw new InvalidExpressionArgumentException('is_null', 'fieldDescriptor');
        }

        return new DoctrineIsNullExpression($fieldDescriptor);
    }

    public function createIsNotNullExpression(FieldDescriptorInterface $fieldDescriptor)
    {
        if (!$fieldDescriptor instanceof DoctrineFieldDescriptorInterface) {
            throw new InvalidExpressionArgumentException('is_not_null', 'fieldDescriptor');
        }

        return new DoctrineIsNotNullExpression($fieldDescriptor);
    }
}
