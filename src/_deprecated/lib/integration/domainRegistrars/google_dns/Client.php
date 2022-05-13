<?php

namespace lib\integration\domainRegistrars\google_dns;
use e10\Utility, e10\utils, \e10\json;


/**
 * Class Client
 * @package lib\integration\domainRegistrars\google_dns
 */
class Client extends \lib\integration\domainRegistrars\Client
{
	var $authKeyFileName = '';

	/** @var \Google_Client */
	var $client = NULL;

	/** @var \Google_Service_Dns */
	var $service = NULL;

	protected function saveAuthKey()
	{
		$this->authKeyFileName = utils::tmpFileName('dta', utils::createToken(16));
		file_put_contents($this->authKeyFileName, $this->auth['integrationService']['authKey']);
	}

	protected function deleteAuthKey()
	{
		if ($this->authKeyFileName !== '' && is_readable($this->authKeyFileName))
			unlink($this->authKeyFileName);
	}

	public function init()
	{
		$this->saveAuthKey();
		putenv('GOOGLE_APPLICATION_CREDENTIALS='.$this->authKeyFileName);
	}

	public function login()
	{
		try
		{
			$this->client = new \Google_Client();
			$this->client->useApplicationDefaultCredentials();
			$this->client->addScope(\Google_Service_Dns::NDEV_CLOUDDNS_READWRITE);

			$this->service = new \Google_Service_Dns($this->client);
		}
		catch (\Exception $e)
		{
			echo "ERROR: ".$e->getMessage()."\n";
			$this->deleteAuthKey();
			return;
		}
	}

	public function logout()
	{
		$this->deleteAuthKey();
	}

	protected function zoneId($domain)
	{
		$zid = str_replace('.', '-', $domain);
		if (is_numeric(substr($zid, 0, 1)))
			$zid = 'zone-'.$zid;

		return $zid;	
	}

	public function domainsList ()
	{
		$list = [];
		$optParams = [];

		do
		{
			$response = $this->service->managedZones->listManagedZones($this->auth['projectId'], $optParams);
			foreach ($response['managedZones'] as $managedZone)
			{
				$item = [
					'domain' => substr($managedZone['dnsName'], 0, -1),
					'dateExpire' => NULL,
					'autorenew' => 0
				];
				$list[] = $item;

				//echo "* ".json::lint($managedZone)."\n";
			}

			$optParams['pageToken'] = $response->getNextPageToken();
		} while ($optParams['pageToken']);

		return $list;
	}

	public function dnsRecords ($domainAsciiName)
	{
		$list = [];
		$managedZone = $this->zoneId($domainAsciiName);

		$optParams = [];

		do
		{
			$response = $this->service->resourceRecordSets->listResourceRecordSets($this->auth['projectId'], $managedZone, $optParams);

			foreach ($response['rrsets'] as $resourceRecordSet)
			{
				foreach ($resourceRecordSet['rrdatas'] as $value)
				{
					$item = [
						'recordType' => $resourceRecordSet['type'],
						'ttl' => $resourceRecordSet['ttl'],
					];

					//$item['hostName'] = '';
					$item['hostName'] = substr($resourceRecordSet['name'], 0, -(strlen($domainAsciiName) + 1));
					if (substr($item['hostName'], -1) === '.')
						$item['hostName'] = substr($item['hostName'], 0, -1);

					if ($resourceRecordSet['type'] === 'CNAME')
					{
						$item['value'] = substr($value, 0, -1);
					}
					elseif ($resourceRecordSet['type'] === 'MX')
					{
						$parts = explode(' ', $value);
						$item['priority'] = intval($parts[0]);
						$item['value'] = substr($parts[1], 0, -1);
					}
					else
						$item['value'] = $value;

					$list[] = $item;
					// echo json::lint($resourceRecordSet) . "\n";
				}
			}

			$optParams['pageToken'] = $response->getNextPageToken();
		} while ($optParams['pageToken']);

		return $list;
	}

	public function addDnsRecord ($domain, $dnsRec)
	{
		$managedZone = $this->zoneId($domain);

		$change = new \Google_Service_Dns_Change();

		$op = new \Google_Service_Dns_ResourceRecordSet();
		$op->setType($dnsRec['recordType']);
		if ($dnsRec['hostName'] === '')
			$op->setName($domain.'.');
		else
		{
			if (strchr($dnsRec['hostName'], $domain) === FALSE)
				$op->setName($dnsRec['hostName'] . '.' . $domain . '.');
			else
				$op->setName($dnsRec['hostName']);
		}
		$op->setRrdatas([$dnsRec['value']]);

		$op->setTtl(isset($dnsRec['ttl']) ? $dnsRec['ttl'] : 300);

		$change->setAdditions([$op]);

		$response = $this->service->changes->create($this->auth['projectId'], $managedZone, $change);

		return $response;
	}

	public function deleteDnsRecord ($domain, $dnsRec)
	{
		$managedZone = $this->zoneId($domain);

		$change = new \Google_Service_Dns_Change();

		$op = new \Google_Service_Dns_ResourceRecordSet();
		$op->setType($dnsRec['recordType']);
		if ($dnsRec['hostName'] === '')
			$op->setName($domain.'.');
		else
		{
			if (strchr($dnsRec['hostName'], $domain) === FALSE)
				$op->setName($dnsRec['hostName'] . '.' . $domain . '.');
			else
				$op->setName($dnsRec['hostName']);
		}
		$op->setTtl(isset($dnsRec['ttl']) ? $dnsRec['ttl'] : 300);

		if ($dnsRec['recordType'] === 'TXT')
			$op->setRrdatas(['"'.$dnsRec['value'].'"']);
		else
			$op->setRrdatas([$dnsRec['value']]);

		$change->setDeletions([$op]);

		$response = $this->service->changes->create($this->auth['projectId'], $managedZone, $change);

		return $response;
	}


	public function modifyDnsRecord ($domain, $dnsRec)
	{
	}
}
