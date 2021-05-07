<?php

namespace terminals\base;


use E10\Utility;

/**
 * Class LocalServerConfig
 * @package terminals\base
 */
class LocalServerConfig extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$serverNdx = intval($this->app->requestPath(4));
		$server = $this->db()->query ('SELECT * FROM [terminals_base_servers] WHERE ndx = %i', $serverNdx)->fetch();

		if ($server)
		{
			$this->result ['cfg'] = ['ver' => $server['cfgDataVer'],'cfg' => json_decode($server['cfgData'], TRUE)];
			$this->result ['success'] = 1;
		}
	}
}
