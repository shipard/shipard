<?php

namespace swdev\translation;


use E10\utils;


/**
 * Class ModuleServices
 * @package swdev\translation
 */
class ModuleServices extends \E10\CLI\ModuleServices
{
	public function onAppUpgrade ()
	{
		$s [] = ['end' => '2019-12-31', 'sql' => "UPDATE swdev_dm_columns SET docState = 4000, docStateMain = 2 WHERE docState = 0"];

		$this->doSqlScripts ($s);
	}

	function dmTrData()
	{
		$tt = new \swdev\dm\libs\TranslationTable($this->app);
		$tt->updateTableTrData();
		$tt->updateDictsTrData();
		$tt->updateEnumsTrData();
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'dm-tr-data': return $this->dmTrData();
		}

		parent::onCliAction($actionId);
	}
}
