<?php

namespace mac\lan;

use \E10\utils, \E10\Utility, \E10\uiutils;


/**
 * Class LanAddressesWidget
 * @package mac\lan
 */
class LanAddressesWidget extends \E10\widgetPane
{
	var $calParams;
	var $calParamsValues;
	var $mobileMode;
	var $tableDevices;

	var $rangeNdx = 0;
	var $rangeRecData;
	var $rangeInfo;
	var $lanNdx = 0;
	var $lanRecData;

	var $servers;
	var $deviceKinds;

	var $devices = [];
	var $groups = [];

	var $serverData;
	var $data = [];

	CONST rtCount = 3;

	public function loadData ()
	{
		$q[] = 'SELECT ifaces.*, devices.ndx AS deviceNdx, devices.fullName as deviceFullName, devices.deviceKind, devices.id as deviceId, ';
		array_push ($q, ' devices.docState, devices.docStateMain');
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON ifaces.device = devices.ndx');
		array_push ($q, ' WHERE devices.docState IN %in', [1000, 4000, 8000, 9100]);
		array_push ($q, ' AND devices.lan = %i', $this->lanNdx);
		array_push ($q, ' ORDER BY devices.fullName');
		$rows = $this->app->db()->query($q);

		foreach ($rows as $r)
		{
			if ($r['ip'] === '')
				continue;

			$ip = $r['ip'];
			$ipLong = ip2long($ip);
			if ($ipLong < $this->rangeInfo['first_host'] || $ipLong > $this->rangeInfo['last_host'])
				continue;

			$this->data[$ip]['device'] = [
					'text' => $r['deviceFullName'], 'icon' => $this->tableDevices->tableIcon($r), 'suffix' => $r['deviceId'],
					'docAction' => 'edit', 'table' => 'mac.lan.devices', 'pk' => $r['deviceNdx']
			];
			$this->data[$ip]['deviceMac'] = ['text' => $r['mac']];
		}
	}

	protected function createAddresses ()
	{
		$this->rangeInfo = $this->ipv4rangeInfo($this->rangeRecData['range']);
		foreach ($this->rangeInfo['hosts'] as $ip)
		{
			$c = '';
			for ($i = 0; $i < self::rtCount; $i++)
				$c .= "<span data-rt-id='{$ip}-$i'></span>";

			$this->data[$ip] = ['addr' => $ip, 'rtt' => '', 'rtf' => ['code' => $c], '_options' => ['cellClasses' => ['rtt' => 'e10-lans-rt-info width10em', 'addr' => 'ip']]];
		}
	}

	protected function loadFromServer ()
	{
		$server = $this->servers [$this->lanRecData['localServer']];
		$url = $server['camerasURL'].'/lans/status';
		$opts = ['http'=>['timeout' => 1, 'method'=> "GET", 'header'=> "Connection: close\r\n"]];
		$context = stream_context_create($opts);
		$resultString = file_get_contents ($url, FALSE, $context);
		if (!$resultString)
			return;

		$this->serverData = json_decode($resultString, TRUE);
		foreach ($this->serverData['data'] as $i)
		{
			$ip = $i['ip'];
			if (!isset($this->data[$ip]))
				continue;

			$this->data[$ip]['serverMac'] = $i['mac'];
		}
	}

	public function createContent ()
	{
		$this->servers = $this->app->cfgItem('e10.terminals.servers');
		$this->tableDevices = $this->app->table('mac.lan.devices');
		$this->createContent_Toolbar();

		$this->lanNdx = intval($this->calParamsValues['lan']['value']);
		$this->lanRecData = $this->app->loadItem ($this->lanNdx, 'mac.lan.lans');

		$this->rangeNdx = intval($this->calParamsValues['range']['value']);
		$this->rangeRecData = $this->app->loadItem ($this->rangeNdx, 'mac.lan.lansAddrRanges');

		$this->createAddresses ();
		$this->loadData();
		$this->loadFromServer();

		$this->createContent_Overview ();

		$serversStr = json_encode($this->servers);
		$c = "<script>e10.widgets.lans.init('{$this->widgetId}', {$serversStr});</script>";
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function createContent_Overview ()
	{
		$this->mobileMode = $this->app->mobileMode;
		$this->deviceKinds = $this->app->cfgItem ('mac.lan.devices.kinds');

		$h = ['addr' => 'Adresa', 'device' => 'Zařízení', 'deviceMac' => 'MAC', 'serverMac' => 'MAC LAN', 'rtt' => '_Stav', 'rtf' => '_Status'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data, 'main' => TRUE, 'params' => ['tableClass' => 'stripped']]);
	}

	public function createContent_Toolbar ()
	{
		$this->calParams = new \E10\Params ($this->app);
		$this->addParamSites();
		$this->addParamRanges();

		$this->calParams->detectValues ();
		$this->calParamsValues = $this->calParams->getParams();

		$c = '';

		$c .= "<div class='padd5' style='display: inline-block; width: 100%;'>";
		$c .= "<span class='pull-right'>";
		$c .= $this->calParams->createParamCode('lan');
		$c .= '&nbsp;';
		$c .= $this->calParams->createParamCode('range');
		$c .= '</span>';

		$c .= '</div>';

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function addParamSites ()
	{
		$enum = $this->db()->query('SELECT * FROM [mac_lan_lans] WHERE docStateMain < 4 ORDER BY shortName')->fetchPairs('ndx', 'shortName');
		$this->calParams->addParam ('switch', 'lan', ['title' => 'Síť', 'switch' => $enum, 'radioBtn' => 1]);
	}

	public function addParamRanges ()
	{
		$lan = uiutils::detectParamValue('lan', '1');
		$enum = $this->db()->query('SELECT * FROM [mac_lan_lansAddrRanges] WHERE [lan] = %i ORDER BY [range]', $lan)->fetchPairs('ndx', 'range');
		$this->calParams->addParam ('switch', 'range', ['title' => 'Rozsah', 'switch' => $enum, 'radioBtn' => 1]);
	}

	public function title() {return FALSE;}

	public function ipv4rangeInfo ($range)
	{
		$parts = explode('/', $range);
		$ip_address = $parts[0];
		$ip_nmask = long2ip(-1 << (32 - (int)$parts[1]));
		$ip_count = 1 << (32 - (int)$parts[1]);

		$hosts = [];

		//convert ip addresses to long form
		$ip_address_long = ip2long($ip_address);
		$ip_nmask_long = ip2long($ip_nmask);

		//calculate network address
		$ip_net = $ip_address_long & $ip_nmask_long;

		//calculate first usable address
		$ip_host_first = ((~$ip_nmask_long) & $ip_address_long);
		$ip_first = ($ip_address_long ^ $ip_host_first) + 1;

		//calculate last usable address
		$ip_broadcast_invert = ~$ip_nmask_long;
		////$ip_last = ($ip_address_long | $ip_broadcast_invert) - 1;
		$ip_last = $ip_first + $ip_count - 2;

		//calculate broadcast address
		$ip_broadcast = $ip_address_long | $ip_broadcast_invert;

		for ($ip = $ip_first; $ip <= $ip_last; $ip++)
		{
			array_push($hosts, long2ip($ip));
		}

		$block_info = [
				'network' => $ip_net,
				'first_host' => $ip_first,
				'last_host' => $ip_last,
				'broadcast' => $ip_broadcast,
				'hosts' => $hosts
		];

		return $block_info;
	}
}
