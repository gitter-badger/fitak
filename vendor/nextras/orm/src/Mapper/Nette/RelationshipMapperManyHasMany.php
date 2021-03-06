<?php

/**
 * This file is part of the Nextras\ORM library.
 *
 * @license    MIT
 * @link       https://github.com/nextras/orm
 * @author     Jan Skrasek
 */

namespace Nextras\Orm\Mapper\Nette;

use Nette\Object;
use Nette\Database\Context;
use Nette\Database\Table\SqlBuilder;
use Nextras\Orm\Entity\IEntity;
use Nextras\Orm\Entity\Collection\EntityIterator;
use Nextras\Orm\Entity\Collection\ICollection;
use Nextras\Orm\Entity\Collection\IEntityIterator;
use Nextras\Orm\Entity\Reflection\PropertyMetadata;
use Nextras\Orm\Mapper\IRelationshipMapperManyHasMany;
use Nextras\Orm\Mapper\IMapper;
use Nextras\Orm\Repository\IRepository;
use Nextras\Orm\LogicException;


/**
 * ManyHasMany relationship mapper for Nette\Database.
 */
class RelationshipMapperManyHasMany extends Object implements IRelationshipMapperManyHasMany
{
	/** @var Context */
	protected $context;

	/** @var NetteMapper */
	protected $mapperOne;

	/** @var NetteMapper */
	protected $mapperTwo;

	/** @var PropertyMetadata */
	protected $metadata;

	/** @var IEntityIterator[] */
	protected $cacheEntityIterator;

	/** @var int[] */
	protected $cacheCounts;

	/** @var string */
	protected $joinTable;

	/** @var string */
	protected $primaryKeyFrom;

	/** @var string */
	protected $primaryKeyTo;

	/** @var IRepository */
	protected $targetRepository;


	public function __construct(Context $context, IMapper $mapperOne, IMapper $mapperTwo, PropertyMetadata $metadata)
	{
		$this->context   = $context;
		$this->mapperOne = $mapperOne;
		$this->mapperTwo = $mapperTwo;
		$this->metadata  = $metadata;

		$parameters = $mapperOne->getManyHasManyParameters($mapperTwo);
		$this->joinTable = $parameters[0];

		if ($this->metadata->relationshipIsMain) {
			$this->targetRepository = $this->mapperTwo->getRepository();
			list($this->primaryKeyFrom, $this->primaryKeyTo) = $parameters[1];
		} else {
			$this->targetRepository = $this->mapperOne->getRepository();
			list($this->primaryKeyTo, $this->primaryKeyFrom) = $parameters[1];
		}
	}


	// ==== ITERATOR ===================================================================================================


	public function getIterator(IEntity $parent, ICollection $collection)
	{
		/** @var IEntityIterator $iterator */
		$iterator = $this->execute($collection, $parent);
		$iterator->setDataIndex($parent->id);
		return $iterator;
	}


	protected function execute(ICollection $collection, IEntity $parent)
	{
		$collectionMapper = $collection->getCollectionMapper();
		if (!$collectionMapper instanceof CollectionMapper) {
			throw new LogicException();
		}

		$builder = $collectionMapper->getSqlBuilder();
		$preloadIterator = $parent->getPreloadContainer();
		$values = $preloadIterator ? $preloadIterator->getPreloadValues('id') : [$parent->id];
		$cacheKey = $this->calculateCacheKey($builder, $values);

		$data = & $this->cacheEntityIterator[$cacheKey];
		if ($data !== NULL) {
			return $data;
		}

		$data = $this->fetchByTwoPassStrategy($builder, $values);
		return $data;
	}


	private function fetchByTwoPassStrategy(SqlBuilder $builder, array $values)
	{
		$builder = clone $builder;
		$builder->addSelect(":{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyTo");
		$builder->addSelect(":{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyFrom");

		if ($builder->getLimit() && $builder->getLimit() !== 1) {
			$sqls = $args = [];
			foreach ($values as $value) {
				$builderPart = clone $builder;
				$builderPart->addWhere(":{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyFrom", $value);

				$sqls[] = $builderPart->buildSelectQuery();
				$args = array_merge($args, $builderPart->getParameters());
			}

			$query = '(' . implode(') UNION ALL (', $sqls) . ')';
			$result = $this->context->queryArgs($query, $args);

		} else {
			$builder->addWhere(":{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyFrom", $values);
			$result = $this->context->queryArgs($builder->buildSelectQuery(), $builder->getParameters());
		}

		$values = [];
		foreach ($result->fetchAll() as $row) {
			$values[$row->{$this->primaryKeyTo}] = NULL;
		}

		if (count($values) === 0) {
			return new EntityIterator([]);
		}

		$entitiesResult = $this->targetRepository->findById(array_keys($values));
		$entitiesResult->getIterator();

		$entities = [];
		foreach ($result->fetchAll() as $row) {
			$entities[$row->{$this->primaryKeyFrom}][] = $this->targetRepository->getById($row->{$this->primaryKeyTo});
		}

		return new EntityIterator($entities);
	}


	// ==== ITERATOR COUNT =============================================================================================


	public function getIteratorCount(IEntity $parent, ICollection $collection)
	{
		$counts = $this->executeCounts($collection, $parent);
		return isset($counts[$parent->id]) ? $counts[$parent->id] : 0;
	}


	protected function executeCounts(ICollection $collection, IEntity $parent)
	{
		$collectionMapper = $collection->getCollectionMapper();
		if (!$collectionMapper instanceof CollectionMapper) {
			throw new LogicException();
		}

		$builder = $collectionMapper->getSqlBuilder();
		$preloadIterator = $parent->getPreloadContainer();
		$values = $preloadIterator ? $preloadIterator->getPreloadValues('id') : [$parent->id];
		$cacheKey = $this->calculateCacheKey($builder, $values);

		$data = & $this->cacheCounts[$cacheKey];
		if ($data !== NULL) {
			return $data;
		}

		$data = $this->fetchCounts($builder, $values);
		return $data;
	}


	private function fetchCounts(SqlBuilder $builder, array $values)
	{
		$builder = clone $builder;
		$builder->addSelect(":{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyFrom");
		$builder->setOrder([], []);

		if ($builder->getLimit() || $builder->getOffset()) {
			$sqls = [];
			$args = [];
			foreach ($values as $value) {
				$build = clone $builder;

				$sqls[] = "SELECT ? as {$this->primaryKeyFrom}, COUNT(*) AS count FROM (" . $build->buildSelectQuery() . ') temp';
				$args[] = $value;
				$args = array_merge($args, $build->getParameters());
			}

			$sql = '(' . implode(') UNION ALL (', $sqls) . ')';
			$result = $this->context->queryArgs($sql, $args)->fetchAll();

		} else {
			$builder->addWhere(":{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyFrom", $values);
			$builder->addSelect("COUNT(:{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyTo) AS count");
			$builder->setGroup(":{$this->joinTable}($this->primaryKeyTo).$this->primaryKeyFrom");
			$result = $this->context->queryArgs($builder->buildSelectQuery(), $builder->getParameters())->fetchAll();
		}

		$counts = [];
		foreach ($result as $row) {
			$counts[$row->{$this->primaryKeyFrom}] = $row['count'];
		}
		return $counts;
	}


	// ==== OTHERS =====================================================================================================


	public function add(IEntity $parent, array $add)
	{
		$this->mapperOne->beginTransaction();
		$list = $this->buildList($parent, $add);
		$builder = new SqlBuilder($this->joinTable, $this->context);
		$this->context->query($builder->buildInsertQuery(), $list);
	}


	public function remove(IEntity $parent, array $remove)
	{
		$this->mapperOne->beginTransaction();
		$list = $this->buildList($parent, $remove);
		$builder = new SqlBuilder($this->joinTable, $this->context);
		$builder->addWhere(array_keys(reset($list)), array_map('array_values', $list));
		$this->context->queryArgs($builder->buildDeleteQuery(), $builder->getParameters());
	}


	protected function buildList(IEntity $parent, array $entries)
	{
		if (!$this->metadata->relationshipIsMain) {
			throw new LogicException('ManyHasMany relationship has to be persited on the primary mapper.');
		}

		$list = [];
		$primaryId = $parent->id;
		foreach ($entries as $id) {
			$list[] = [
				$this->primaryKeyFrom => $primaryId,
				$this->primaryKeyTo => $id,
			];
		}

		return $list;
	}


	protected function calculateCacheKey(SqlBuilder $builder, $values)
	{
		return md5($builder->buildSelectQuery() . json_encode($builder->getParameters()) . json_encode($values));
	}

}
