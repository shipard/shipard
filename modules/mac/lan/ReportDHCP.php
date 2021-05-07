<?php

namespace mac\lan;

use e10\utils;


/**
 * Class ReportDHCP
 * @package mac\lan
 */
class ReportDHCP extends \mac\lan\Report
{
	var $data = [];
	var $files = [];
	var $lanNdx = 1;

	function init ()
	{
		parent::init();
	}

	function createContent ()
	{
		$this->loadData();

		switch ($this->subReportId)
		{
			case '':
			case 'overview': $this->createContent_Overview(); break;
			case 'mikrotik': $this->createContent_Mikrotik(); break;
			case 'linux': $this->createContent_Linux (); break;
		}

		$this->setInfo('title', 'Nastavení DHCP');
	}

	function createContent_Overview ()
	{
		$h = ['#' => '#', 'ip' => 'IP', 'mac' => 'MAC', 'hostid' => 'hostId', 'comment' => 'Komentář'];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data]);
	}

	function createContent_Mikrotik ()
	{
		$this->addContent (['type' => 'text', 'subtype' => 'plain', 'text' => $this->files['mikrotik']]);
	}

	function createContent_Linux ()
	{
		$this->addContent (['type' => 'text', 'subtype' => 'plain', 'text' => $this->files['linux']]);
	}

	public function loadData ()
	{
		$this->files['mikrotik'] = ''; // /ip dhcp-server lease remove [find server=server1]
		$this->files['linux'] = '';

		$q[] = 'SELECT ifaces.*, devices.ndx AS deviceNdx, devices.fullName as deviceFullName, devices.deviceKind, devices.id as deviceId, ranges.dhcpServerId, ranges.id AS rangeId ';
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON ifaces.device = devices.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_lansAddrRanges] AS [ranges] ON ifaces.range = ranges.ndx');
		array_push ($q, ' WHERE devices.docStateMain < 3');
		array_push ($q, ' AND devices.lan = %i', $this->reportParams ['lan']['value']);
		array_push ($q, ' AND ifaces.addrType = %i', 1);
		array_push ($q, ' ORDER BY ranges.id, INET_ATON(ifaces.ip), devices.fullName');
		$rows = $this->app->db()->query($q);

		$lastRangeNdx = -1;

		foreach ($rows as $r)
		{
			if ($lastRangeNdx !== $r['range'])
			{
				if ($this->files['mikrotik'] !=='')
					$this->files['mikrotik'] .= "\n";
				$this->files['mikrotik'] .= "/ip dhcp-server lease\n";

				if ($this->files['linux'] !=='')
					$this->files['linux'] .= "\n";
				$this->files['linux'] .= '### '.$r['rangeId']."\n";
			}

			$hostId = ($r['dnsname'] !== '') ? $r['dnsname'] : strtolower(utils::safeChars($r['deviceFullName']));
			$newItem = ['ip' => $r['ip'], 'mac' => $r['mac'], 'comment' => $r['deviceFullName'], 'hostid' => $hostId];

			if ($r['mac'] !== '')
			{
				$this->files['mikrotik'] .= 'add address=' . $r['ip'] .
					' mac-address=' . $r['mac'] .
					' comment="' . utils::safeChars($r['deviceFullName'], FALSE, TRUE) . '"' .
					' server='.$r['dhcpServerId']."\n";

				$this->files['linux'] .= "host $hostId {hardware ethernet " . $r['mac'] .
					'; fixed-address ' . $r['ip'] . ';} # ' . $r['deviceFullName'] . "\n";
			}
			$this->data[]= $newItem;

			$lastRangeNdx = $r['range'];
		}
	}

	public function createToolbarSaveAs (&$printButton)
	{
		$printButton['dropdownMenu'][] = ['text' => 'Mikrotik (.rsc)', 'icon' => 'icon-file-text-o', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'mikrotik'];
		$printButton['dropdownMenu'][] = ['text' => 'Linux (.conf)', 'icon' => 'icon-file-text-o', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'linux'];
	}

	public function saveReportAs ()
	{
		$this->loadData();

		$fileName = utils::tmpFileName('cfg');
		file_put_contents($fileName, $this->files[$this->format]);

		$this->fullFileName = $fileName;
		$this->saveFileName = $this->saveAsFileName ($this->format);
		$this->mimeType = 'text/plain';
	}

	public function subReportsList ()
	{
		$d[] = ['id' => 'overview', 'icon' => 'icon-table', 'title' => 'Přehled'];
		$d[] = ['id' => 'mikrotik', 'icon' => 'icon-hdd-o', 'title' => 'Mikrotik'];
		$d[] = ['id' => 'linux', 'icon' => 'icon-linux', 'title' => 'Linux'];

		return $d;
	}

	public function saveAsFileName ($type)
	{
		switch ($type)
		{
			case 'linux': return 'dhcpd.conf';
			case 'mikrotik': return 'dhcp.rsc';
		}
	}
}
