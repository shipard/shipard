<?php

namespace services\money\libs\download;

use e10\utils;


/**
 * Class Download_CZ_CNB_OC
 * @package services\money\libs\download
 */
class Download_CZ_CNB_OC extends \services\money\libs\download\Download_CZ_CNB
{
	public function init()
	{
		$this->listTypeId = 2;

		parent::init();
	}

	public function downloadDay($date)
	{
		$day = intval($date->format ('d'));
		if ($day !== 1)
		{
			return 1;
		}

		$this->dateValidFrom = new \DateTime($date->format('Y-m-01'));
		$this->dateValidTo = new \DateTime($date->format('Y-m-t'));

		$this->doneFileName = __APP_DIR__.'/tmp/exr-download-'.$this->listTypeId.'-'.$date->format('Ymd').'.done';
		if (is_readable($this->doneFileName))
			return 1;

		$dayAgo = clone $date;
		$dayAgo->sub (new \DateInterval('P1D'));
		$url = 'https://www.cnb.cz/cs/financni_trhy/devizovy_trh/kurzy_ostatnich_men/kurzy.txt?mesic='.intval($dayAgo->format('m')).'&rok='.$dayAgo->format('Y');
		$txtData = file_get_contents($url);
		if ($txtData === FALSE)
		{
			return 2;
		}

		$this->setTxtData($txtData);

		$this->parseData();
		if ($this->saveList)
			$this->save();

		return 0;
	}
}
