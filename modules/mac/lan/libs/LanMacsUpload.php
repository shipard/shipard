<?php

namespace mac\lan\libs;

use e10\Utility, e10\json;


/**
 * Class LanMacsUpload
 * @package mac\lan\libs
 */
class LanMacsUpload extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$data = json_decode($this->app()->postData(), TRUE);
		if (!$data)
			return;

		$this->db()->begin();

		// -- macs
		foreach ($data['macs'] as $mac => $onPorts)
		{
			$exist = $this->app()->db()->query('SELECT ndx FROM [mac_lan_macs] WHERE [mac] = %s', $mac)->fetch();
			if ($exist)
			{ // update
				$update = ['updated' => new \DateTime(), 'ports' => json::lint($onPorts)];
				$this->app()->db()->query('UPDATE [mac_lan_macs] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
			}
			else
			{ // insert
				$newItem = [
					'mac' => $mac, 'ports' => json::lint($onPorts),
					'created' => new \DateTime(), 'updated' => new \DateTime()
				];
				$this->app()->db()->query('INSERT INTO [mac_lan_macs] ', $newItem);
			}
		}

		// -- macs on ports
		foreach ($data['devices'] as $deviceNdx => $onPorts)
		{
			foreach ($onPorts as $portNumber => $macs)
			{
				$exist = $this->app()->db()->query('SELECT ndx FROM [mac_lan_macsOnPorts]',
					' WHERE [device] = %i', $deviceNdx, ' AND [portNumber] = %i', $portNumber)->fetch();
				if ($exist)
				{ // update
					$update = ['updated' => new \DateTime(), 'macs' => json::lint($macs)];
					$this->app()->db()->query('UPDATE [mac_lan_macsOnPorts] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
				}
				else
				{ // insert
					$newItem = [
						'device' => $deviceNdx, 'portNumber' => $portNumber, 'macs' => json::lint($macs),
						'created' => new \DateTime(), 'updated' => new \DateTime()
					];
					$this->app()->db()->query('INSERT INTO [mac_lan_macsOnPorts] ', $newItem);
				}
			}
		}

		$this->db()->commit();

		$this->result ['success'] = 1;
	}
}
