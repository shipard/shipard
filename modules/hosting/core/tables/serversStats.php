<?php

namespace E10pro\Hosting\Server;

use \e10\DbTable, \e10\utils;

/**
 * Class TableServersStats
 * @package E10pro\Hosting\Server
 */
class TableServersStats extends DbTable
{
	public function __construct($dbmodel)
	{
		parent::__construct($dbmodel);
		$this->setName('e10pro.hosting.server.serversStats', 'e10pro_hosting_server_serversStats', 'Statistiky serverů');
	}

	public function downloadStats ()
	{
		$moncApiKey = $this->app()->cfgItem('moncApiKey', FALSE);
		if (!$moncApiKey)
			return;
		$data = utils::loadApiFile('https://monc.shipard.com'.'/api/objects/call/monc-hosting-serverstats', $moncApiKey);
		if (!$data)
			return;

		foreach ($data['stats'] as $s)
		{
			$server = $this->db()->query ('SELECT * FROM e10pro_hosting_server_servers WHERE [gid] = %s', strval($s['gid']))->fetch();
			if (!$server)
				continue;

			$i = [
					'dateUpdate' => $s['updated'],
					'diskTotalSpace' => $s['totalSpace'], 'diskFreeSpace' => $s['freeSpace'],
					'diskUsedSpace' => $s['totalSpace'] - $s['freeSpace']
			];

			$existed = $this->db()->query ('SELECT * FROM e10pro_hosting_server_serversStats WHERE [server] = %i', $server['ndx'])->fetch();
			if ($existed)
			{
				$this->db()->query ('UPDATE e10pro_hosting_server_serversStats SET ', $i, ' WHERE ndx = %i', $existed['ndx']);
			}
			else
			{
				$i['server'] = $server['ndx'];
				$this->db()->query ('INSERT INTO e10pro_hosting_server_serversStats ', $i);
			}
		}
	}
}
