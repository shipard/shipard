<?php

namespace e10pro\hosting\server;

use e10\Utility;


/**
 * Class DomainsApiEngine
 * @package e10pro\hosting\server
 */
class DomainsApiEngine extends Utility
{
	/** @var \e10pro\hosting\server\TableDomains */
	var $tableDomains;
	/** @var \e10pro\hosting\server\TableDomainsRecords */
	var $tableDomainsRecords;
	/** @var \e10pro\hosting\server\TableDomainsAccounts */
	var $tableDomainsAccounts;
	/** @var \lib\integration\domainRegistrars\Client */
	var $apiClient = NULL;

	var $params = [];
	var $domainRecData = NULL;
	var $accountRegistrar = NULL;
	var $accountDNS = NULL;
	var $accountNdx = 0;
	var $account = NULL;

	public function setAccountNdx ($accountNdx)
	{
		$this->accountNdx = $accountNdx;
	}

	public function checkParams()
	{
		$this->tableDomains = $this->app->table ('e10pro.hosting.server.domains');
		$this->tableDomainsRecords = $this->app->table ('e10pro.hosting.server.domainsRecords');
		$this->tableDomainsAccounts = $this->app->table ('e10pro.hosting.server.domainsAccounts');

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
		if (!$this->params['domain'])
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
			return $this->err('Missing account');

		$this->account = $this->tableDomainsAccounts->loadItem($accountNdx);
		if (!$this->account)
			return $this->err("Invalid account `{$accountNdx}`");

		$this->accountNdx = $this->account['ndx'];

		$this->debug("account: #".$this->account['ndx']." / ".$this->account['name']);
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
		$exist = $this->db()->query('SELECT * FROM [e10pro_hosting_server_domains] WHERE [domainAscii] = %s', $domain['domain'])->fetch();

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
		{ // update existing
			$update = $exist->toArray();
			if ($update['dateExpire'] != $domain['dateExpire'])
				$update['dateExpire'] = $domain['dateExpire'];

			if (count($update))
			{
				$newNdx = $this->tableDomains->dbUpdateRec($update);
				$this->tableDomains->docsLog($newNdx);
			}
		}

		// --- dns records
		$this->importDomainDnsRecords ($newNdx);
	}

	public function importDomainDnsRecords ($domainNdx)
	{
		$domainRecData = $this->tableDomains->loadItem($domainNdx);
		//echo "  --> ".json_encode($domainRecData)."\n";
		if (!$domainRecData)
			return;

		$dnsRecords = $this->apiClient->dnsRecords($domainRecData['domainAscii']);
		foreach ($dnsRecords as $dnsRecord)
			$this->importDnsRecord($domainNdx, $dnsRecord);
	}

	public function importDnsRecord($domainNdx, $dnsRecord)
	{
		$exist = $this->db()->query('SELECT * FROM [e10pro_hosting_server_domainsRecords] WHERE [registrarId] = %i', $dnsRecord['registrarId'])->fetch();

		$newNdx = 0;
		if (!$exist)
		{ // add new
			$item = [
				'domain' => $domainNdx,
				'recordType' => $dnsRecord['recordType'],
				'hostName' => $dnsRecord['hostName'],
				'value' => $dnsRecord['value'],
				'priority' => $dnsRecord['priority'],
				'ttl' => $dnsRecord['ttl'],
				'registrarId' => $dnsRecord['registrarId'],
				'docState' => 4000, 'docStateMain' => 2
			];

			$id = $item['hostName'].'-'.$item['value'].'-'.$item['priority'].'-'.$item['ttl'];
			$item['versionProvider'] = sha1($id);

			$newNdx = $this->tableDomainsRecords->dbInsertRec ($item);
			$this->tableDomainsRecords->docsLog ($newNdx);
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
		$domainsList = $this->apiClient->domainsList();

		if (!$domainsList)
			return FALSE;

		foreach ($domainsList as $domain)
		{
		//	$this->importDomain($domain);
			echo '* '.json_encode($domain)."\n";
		}

		return TRUE;

		//echo "IMPORTED DOMAINS: ".json_encode($domainsList)."\n";
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
		$this->checkParams();
		$this->loadDomainDbInfo();
		$this->loadAccountDbInfo();
		$this->login();
		$this->doCmd();
		$this->logout();
	}
}
