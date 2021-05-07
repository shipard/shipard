<?php

namespace lib\integration\domainRegistrars\subreg_cz;
use e10\Utility;


/**
 * Class Client
 * @package lib\integration\domainRegistrars\subreg_cz
 */
class Client extends \lib\integration\domainRegistrars\Client
{
	var $serverInfo = [
		'location' => 'https://soap.subreg.cz/cmd.php',
		'uri' => 'http://soap.subreg.cz/',
		];

	var $client;

	public function init()
	{
		$this->client = new \SoapClient(null, $this->serverInfo);
	}

	public function login()
	{
		$params = [
			"data" => [
				"login" => $this->auth['login'],
				"password" => $this->auth['password'],
			]
		];

		$response = $this->client->__call("Login",$params);

		$this->auth['token'] = $response["data"]["ssid"];
	}

	public function domainsList ()
	{
		$params = [
			'data' => [
				'ssid' => $this->auth['token'],
			]
		];

		$response = $this->client->__call('Domains_List', $params);

		if (!$response || !isset($response['status']) || !$response['status'])
		{
			print_r ($response);
			return NULL;
		}
		if (!isset($response['data']['domains']))
		{
			print_r ($response);
			return NULL;
		}

		$list = [];
		foreach ($response['data']['domains'] as $d)
		{
			$item = ['domain' => $d['name'], 'dateExpire' => $d['expire'], 'autorenew' => $d['autorenew']];
			$list[] = $item;
		}

		//echo json_encode($list)."\n";

		return $list;
	}

	public function dnsRecords ($domainAsciiName)
	{
		$params = [
			'data' => [
				'ssid' => $this->auth['token'],
				'domain' => $domainAsciiName,
			]
		];

		$response = $this->client->__call('Get_DNS_Zone',$params);

		if (!$response || !isset($response['status']) || !$response['status'])
		{
			print_r ($response);
			return NULL;
		}
		if (!isset($response['data']['records']))
		{
			print_r ($response);
			return NULL;
		}

		$list = [];
		foreach ($response['data']['records'] as $d)
		{
			$item = [
				'recordType' => $d['type'],
				'hostName' => $d['name'],
				'value' => $d['content'],
				'priority' => $d['prio'],
				'ttl' => $d['ttl'],
				'registrarId' => $d['id']
			];
			$list[] = $item;
		}

		//echo json_encode($list)."\n";

		return $list;
	}

	public function addDnsRecord ($domain, $dnsRec)
	{
		$params = [
			'data' => [
				'ssid' => $this->auth['token'],
				'domain' => $domain,
				'record' => [
					'name' => $dnsRec['hostName'],
					'type' => $dnsRec['recordType'],
					'content' => $dnsRec['value'],
					'prio' => isset($dnsRec['priority']) ? $dnsRec['priority'] : 0,
					'ttl' => isset($dnsRec['ttl']) ? $dnsRec['ttl'] : 900,
				]
			]
		];

		$response = $this->client->__call('Add_DNS_Record', $params);
		if (!$response || !isset($response['status']) || !$response['status'])
		{
			error_log ("Add_DNS_Record failed; ".json_encode($response));
			return 0;
		}

		if ($response['status'] !== 'ok')
		{
			error_log ("Add_DNS_Record status failed; ".json_encode($response));
			return 0;
		}

		if (!isset($response['data']['record_id']))
		{
			error_log ("Add_DNS_Record missing record_id; ".json_encode($response));
			return 0;
		}

		return intval($response['data']['record_id']);
	}

	public function deleteDnsRecord ($domain, $dnsRec)
	{
		$dnsRecords = $this->dnsRecords($domain);
		$registrarId = 0;

		foreach ($dnsRecords as $rec)
		{
			if ($rec['recordType'] !== $dnsRec['recordType'])
				continue;

			if ($rec['hostName'] !== $dnsRec['hostName'])
				continue;

			if ($rec['value'] !== $dnsRec['value'])
				continue;

			$registrarId = $rec['registrarId'];
		}

		if ($registrarId === 0)
		{
			return FALSE;
		}

		$params = [
			'data' => [
				'ssid' => $this->auth['token'],
				'domain' => $domain,
				'record' => [
					'id' => $registrarId,
				]
			]
		];

		$response = $this->client->__call('Delete_DNS_Record', $params);
		if (!$response || !isset($response['status']) || !$response['status'])
		{
			error_log ("Delete_DNS_Record failed; ".json_encode($response));
			return 0;
		}

		if ($response['status'] !== 'ok')
		{
			error_log ("Delete_DNS_Record status failed; ".json_encode($response));
			return 0;
		}

		return TRUE;
	}

	public function modifyDnsRecord ($domain, $dnsRec)
	{
		$params = [
			'data' => [
				'ssid' => $this->auth['token'],
				'domain' => $domain['domainAscii'],
				'record' => [
					'id' => $dnsRec['registrarId'],
					'name' => $dnsRec['hostName'],
					'type' => $dnsRec['recordType'],
					'content' => $dnsRec['value'],
					'prio' => $dnsRec['priority'],
					'ttl' => $dnsRec['ttl'],
				]
			]
		];

		$response = $this->client->__call('Modify_DNS_Record', $params);
		if (!$response || !isset($response['status']) || !$response['status'])
		{
			error_log ("Modify_DNS_Record failed; ".json_encode($response));
			return FALSE;
		}

		if ($response['status'] !== 'ok')
		{
			error_log ("Modify_DNS_Record status failed; ".json_encode($response));
			return FALSE;
		}

		return TRUE;
	}
}
