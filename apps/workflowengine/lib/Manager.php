<?php
/**
 * @copyright Copyright (c) 2016 Morris Jobke <hey@morrisjobke.de>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\WorkflowEngine;


use OC\Files\Storage\Wrapper\Jail;
use Doctrine\DBAL\DBALException;
use OC\Cache\CappedMemoryCache;
use OCA\WorkflowEngine\Entity\File;
use OCA\WorkflowEngine\Helper\ScopeContext;
use OCP\AppFramework\QueryException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Storage\IStorage;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IServerContainer;
use OCP\IUserSession;
use OCP\WorkflowEngine\ICheck;
use OCP\WorkflowEngine\IEntity;
use OCP\WorkflowEngine\IEntityAware;
use OCP\WorkflowEngine\IManager;
use OCP\WorkflowEngine\IOperation;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

class Manager implements IManager, IEntityAware {

	/** @var IStorage */
	protected $storage;

	/** @var string */
	protected $path;

	/** @var object */
	protected $entity;

	/** @var array[] */
	protected $operations = [];

	/** @var array[] */
	protected $checks = [];

	/** @var IDBConnection */
	protected $connection;

	/** @var IServerContainer|\OC\Server */
	protected $container;

	/** @var IL10N */
	protected $l;

	/** @var EventDispatcherInterface */
	protected $eventDispatcher;

	/** @var IEntity[] */
	protected $registeredEntities = [];

	/** @var ILogger */
	protected $logger;

	/** @var CappedMemoryCache */
	protected $operationsByScope = [];

	/** @var IUserSession */
	protected $session;

	/**
	 * @param IDBConnection $connection
	 * @param IServerContainer $container
	 * @param IL10N $l
	 */
	public function __construct(
		IDBConnection $connection,
		IServerContainer $container,
		IL10N $l,
		EventDispatcherInterface $eventDispatcher,
		ILogger $logger,
		IUserSession $session
	) {
		$this->connection = $connection;
		$this->container = $container;
		$this->l = $l;
		$this->eventDispatcher = $eventDispatcher;
		$this->logger = $logger;
		$this->operationsByScope = new CappedMemoryCache(64);
		$this->session = $session;
	}

	/**
	 * @inheritdoc
	 */
	public function setFileInfo(IStorage $storage, $path) {
		$this->storage = $storage;

		if ($storage->instanceOfStorage(Jail::class)) {
			$path = $storage->getJailedPath($path);
		}
		$this->path = $path;
	}

	/**
	 * @inheritdoc
	 */
	public function getMatchingOperations($class, $returnFirstMatchingOperationOnly = true) {
		$scopes[] = new ScopeContext(IManager::SCOPE_ADMIN);
		$user = $this->session->getUser();
		if($user !== null) {
			$scopes[] = new ScopeContext(IManager::SCOPE_USER, $user->getUID());
		}

		$operations = [];
		foreach ($scopes as $scope) {
			$operations = array_merge($operations, $this->getOperations($class, $scope));
		}

		$matches = [];
		foreach ($operations as $operation) {
			$checkIds = json_decode($operation['checks'], true);
			$checks = $this->getChecks($checkIds);

			foreach ($checks as $check) {
				if (!$this->check($check)) {
					// Check did not match, continue with the next operation
					continue 2;
				}
			}

			if ($returnFirstMatchingOperationOnly) {
				return $operation;
			}
			$matches[] = $operation;
		}

		return $matches;
	}

	/**
	 * @param array $check
	 * @return bool
	 */
	protected function check(array $check) {
		try {
			$checkInstance = $this->container->query($check['class']);
		} catch (QueryException $e) {
			// Check does not exist, assume it matches.
			return true;
		}

		if ($checkInstance instanceof IEntityAware && $this->entity !== null) {
			$checkInstance->setEntity($this->entity);
			return $checkInstance->executeCheck($check['operator'], $check['value']);
		} elseif ($checkInstance instanceof ICheck) {
			$checkInstance->setFileInfo($this->storage, $this->path);
			return $checkInstance->executeCheck($check['operator'], $check['value']);
		} else {
			// Check is invalid
			throw new \UnexpectedValueException($this->l->t('Check %s is invalid or does not exist', $check['class']));
		}
	}
	public function getAllOperations(ScopeContext $scopeContext): array {
		if(isset($this->operations[$scopeContext->getHash()])) {
			return $this->operations[$scopeContext->getHash()];
		}

		$query = $this->connection->getQueryBuilder();

		$query->select('o.*')
			->from('flow_operations', 'o')
			->leftJoin('o', 'flow_operations_scope', 's', $query->expr()->eq('o.id', 's.operation_id'))
			->where($query->expr()->eq('s.type', $query->createParameter('scope')));

		if($scopeContext->getScope() === IManager::SCOPE_USER) {
			$query->andWhere($query->expr()->eq('s.value', $query->createParameter('scopeId')));
		}

		$query->setParameters(['scope' => $scopeContext->getScope(), 'scopeId' => $scopeContext->getScopeId()]);
		$result = $query->execute();

		$this->operations[$scopeContext->getHash()] = [];
		while ($row = $result->fetch()) {
			if(!isset($this->operations[$scopeContext->getHash()][$row['class']])) {
				$this->operations[$scopeContext->getHash()][$row['class']] = [];
			}
			$this->operations[$scopeContext->getHash()][$row['class']][] = $row;
		}

		return $this->operations[$scopeContext->getHash()];
	}

	public function getOperations(string $class, ScopeContext $scopeContext): array {
		if (!isset($this->operations[$scopeContext->getHash()])) {
			$this->getAllOperations($scopeContext);
		}
		return $this->operations[$scopeContext->getHash()][$class] ?? [];
	}

	/**
	 * @param int $id
	 * @return array
	 * @throws \UnexpectedValueException
	 */
	protected function getOperation($id) {
		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from('flow_operations')
			->where($query->expr()->eq('id', $query->createNamedParameter($id)));
		$result = $query->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row) {
			return $row;
		}

		throw new \UnexpectedValueException($this->l->t('Operation #%s does not exist', [$id]));
	}

	protected function insertOperation(string $class, string $name, array $checkIds, string $operation): int {
		$query = $this->connection->getQueryBuilder();
		$query->insert('flow_operations')
			->values([
				'class' => $query->createNamedParameter($class),
				'name' => $query->createNamedParameter($name),
				'checks' => $query->createNamedParameter(json_encode(array_unique($checkIds))),
				'operation' => $query->createNamedParameter($operation),
			]);
		$query->execute();

		return $query->getLastInsertId();
	}

	/**
	 * @param string $class
	 * @param string $name
	 * @param array[] $checks
	 * @param string $operation
	 * @return array The added operation
	 * @throws \UnexpectedValueException
	 * @throws DBALException
	 */
	public function addOperation($class, $name, array $checks, $operation, ScopeContext $scope) {
		$this->validateOperation($class, $name, $checks, $operation);

		$this->connection->beginTransaction();

		try {
			$checkIds = [];
			foreach ($checks as $check) {
				$checkIds[] = $this->addCheck($check['class'], $check['operator'], $check['value']);
			}

			$id = $this->insertOperation($class, $name, $checkIds, $operation);
			$this->addScope($id, $scope);

			$this->connection->commit();
		} catch (DBALException $e) {
			$this->connection->rollBack();
			throw $e;
		}

		return $this->getOperation($id);
	}

	protected function canModify(int $id, ScopeContext $scopeContext):bool {
		if(isset($this->operationsByScope[$scopeContext->getHash()])) {
			return in_array($id, $this->operationsByScope[$scopeContext->getHash()], true);
		}

		$qb = $this->connection->getQueryBuilder();
		$qb = $qb->select('o.id')
			->from('flow_operations', 'o')
			->leftJoin('o', 'flow_operations_scope', 's', $qb->expr()->eq('o.id', 's.operation_id'))
			->where($qb->expr()->eq('s.type', $qb->createParameter('scope')));

		if($scopeContext->getScope() !== IManager::SCOPE_ADMIN) {
			$qb->where($qb->expr()->eq('s.value', $qb->createParameter('scopeId')));
		}

		$qb->setParameters(['scope' => $scopeContext->getScope(), 'scopeId' => $scopeContext->getScopeId()]);
		$result = $qb->execute();

		$this->operationsByScope[$scopeContext->getHash()] = [];
		while($opId = $result->fetchColumn(0)) {
			$this->operationsByScope[$scopeContext->getHash()][] = (int)$opId;
		}
		$result->closeCursor();

		return in_array($id, $this->operationsByScope[$scopeContext->getHash()], true);
	}

	/**
	 * @param int $id
	 * @param string $name
	 * @param array[] $checks
	 * @param string $operation
	 * @return array The updated operation
	 * @throws \UnexpectedValueException
	 * @throws \DomainException
	 * @throws DBALException
	 */
	public function updateOperation($id, $name, array $checks, $operation, ScopeContext $scopeContext): array {
		if(!$this->canModify($id, $scopeContext)) {
			throw new \DomainException('Target operation not within scope');
		};
		$row = $this->getOperation($id);
		$this->validateOperation($row['class'], $name, $checks, $operation);

		$checkIds = [];
		try {
			$this->connection->beginTransaction();
			foreach ($checks as $check) {
				$checkIds[] = $this->addCheck($check['class'], $check['operator'], $check['value']);
			}

			$query = $this->connection->getQueryBuilder();
			$query->update('flow_operations')
				->set('name', $query->createNamedParameter($name))
				->set('checks', $query->createNamedParameter(json_encode(array_unique($checkIds))))
				->set('operation', $query->createNamedParameter($operation))
				->where($query->expr()->eq('id', $query->createNamedParameter($id)));
			$query->execute();
			$this->connection->commit();
		} catch (DBALException $e) {
			$this->connection->rollBack();
			throw $e;
		}
		unset($this->operations[$scopeContext->getHash()]);

		return $this->getOperation($id);
	}

	/**
	 * @param int $id
	 * @return bool
	 * @throws \UnexpectedValueException
	 * @throws DBALException
	 * @throws \DomainException
	 */
	public function deleteOperation($id, ScopeContext $scopeContext) {
		if(!$this->canModify($id, $scopeContext)) {
			throw new \DomainException('Target operation not within scope');
		};
		$query = $this->connection->getQueryBuilder();
		try {
			$this->connection->beginTransaction();
			$result = (bool)$query->delete('flow_operations')
				->where($query->expr()->eq('id', $query->createNamedParameter($id)))
				->execute();
			if($result) {
				$qb = $this->connection->getQueryBuilder();
				$result &= (bool)$qb->delete('flow_operations_scope')
					->where($qb->expr()->eq('operation_id', $query->createNamedParameter($id)))
					->execute();
			}
			$this->connection->commit();
		} catch (DBALException $e) {
			$this->connection->rollBack();
			throw $e;
		}

		if(isset($this->operations[$scopeContext->getHash()])) {
			unset($this->operations[$scopeContext->getHash()]);
		}

		return $result;
	}

	/**
	 * @param string $class
	 * @param string $name
	 * @param array[] $checks
	 * @param string $operation
	 * @throws \UnexpectedValueException
	 */
	protected function validateOperation($class, $name, array $checks, $operation) {
		try {
			/** @var IOperation $instance */
			$instance = $this->container->query($class);
		} catch (QueryException $e) {
			throw new \UnexpectedValueException($this->l->t('Operation %s does not exist', [$class]));
		}

		if (!($instance instanceof IOperation)) {
			throw new \UnexpectedValueException($this->l->t('Operation %s is invalid', [$class]));
		}

		$instance->validateOperation($name, $checks, $operation);

		foreach ($checks as $check) {
			try {
				/** @var ICheck $instance */
				$instance = $this->container->query($check['class']);
			} catch (QueryException $e) {
				throw new \UnexpectedValueException($this->l->t('Check %s does not exist', [$class]));
			}

			if (!($instance instanceof ICheck)) {
				throw new \UnexpectedValueException($this->l->t('Check %s is invalid', [$class]));
			}

			$instance->validateCheck($check['operator'], $check['value']);
		}
	}

	/**
	 * @param int[] $checkIds
	 * @return array[]
	 */
	public function getChecks(array $checkIds) {
		$checkIds = array_map('intval', $checkIds);

		$checks = [];
		foreach ($checkIds as $i => $checkId) {
			if (isset($this->checks[$checkId])) {
				$checks[$checkId] = $this->checks[$checkId];
				unset($checkIds[$i]);
			}
		}

		if (empty($checkIds)) {
			return $checks;
		}

		$query = $this->connection->getQueryBuilder();
		$query->select('*')
			->from('flow_checks')
			->where($query->expr()->in('id', $query->createNamedParameter($checkIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$result = $query->execute();

		while ($row = $result->fetch()) {
			$this->checks[(int) $row['id']] = $row;
			$checks[(int) $row['id']] = $row;
		}
		$result->closeCursor();

		$checkIds = array_diff($checkIds, array_keys($checks));

		if (!empty($checkIds)) {
			$missingCheck = array_pop($checkIds);
			throw new \UnexpectedValueException($this->l->t('Check #%s does not exist', $missingCheck));
		}

		return $checks;
	}

	/**
	 * @param string $class
	 * @param string $operator
	 * @param string $value
	 * @return int Check unique ID
	 */
	protected function addCheck($class, $operator, $value) {
		$hash = md5($class . '::' . $operator . '::' . $value);

		$query = $this->connection->getQueryBuilder();
		$query->select('id')
			->from('flow_checks')
			->where($query->expr()->eq('hash', $query->createNamedParameter($hash)));
		$result = $query->execute();

		if ($row = $result->fetch()) {
			$result->closeCursor();
			return (int) $row['id'];
		}

		$query = $this->connection->getQueryBuilder();
		$query->insert('flow_checks')
			->values([
				'class' => $query->createNamedParameter($class),
				'operator' => $query->createNamedParameter($operator),
				'value' => $query->createNamedParameter($value),
				'hash' => $query->createNamedParameter($hash),
			]);
		$query->execute();

		return $query->getLastInsertId();
	}

	protected function addScope(int $operationId, ScopeContext $scope): void {
		$query = $this->connection->getQueryBuilder();

		$insertQuery = $query->insert('flow_operations_scope');
		$insertQuery->values([
			'operation_id' => $query->createNamedParameter($operationId),
			'type' => $query->createNamedParameter($scope->getScope()),
			'value' => $query->createNamedParameter($scope->getScopeId()),
		]);
		$insertQuery->execute();
	}

	public function formatOperation(array $operation): array {
		$checkIds = json_decode($operation['checks'], true);
		$checks = $this->getChecks($checkIds);

		$operation['checks'] = [];
		foreach ($checks as $check) {
			// Remove internal values
			unset($check['id']);
			unset($check['hash']);

			$operation['checks'][] = $check;
		}

		return $operation;
	}

	/**
	 * @param object $entity
	 * @since 18.0.0
	 */
	public function setEntity($entity) {
		if(!is_object($entity)) {
			$this->container->getLogger()->logException(
				new \InvalidArgumentException('provided entity is not an object'),
				[
					'app' => 'workflowengine',
					'level' => ILogger::ERROR,
				]
			);
			return;
		}
		$this->entity = $entity;
	}

	/**
	 * @return IEntity[]
	 */
	public function getEntitiesList() {
		$this->eventDispatcher->dispatch('OCP\WorkflowEngine::registerEntities', new GenericEvent($this));

		return array_merge($this->getBuildInEntities(), $this->registeredEntities);
	}

	/**
	 * Listen to 'OCP/WorkflowEngine::registerEntities' at the EventDispatcher
	 * for registering your entities
	 *
	 * @since 18.0.0
	 */
	public function registerEntity(IEntity $entity): void {
		$this->registeredEntities[$entity->getId()] = $entity;
	}

	/**
	 * @return IEntity[]
	 */
	protected function getBuildInEntities(): array {
		try {
			return [
				$this->container->query(File::class),
			];
		} catch (QueryException $e) {
			$this->logger->logException($e);
			return [];
		}
	}
}
