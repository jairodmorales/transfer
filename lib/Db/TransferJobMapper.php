<?php

declare(strict_types=1);

namespace OCA\Transfer\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TransferJobEntity>
 */
class TransferJobMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'transfer_jobs', TransferJobEntity::class);
	}

	/**
	 * Find a specific job by its token, scoped to a user.
	 *
	 * The user_id constraint prevents one user from reading another user's job
	 * status even if they somehow obtain the token.
	 *
	 * @throws DoesNotExistException
	 */
	public function findByToken(string $token, string $userId): TransferJobEntity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * Return the most recent jobs for a user, newest first.
	 *
	 * @param int $since Unix timestamp — only jobs created at or after this time
	 * @return TransferJobEntity[]
	 */
	public function findRecentByUser(string $userId, int $since): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC')
			->setMaxResults(100);

		return $this->findEntities($qb);
	}

	/**
	 * Delete jobs older than the given timestamp.
	 * Used by the periodic cleanup job to keep the table small.
	 *
	 * @return int Number of rows deleted
	 */
	public function deleteOlderThan(int $timestamp): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('created_at', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Update only the status (and optionally the error message) of a job,
	 * touching updated_at. Avoids reloading and re-saving the full entity
	 * from a background job that only knows the token.
	 */
	public function updateStatus(string $token, string $status, ?string $error = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		if ($error !== null) {
			$qb->set('error', $qb->createNamedParameter($error));
		}

		$qb->executeStatement();
	}
}
