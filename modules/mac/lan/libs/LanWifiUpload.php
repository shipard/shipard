<?php

namespace mac\lan\libs;

use e10\Utility, e10\json;
use \Shipard\Utils\Str;


/**
 * class LanWifiUpload
 */
class LanWifiUpload extends Utility
{
	public $result = ['success' => 0];

	public function run ()
	{
		$data = json_decode($this->app()->postData(), TRUE);
		if (!$data || !isset($data['clients']))
			return;


    $cmsDeviceNdx = intval($data['cmsDevice'] ?? 0);
    $activeMacsNdxs = [];

		$this->db()->begin();

		// -- clients
		foreach ($data['clients'] as $client)
		{
      $mac = $client['mac'];
      if ($mac === '')
        continue;

			$exist = $this->app()->db()->query('SELECT ndx FROM [mac_lan_wifiClients] WHERE [mac] = %s', $mac)->fetch();
			if ($exist)
			{ // update
				$update = [
          'cmsDevice' => $cmsDeviceNdx,
          'hostName' => Str::upToLen($client['hostName'] ?? '', 120),
          'rssi' => intval($client['rssi'] ?? 0),
          'ssid' => Str::upToLen($client['ssid'] ?? '', 60),
          'apId' => Str::upToLen($client['apId'] ?? '', 60),
          'cch' => Str::upToLen($client['cch'] ?? '', 80),
          'txRate' => Str::upToLen($client['txRate'] ?? '', 80),
          'rxRate' => Str::upToLen($client['rxRate'] ?? '', 80),
          'inactive' => 0,
          'txBytes' => $client['txBytes'] ?? 0,
          'rxBytes' => $client['rxBytes'] ?? 0,
          'uptime' => $client['uptime'] ?? 0,
          'aip' => Str::upToLen($client['aip'] ?? '', 16),
          'updated' => new \DateTime(),
        ];
				$this->app()->db()->query('UPDATE [mac_lan_wifiClients] SET ', $update, ' WHERE [ndx] = %i', $exist['ndx']);
        $activeMacsNdxs[] = $exist['ndx'];
			}
			else
			{ // insert
				$newItem = [
					'mac' => $mac,
          'cmsDevice' => $cmsDeviceNdx,
          'hostName' => Str::upToLen($client['hostName'] ?? '', 120),
          'rssi' => intval($client['rssi'] ?? 0),
          'ssid' => Str::upToLen($client['ssid'] ?? '', 60),
          'apId' => Str::upToLen($client['apId'] ?? '', 60),
          'cch' => Str::upToLen($client['cch'] ?? '', 80),
          'txRate' => Str::upToLen($client['txRate'] ?? '', 80),
          'rxRate' => Str::upToLen($client['rxRate'] ?? '', 80),
          'txBytes' => $client['txBytes'] ?? 0,
          'rxBytes' => $client['rxBytes'] ?? 0,
          'uptime' => $client['uptime'] ?? 0,
          'aip' => Str::upToLen($client['aip'] ?? '', 16),
          'inactive' => 0,

					'created' => new \DateTime(),
          'updated' => new \DateTime()
				];
				$this->app()->db()->query('INSERT INTO [mac_lan_wifiClients] ', $newItem);
        $newNdx = intval ($this->app()->db()->getInsertId ());
        $activeMacsNdxs[] = $newNdx;
			}
		}

    if (count($activeMacsNdxs))
    {
      $this->app()->db()->query('UPDATE [mac_lan_wifiClients] SET [inactive] = %i', 1, ' WHERE [ndx] NOT IN %in', $activeMacsNdxs,
                                ' AND cmsDevice = %i', $cmsDeviceNdx);
    }
    else
    {
      $this->app()->db()->query('UPDATE [mac_lan_wifiClients] SET [inactive] = %i', 1, ' WHERE cmsDevice = %i', $cmsDeviceNdx);
    }

    // -- remove old inactive
    $limit = new \DateTime('4 days ago');
    $this->app()->db()->query('DELETE FROM [mac_lan_wifiClients] WHERE [inactive] = %i', 1, ' AND [updated] < %d', $limit);

		$this->db()->commit();

		$this->result ['success'] = 1;
	}
}
