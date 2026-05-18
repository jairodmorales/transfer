<?php

declare(strict_types=1);

namespace OCA\Transfer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Represents one queued or completed transfer job.
 *
 * Status lifecycle: queued → running → done | failed
 *
 * @method string  getToken()
 * @method void    setToken(string $token)
 * @method string  getUserId()
 * @method void    setUserId(string $userId)
 * @method string  getUrl()
 * @method void    setUrl(string $url)
 * @method string  getPath()
 * @method void    setPath(string $path)
 * @method string  getStatus()
 * @method void    setStatus(string $status)
 * @method ?string getError()
 * @method void    setError(?string $error)
 * @method int     getCreatedAt()
 * @method void    setCreatedAt(int $createdAt)
 * @method int     getUpdatedAt()
 * @method void    setUpdatedAt(int $updatedAt)
 */
class TransferJobEntity extends Entity {
	public const STATUS_QUEUED  = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	/** All terminal statuses — polling stops when all jobs reach one of these. */
	public const TERMINAL_STATUSES = [self::STATUS_DONE, self::STATUS_FAILED];

	protected string $token    = '';
	protected string $userId   = '';
	protected string $url      = '';
	protected string $path     = '';
	protected string $status   = self::STATUS_QUEUED;
	protected ?string $error   = null;
	protected int $createdAt   = 0;
	protected int $updatedAt   = 0;

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
