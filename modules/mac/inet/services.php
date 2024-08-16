<?php

namespace mac\inet;


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function domains()
	{
		$engine = new \mac\inet\libs\DomainsApiEngine($this->app);
		$engine->run();

		return TRUE;
	}

	function masterCertsScan()
	{
		$e = new \lib\hosting\services\MasterCertificatesManager($this->app);
		$e->scan();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'master-certs-scan': return $this->masterCertsScan();
			case 'domains': return $this->domains();
		}

		parent::onCliAction($actionId);
	}
}
