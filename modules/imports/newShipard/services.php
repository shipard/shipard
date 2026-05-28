<?php

namespace imports\newShipard;

class ModuleServices extends \Shipard\CLI\ModuleServices
{
	public function onCliAction($actionId)
	{
		if ($actionId !== 'import')
			return parent::onCliAction($actionId);

		$importApp = new \imports\newShipard\libs\ImportApp($this->app());
		return $importApp->run();
	}
}
