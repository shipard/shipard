<?php

namespace mac\inet;


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function domainsImportFromAccount ()
	{
		/*
		$accountNdx = intval($this->app->arg('account'));
		if (!$accountNdx)
		{
			return FALSE;
		}

		$engine = new \e10pro\hosting\server\DomainsApiEngine($this->app);
		$engine->setAccountNdx($accountNdx);

		if (!$engine->login())
		{
			echo "!!! Login failed...\n";
			return FALSE;
		}

		$engine->importDomains();
		*/
		return TRUE;
	}

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
			//case 'domains-import-from-account': return $this->domainsImportFromAccount();
			case 'master-certs-scan': return $this->masterCertsScan();
			case 'domains': return $this->domains();
		}

		parent::onCliAction($actionId);
	}
}
