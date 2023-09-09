<?php

namespace lib\hosting\services;
use e10\Utility, e10\json;


/**
 * Class GetHostingInfo
 * @package lib\hosting\services
 */
class GetHostingInfo extends Utility
{
	var $data = [];

	public function run()
	{
		$this->loadSystemCertificates();
	}

	function loadSystemCertificates()
	{
		$sc = ['all.shipard.app', 'all.shipard.pro', 'all.shipard.cz', 'all.shipard.com', 'all.shipard.online'];

		$e = new \lib\hosting\services\MasterCertificatesManager($this);
		$this->data['certificates'] = $e->loadCertificates($sc);
	}
}

