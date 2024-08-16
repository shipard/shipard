<?php

namespace mac\inet\libs;

use e10\Utility;


/**
 * Class DomainsApiEngine
 */
class DomainsApiEngine extends Utility
{
	/** @var \mac\inet\TableDomains */
	var $tableDomains;
	/** @var \mac\inet\TableDomainsRecords */
	var $tableDomainsRecords;
	/** @var \mac\inet\TableDomainsAccounts */
	var $tableDomainsAccounts;
	/** @var \lib\integration\domainRegistrars\Client */
	var $apiClient = NULL;

	var $params = [];
	var $domainRecData = NULL;
	var $accountRegistrar = NULL;
	var $accountDNS = NULL;
	var $accountNdx = 0;
	var $account = NULL;

	var $domainRecordTypes;
	var $lastCheckId = 0;

	public function setAccountNdx ($accountNdx)
	{
		$this->accountNdx = $accountNdx;
	}

	public function checkParams()
	{
		$this->tableDomains = $this->app->table ('mac.inet.domains');
		$this->tableDomainsRecords = $this->app->table ('mac.inet.domainsRecords');
		$this->tableDomainsAccounts = $this->app->table ('mac.inet.domainsAccounts');

		$cmd = $this->app()->arg('cmd');
		if (!$cmd)
			return $this->err('Missing `--cmd` param');

		$this->params['cmd'] = $cmd;

		$domain = $this->app()->arg('domain');
		//if (!$domain)
		//	return $this->err('Missing `--domain` param');

		if ($domain !== FALSE)
		{
			$parts = explode('.', $domain);
			while(count($parts) > 2)
				array_shift($parts);
			$domain = implode('.', $parts);
		}

		$this->params['domain'] = $domain;

		$account = intval($this->app()->arg('account'));
		if ($account)
			$this->params['account'] = $account;

		$recordType = $this->app()->arg('record-type');
		if ($recordType)
			$this->params['recordType'] = $recordType;

		$recordHostName = $this->app()->arg('record-host-name');
		if ($recordHostName)
			$this->params['recordHostName'] = $recordHostName;

		$recordValue = $this->app()->arg('record-value');
		if ($recordValue)
			$this->params['recordValue'] = $recordValue;

		return TRUE;
	}

	function loadDomainDbInfo()
	{
		if (!isset($this->params['domain']) || $this->params['domain'] === '' || !$this->params['domain'])
			return FALSE;

		$this->domainRecData = $this->tableDomains->loadRecData('@domainAscii:'.$this->params['domain']);
		if (!$this->domainRecData)
			return $this->err("Domain `{$this->params['domain']}` not found");

		$this->accountRegistrar = $this->tableDomainsAccounts->loadItem($this->domainRecData['domainAccount']);
		if (!$this->accountRegistrar)
			return $this->err("Invalid/missing registrar for domain `{$this->params['domain']}`");

		$this->debug("account registrar is `{$this->accountRegistrar['name']}`");

		$this->accountDNS = $this->tableDomainsAccounts->loadItem($this->domainRecData['domainAccountDNS']);
		if (!$this->accountDNS)
			$this->accountDNS = $this->accountRegistrar;

		$this->debug("account DNS is `{$this->accountDNS['name']}`");
	}

	function loadAccountDbInfo()
	{
		$accountNdx = 0;

		if (isset($this->params['account']))
			$accountNdx = $this->params['account'];
		elseif ($this->accountDNS)
			$accountNdx = $this->accountDNS['ndx'];

		if (!$accountNdx)
			return $this->err('Missing account (--account=xxx)');

		$this->account = $this->tableDomainsAccounts->loadItem($accountNdx);
		if (!$this->account)
			return $this->err("Invalid account `{$accountNdx}`");

		$this->accountNdx = $this->account['ndx'];

		if ($this->app()->debug)
			$this->debug("account: #".$this->account['ndx']." / ".$this->account['name']);

		return TRUE;
	}

	public function login()
	{
		if (!$this->accountNdx)
			return FALSE;

		$this->apiClient = $this->tableDomainsAccounts->apiClient ($this->accountNdx);

		if (!$this->apiClient)
		{
			return $this->err("ERROR: Invalid apiClient");
		}

		$this->apiClient->init();
		$this->apiClient->login();

		return TRUE;
	}

	public function logout()
	{
		$this->apiClient->logout();
	}

	public function importDomains()
	{
		$domainsList = $this->apiClient->domainsList();

		if (!$domainsList)
			return FALSE;

		foreach ($domainsList as $domain)
		{
			$this->importDomain($domain);
		}

		return TRUE;

		//echo "IMPORTED DOMAINS: ".json_encode($domainsList)."\n";
	}

	public function importDomain($domain)
	{
		$exist = $this->db()->query('SELECT * FROM [mac_inet_domains] WHERE [domainAscii] = %s', $domain['domain'])->fetch();

		$newNdx = 0;
		if (!$exist)
		{ // add new
			$item = [
				'domainAscii' => $domain['domain'],
				'domain' => idn_to_utf8($domain['domain']),
				'domainAccount' => $this->accountNdx,
				'dateExpire' => $domain['dateExpire'],
				'docState' => 4000, 'docStateMain' => 2
			];

			$newNdx = $this->tableDomains->dbInsertRec ($item);
			$this->tableDomains->docsLog ($newNdx);
		}
		else
		{ // update existing?
			$newNdx = $exist['ndx'];
			if ($exist['domainAccount'] === $this->accountNdx)
			{
				$update = $exist->toArray();
				if ($update['dateExpire'] != $domain['dateExpire'])
					$update['dateExpire'] = $domain['dateExpire'];
				$update['checkId'] = 0;

				if (count($update))
				{
					$newNdx = $this->tableDomains->dbUpdateRec($update);
					$this->tableDomains->docsLog($newNdx);
				}
			}
		}

		// --- dns records
		//echo " --->`{$this->accountNdx}` ".json_encode($exist->toArray())."\n";
		if ($exist['domainAccountDNS'] == $this->accountNdx)
		{
			$this->importDomainDnsRecords ($newNdx);
		}
		else
		{
			//echo "     !!!: {$exist['domainAccountDNS']} != {$this->accountNdx}; ".json_encode($exist->toArray())."\n";
		}
	}

	public function importDomainDnsRecords ($domainNdx)
	{
		$this->db()->query('DELETE FROM [mac_inet_domainsRecords] WHERE [domain] = %i', $domainNdx);

		$domainRecData = $this->tableDomains->loadItem($domainNdx);
		if (!$domainRecData)
		{
			//echo "     ---- ##### -----`{$domainNdx}`\n";
			return;
		}

		$dnsRecords = $this->apiClient->dnsRecords($domainRecData['domainAscii']);
		if ($this->app()->debug)
			echo " --> dnsRecords: ".json_encode($dnsRecords)."\n";
		if ($dnsRecords)
		{
			foreach ($dnsRecords as $dnsRecord)
			{
				if ($this->app()->debug)
					echo " ---> ".json_encode($dnsRecord)."\n";
				$this->importDnsRecord($domainNdx, $dnsRecord);
			}
		}
	}

	public function importDnsRecord($domainNdx, $dnsRecord)
	{
		$exist = NULL;

		if (isset($dnsRecord['registrarId']))
			$exist = $this->db()->query('SELECT * FROM [mac_inet_domainsRecords] WHERE [registrarId] = %i', $dnsRecord['registrarId'])->fetch();

		$recordType = $this->domainRecordTypes[$dnsRecord['recordType']] ?? [];

		$newNdx = 0;
		if (!$exist)
		{ // add new
			$item = [
				'domain' => $domainNdx,
				'recordType' => $dnsRecord['recordType'],
				'hostName' => $dnsRecord['hostName'],
				'priority' => $dnsRecord['priority'] ?? 0,
				'ttl' => $dnsRecord['ttl'],
				'registrarId' => $dnsRecord['registrarId'] ?? 0,
				'displayOrder' => $recordType['displayOrder'] ?? 1,
				'docState' => 4000, 'docStateMain' => 2
			];

			if (strlen($dnsRecord['value']) <= 250)
				$item['value'] = $dnsRecord['value'];
			else
				$item['valueMemo'] = $dnsRecord['value'];

			$id = $item['hostName'].'-'.($item['value'] ?? $item['valueMemo'] ?? '').'-'.$item['priority'].'-'.$item['ttl'];
			$item['versionProvider'] = sha1($id);

			$newNdx = $this->tableDomainsRecords->dbInsertRec ($item);
			//$this->tableDomainsRecords->docsLog ($newNdx);
		}
		else
		{ // update existing
			$update = $exist->toArray();
			foreach ($dnsRecord as $key => $value)
				$update[$key] = $dnsRecord[$key];

			$id = $update['hostName'].'-'.$update['value'].'-'.$update['priority'].'-'.$update['ttl'];
			$update['versionProvider'] = sha1($id);

			if (count($update))
			{
				$newNdx = $this->tableDomainsRecords->dbUpdateRec($update);
				$this->tableDomainsRecords->docsLog($newNdx);
			}
		}
	}

	public function addDnsRecord ($domain, $dnsRec)
	{
		$newDomainRegistrarId = $this->apiClient->addDnsRecord($domain, $dnsRec);
		if ($newDomainRegistrarId)
		{
			$update = [
				'registrarId' => $newDomainRegistrarId,
				'versionData' => self::dnsRecVersionCheckSum($dnsRec),
				'versionProvider' => self::dnsRecVersionCheckSum($dnsRec)
			];
			$this->db()->query ('UPDATE [e10pro_hosting_server_domainsRecords] SET ', $update, ' WHERE [ndx] = %i', $dnsRec['ndx']);
			return TRUE;
		}

		return FALSE;
	}

	public function modifyDnsRecord ($domain, $dnsRec)
	{
		$res = $this->apiClient->modifyDnsRecord($domain, $dnsRec);
		if ($res)
		{
			$update = [
				'versionData' => self::dnsRecVersionCheckSum($dnsRec),
				'versionProvider' => self::dnsRecVersionCheckSum($dnsRec)
			];
			$this->db()->query ('UPDATE [e10pro_hosting_server_domainsRecords] SET ', $update, ' WHERE [ndx] = %i', $dnsRec['ndx']);
		}
		return $res;
	}

	static function dnsRecVersionCheckSum ($dnsRec)
	{
		$id = $dnsRec['hostName'] . '-' . $dnsRec['value'] . '-' . $dnsRec['priority'] . '-' . $dnsRec['ttl'];
		return sha1($id);
	}

	function doTest()
	{
		return FALSE;
	}

	public function doDomainsList()
	{
		$this->db()->query('UPDATE [mac_inet_domains] SET checkId = %i, ', $this->lastCheckId,
											 ' lastCheck = %t', new \DateTime(),
											 ' WHERE [domainAccount] = %i', $this->accountNdx);

		$domainsList = $this->apiClient->domainsList();

		if (!$domainsList)
			return FALSE;

		foreach ($domainsList as $domain)
		{
			if ($this->app()->debug)
				echo '* '.json_encode($domain)."\n";
			$this->importDomain($domain);
		}

		$this->db()->query('UPDATE [mac_inet_domains] SET notFound = %i', 1, ' WHERE [domainAccount] = %i', $this->accountNdx, ' AND checkId = %i', $this->lastCheckId);

		return TRUE;
	}

	public function doDomainRecords()
	{
		$dnsRecords = $this->apiClient->dnsRecords($this->domainRecData['domainAscii']);
		foreach ($dnsRecords as $dnsRecord)
		{
			echo '* '.json_encode($dnsRecord)."\n";
		}
	}

	public function doDomainRecordAdd()
	{
		$dnsRecord = [
			'recordType' => $this->params['recordType'],
			'value' => $this->params['recordValue'],
			'hostName' => $this->params['recordHostName'],
		];

		//echo json_encode($dnsRecord)."\n";
		$response = $this->apiClient->addDnsRecord($this->domainRecData['domainAscii'], $dnsRecord);

		echo json_encode($response)."\n";
	}

	public function doDomainRecordDelete()
	{
		$dnsRecord = [
			'recordType' => $this->params['recordType'],
			'value' => $this->params['recordValue'],
			'hostName' => $this->params['recordHostName'],
		];

		//echo json_encode($dnsRecord)."\n";
		$response = $this->apiClient->deleteDnsRecord($this->domainRecData['domainAscii'], $dnsRecord);

		echo json_encode($response)."\n";
	}

	function doCmd()
	{
		if ($this->errors)
			return FALSE;

		switch($this->params['cmd'])
		{
			case 'test': return $this->doTest();
			case 'domains-list': return $this->doDomainsList();
			case 'domain-records': return $this->doDomainRecords();
			case 'domain-record-add': return $this->doDomainRecordAdd();
			case 'domain-record-delete': return $this->doDomainRecordDelete();
		}

		return FALSE;
	}

	public function run()
	{
		$this->lastCheckId = time() - 1723800000;
		$this->domainRecordTypes = $this->app()->cfgItem('mac.inet.domainsRecordTypes');

		if (!$this->checkParams())
			return;
		$this->loadDomainDbInfo();
		if (!$this->loadAccountDbInfo())
			return;
		if (!$this->login())
			return;
		$this->doCmd();
		$this->logout();
	}
}
