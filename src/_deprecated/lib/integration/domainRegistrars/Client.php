<?php

namespace lib\integration\domainRegistrars;
use e10\Utility;


/**
 * Class Client
 * @package lib\integration\domainRegistrars
 */
class Client extends Utility
{
	var $auth = [];

	public function init()
	{

	}

	public function login()
	{
	}

	public function logout()
	{
	}

	public function domainsList ()
	{
		return NULL;
	}

	public function dnsRecords ($domainAsciiName)
	{
		return NULL;
	}

	public function addDnsRecord ($domain, $dnsRec)
	{
		return 0;
	}

	public function deleteDnsRecord ($domain, $dnsRec)
	{
		return 0;
	}

	public function modifyDnsRecord ($domain, $dnsRec)
	{
		return FALSE;
	}
}
