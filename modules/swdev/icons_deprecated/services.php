<?php

namespace swdev\icons;


use E10\utils;


/**
 * Class ModuleServices
 * @package swdev\icons
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	function importIcons()
	{
		$tt = new \swdev\icons\libs\ImportFA47($this->app);
		$tt->import();

		$tt = new \swdev\icons\libs\ImportFA5($this->app);
		$tt->import();

		$tt = new \swdev\icons\libs\ImportFA5Brands($this->app);
		$tt->import();

		$tt = new \swdev\icons\libs\ImportWorldFlags($this->app);
		$tt->import();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'import-icons': return $this->importIcons();
		}

		parent::onCliAction($actionId);
	}
}
