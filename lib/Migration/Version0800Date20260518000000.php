<?php

declare(strict_types=1);

namespace OCA\Transfer\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCA\Transfer\Db\TransferJobEntity;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the transfer_jobs table that tracks download job status so the
 * frontend can poll for progress without relying solely on the Activity log.
 *
 * Introduced in app version 0.8.0.
 */
class Version0800Date20260518000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Idempotent: skip if the table already exists (e.g. re-run after failure)
		if ($schema->hasTable('transfer_jobs')) {
			return null;
		}

		$table = $schema->createTable('transfer_jobs');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);

		// Unguessable token returned to the browser so it can poll this job's status
		$table->addColumn('token', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);

		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);

		$table->addColumn('url', Types::TEXT, [
			'notnull' => true,
		]);

		// Full destination path inside the user's file space, e.g. /Documents/report.pdf
		$table->addColumn('path', Types::STRING, [
			'notnull' => true,
			'length' => 512,
		]);

		// queued | running | done | failed  (see TransferJobEntity::STATUS_*)
		$table->addColumn('status', Types::STRING, [
			'notnull' => true,
			'length' => 16,
			'default' => TransferJobEntity::STATUS_QUEUED,
		]);

		// Human-readable failure reason, populated only when status = failed
		$table->addColumn('error', Types::TEXT, [
			'notnull' => false,
			'default' => null,
		]);

		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
		]);

		$table->addColumn('updated_at', Types::BIGINT, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);

		// Enforces token uniqueness and provides O(1) lookup for polling
		$table->addUniqueIndex(['token'], 'transfer_jobs_token');

		// Used by findRecentByUser() to list jobs per user efficiently
		$table->addIndex(['user_id', 'created_at'], 'transfer_jobs_user_created');

		return $schema;
	}
}
