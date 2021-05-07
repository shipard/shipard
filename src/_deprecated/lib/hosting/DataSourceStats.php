<?php

namespace lib\hosting;
use e10\Utility, e10\json;


/**
 * Class MergePersons
 * @package lib\persons
 */
class DataSourceStats extends Utility
{
	var $cfgFileName;
	var $data = NULL;

	public function loadFromFile()
	{
		$this->cfgFileName = __APP_DIR__.'/config/dataSourceStats.json';

		if (is_file($this->cfgFileName))
		{
			$txt = file_get_contents($this->cfgFileName);
			if ($txt)
			{
				$this->data = json::decode($txt);
				if (!$this->data)
					$this->data = [];
			}
		}

		if (!$this->data)
			$this->data = [];
	}

	public function saveToFile()
	{
		$this->data['created'] = new \DateTime();

		json::polish($this->data);
		foreach ($this->data as &$v)
			if (is_array($v))
				json::polish($v);

		$txt = json::lint($this->data);
		file_put_contents($this->cfgFileName, $txt);
	}
}
