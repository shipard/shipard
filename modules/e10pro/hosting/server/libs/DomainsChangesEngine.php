<?php

namespace e10pro\hosting\server\libs;

use e10\Utility;


/**
 * Class DomainsApiEngine
 * @package e10pro\hosting\server
 */
class DomainsChangesEngine extends Utility
{
	/** @var \e10pro\hosting\server\TableDomains */
	var $tableDomains;
	/** @var \e10pro\hosting\server\TableDomainsRecords */
	var $tableDomainsRecords;
	/** @var \e10pro\hosting\server\TableDomainsAccounts */
	var $tableDomainsAccounts;

	var $changes = NULL;
	var $changesTable = NULL;
	var $changesHeader = NULL;

	public function init()
	{
		$this->tableDomains = $this->app->table ('e10pro.hosting.server.domains');
		$this->tableDomainsRecords = $this->app->table ('e10pro.hosting.server.domainsRecords');
		$this->tableDomainsAccounts = $this->app->table ('e10pro.hosting.server.domainsAccounts');
	}

	public function loadChanges()
	{
		$this->changes = [];
		$this->changesTable = [];
		$this->changesHeader = ['#' => '#', 'domain' => 'Doména', 'type' => 'Typ', 'record' => 'Záznam', 'info' => 'Hodnota'];

		$q[] = 'SELECT records.*, [domains].domainAccount FROM [e10pro_hosting_server_domainsRecords] AS [records]';
		array_push ($q, ' LEFT JOIN [e10pro_hosting_server_domains] AS [domains] ON records.[domain] = [domains].ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND records.[docState] = %i', 4000);
		array_push ($q, ' AND domains.[docState] = %i', 4000);
		array_push ($q, ' AND (records.[versionProvider] != records.[versionData] OR records.registrarId = 0)');
		array_push ($q, ' ORDER BY domains.domainAccount, domains.[domain], records.recordType, records.hostName');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$domainAccountNdx = $r['domainAccount'];
			$domainNdx = $r['domain'];
			$recordNdx = $r['ndx'];
			if (!isset($this->changes[$domainAccountNdx]))
			{
				$this->changes[$domainAccountNdx] = [
					'account' => $this->tableDomainsAccounts->loadItem($domainAccountNdx),
					'domains' => [],
				];

				$ti = [
					'domain' => $this->changes[$domainAccountNdx]['account']['name'],
					'_options' => ['class' => 'subheader', 'beforeSeparator' => 'separator', 'colSpan' => ['domain' => 3]]
					];
				//$this->changesTable[] = $ti;
			}

			if (!isset($this->changes[$domainAccountNdx]['domains'][$domainNdx]))
			{
				$this->changes[$domainAccountNdx]['domains'][$domainNdx] = [
					'domain' => $this->tableDomains->loadItem($domainNdx),
					'records' => [],
				];
			}

			$this->changes[$domainAccountNdx]['domains'][$domainNdx]['records'][$recordNdx] = $r->toArray();

			$ti = [
				'domain' => $this->changes[$domainAccountNdx]['domains'][$domainNdx]['domain']['domain'],
				'type' => $r['recordType'],
				'record' => $r['hostName'],
				'info' => $r['value'],
			];
			if (!$r['registrarId'])
				$ti['_options']['class'] = 'e10-row-info';
			else
				$ti['_options']['class'] = 'e10-row-minus';

			$this->changesTable[] = $ti;
		}
	}

	public function sendChanges()
	{
		foreach ($this->changes as $accountNdx => $account)
		{
			$engine = new \e10pro\hosting\server\DomainsApiEngine($this->app());
			$engine->setAccountNdx($accountNdx);

			if (!$engine->login())
			{
				error_log ("Login to domain account #{$accountNdx} failed...");
				continue;
			}

			foreach ($account['domains'] as $domainNdx => $domain)
			{
				foreach ($domain['records'] as $recordNdx => $record)
				{
					if ($record['registrarId'])
					{
						$engine->modifyDnsRecord($domain['domain'], $record);
					}
					else
					{
						$engine->addDnsRecord($domain['domain'], $record);
					}
				}
			}
		}
	}
}