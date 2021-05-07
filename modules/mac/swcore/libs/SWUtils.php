<?php

namespace mac\swcore\libs;


use e10\Utility, \e10\utils, \e10\json;
use function E10\searchArray;


/**
 * Class SWUtils
 * @package mac\swcore\libs
 */
class SWUtils extends Utility
{
	CONST swcUnknown = 0, swcOS = 1, swcOSExtensions = 10, swcSharedLibs = 11;

	CONST lcUnknown = 0, lcActive = 1, lcObsolete = 2, lcEnded = 4, lcPreliminary = 5;
	CONST osfWindows = 0, osfMacOS = 1, osfLinux = 2, osfMikrotik = 3, osfEdgeCore = 4, osfSynology = 5,
				osfOther = 32001, osfError = 32002;

	var $lifeCycle = NULL;

	public function osFamily($osId)
	{
		$osFamilyList = $this->app()->cfgItem('mac.swcore.osFamily', NULL);

		foreach ($osFamilyList as $osfNdx => $osfCfg)
		{
			if ($osfCfg['id'] === $osId)
				return $osfNdx;
		}
		return self::osfError;
	}

	public function osFamilyCfg($osId)
	{
		$osFamilyList = $this->app()->cfgItem('mac.swcore.osFamily', NULL);

		foreach ($osFamilyList as $osfNdx => $osfCfg)
		{
			if ($osfCfg['id'] === $osId)
				return $osfCfg;
		}
		return NULL;
	}

	public function lcLabel($lcNdx, &$dst)
	{
		if (!$this->lifeCycle)
			$this->lifeCycle = $this->app()->cfgItem ('mac.swcore.lifeCycle');

		if (!isset($this->lifeCycle[$lcNdx]))
			return;
		$lc = $this->lifeCycle[$lcNdx];

		$label = ['text' => $lc['fn'], 'icon' => $lc['icon'], 'class' => 'label label-default'];
		$dst[] = $label;
	}
}
