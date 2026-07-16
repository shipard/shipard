<?php

namespace imports\newShipard\libs;

final class LocalIdMap
{
	public const ENTITY_BANK_ACCOUNT     = 'bankAccount';
	public const ENTITY_COST_CENTER      = 'costCenter';
	public const ENTITY_WAREHOUSE        = 'warehouse';
	public const ENTITY_CASH_DESK        = 'cashDesk';
	public const ENTITY_NUMBER_SERIES    = 'numberSeries';
	public const ENTITY_FISCAL_YEAR      = 'fiscalYear';
	public const ENTITY_FISCAL_MONTH     = 'fiscalMonth';
	public const ENTITY_VAT_REGISTRATION = 'vatRegistration';
	public const ENTITY_VAT_PERIOD       = 'vatPeriod';
	public const ENTITY_ITEM_KIND        = 'itemKind';
	public const ENTITY_UNIT             = 'unit';
	public const ENTITY_PERSON           = 'person';
	public const ENTITY_ITEM             = 'item';
	public const ENTITY_DOC              = 'doc';
	public const ENTITY_BANK_STATEMENT   = 'bankStatement';
	public const ENTITY_MAILBOX          = 'mailbox';
	public const ENTITY_MESSAGE          = 'message';
	public const ENTITY_ACCOUNT          = 'accountingAccount';
	public const ENTITY_BINDER           = 'binder';        // starý kořen-folder ndx → nový base_registry_binders.id
	public const ENTITY_REGISTRY_DOC     = 'registryDoc';   // starý wkf_docs_documents.ndx → nový base_registry_documents.id

	private \PDO $pdo;

	public function __construct(private readonly string $sqliteFilePath)
	{
		$isNew = !is_file($sqliteFilePath);

		$this->pdo = new \PDO('sqlite:' . $sqliteFilePath);
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('PRAGMA journal_mode=WAL');

		if ($isNew)
			@chmod($sqliteFilePath, 0600);

		$this->migrate();
	}

	private function migrate(): void
	{
		$this->pdo->exec(
			'CREATE TABLE IF NOT EXISTS id_map ('
			. ' entity_type   VARCHAR(50)  NOT NULL,'
			. ' old_ndx       INTEGER      NOT NULL,'
			. ' new_id        INTEGER      NOT NULL,'
			. ' imported_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,'
			. ' last_updated  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,'
			. ' PRIMARY KEY (entity_type, old_ndx)'
			. ')'
		);
		$this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_id_map_new_id ON id_map (entity_type, new_id)');
	}

	public function lookup(string $entityType, int $oldNdx): ?int
	{
		$stmt = $this->pdo->prepare('SELECT new_id FROM id_map WHERE entity_type = :t AND old_ndx = :o');
		$stmt->execute([':t' => $entityType, ':o' => $oldNdx]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ? (int) $row['new_id'] : null;
	}

	public function lookupByNewId(string $entityType, int $newId): ?int
	{
		$stmt = $this->pdo->prepare('SELECT old_ndx FROM id_map WHERE entity_type = :t AND new_id = :n');
		$stmt->execute([':t' => $entityType, ':n' => $newId]);
		$row = $stmt->fetch(\PDO::FETCH_ASSOC);
		return $row ? (int) $row['old_ndx'] : null;
	}

	public function listByType(string $entityType): array
	{
		$stmt = $this->pdo->prepare(
			'SELECT old_ndx, new_id, imported_at, last_updated FROM id_map'
			. ' WHERE entity_type = :t ORDER BY old_ndx'
		);
		$stmt->execute([':t' => $entityType]);
		$out = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r)
		{
			$out[] = [
				'old_ndx'      => (int) $r['old_ndx'],
				'new_id'       => (int) $r['new_id'],
				'imported_at'  => (string) $r['imported_at'],
				'last_updated' => (string) $r['last_updated'],
			];
		}
		return $out;
	}

	public function record(string $entityType, int $oldNdx, int $newId): void
	{
		$stmt = $this->pdo->prepare(
			'INSERT INTO id_map (entity_type, old_ndx, new_id) VALUES (:t, :o, :n)'
			. ' ON CONFLICT (entity_type, old_ndx) DO UPDATE SET new_id = excluded.new_id, last_updated = CURRENT_TIMESTAMP'
		);
		$stmt->execute([':t' => $entityType, ':o' => $oldNdx, ':n' => $newId]);
	}

	public function forget(string $entityType, int $oldNdx): void
	{
		$stmt = $this->pdo->prepare('DELETE FROM id_map WHERE entity_type = :t AND old_ndx = :o');
		$stmt->execute([':t' => $entityType, ':o' => $oldNdx]);
	}

	public function forgetAll(string $entityType): void
	{
		$stmt = $this->pdo->prepare('DELETE FROM id_map WHERE entity_type = :t');
		$stmt->execute([':t' => $entityType]);
	}

	public function stats(): array
	{
		$stmt = $this->pdo->query(
			'SELECT entity_type, COUNT(*) AS c FROM id_map GROUP BY entity_type ORDER BY entity_type'
		);
		$out = [];
		foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r)
			$out[(string) $r['entity_type']] = (int) $r['c'];
		return $out;
	}

	public function path(): string { return $this->sqliteFilePath; }
}
