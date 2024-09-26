<?php

namespace e10pro\vendms;

/**
 * class ModuleServices
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function importISIC()
	{
		$fileParam = $this->app()->arg('file');
		if (!$fileParam)
		{
			echo "Missing `--file` param!\n";
			return FALSE;
		}

		$labelIticNdx = intval($this->app()->arg('labelIticNdx'));
		$labelIsicNdx = intval($this->app()->arg('labelIsicNdx'));


		$e = new \e10pro\vendms\libs\ImportISIC($this->app());
		$e->fileName = $fileParam;
		$e->personLabelIsicNdx = $labelIsicNdx;
		$e->personLabelIticNdx = $labelIticNdx;

		$e->run();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'import-isic': return $this->importISIC();
		}

		parent::onCliAction($actionId);
	}

}
