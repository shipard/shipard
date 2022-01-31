<?php

namespace mac\lan;

use \Shipard\UI\Core\WidgetBoard, \e10\utils, \e10\Utility, \e10\uiutils;


/**
 * Class LanMonitoringWidget
 * @package mac\lan
 */
class LanMonitoringWidget extends WidgetBoard
{
	var $today;
	var $mobileMode;

	var $lanNdx = 0;

	var $deviceKinds;

	var $devices = [];
	var $groups = [];

	var $devicesRacks = [];
	var $racks = [];

	var $devicesPlaces = [];
	var $tablePlaces;
	var $places;

	var $usedDevices = [];

	var $classification;

	var $usersSections = [];

	CONST rtCount = 3;

	public function init ()
	{
		$this->deviceKinds = $this->app->cfgItem ('mac.lan.devices.kinds');

		$this->tablePlaces = $this->app->table ('e10.base.places');
		$this->places = $this->tablePlaces->loadTree();


		$this->createTabs();

		parent::init();
	}

	public function createContent ()
	{
		$this->panelStyle = self::psNone;

		$viewerMode = '0';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$viewerMode = $vmp[2];

		if (substr ($this->activeTopTab, 0, 4) === 'lan-')
		{
			$parts = explode ('-', $this->activeTopTab);
			$this->lanNdx = intval($parts[1]);
			$this->loadData();

			if ($viewerMode === 'overview')
				$this->createContent_Overview(1);
			elseif ($viewerMode === 'overviewnew')
				$this->createContent_Overview(0);
			elseif ($viewerMode === 'dashboard')
				$this->createContent_Dashboard($vmp[3]);
			elseif ($viewerMode === 'schema')
				$this->createGraph();
			elseif ($viewerMode === 'old')
				$this->createContent_Overview_OLD();
		}
		elseif (substr ($this->activeTopTab, 0, 8) === 'section-')
		{
			$parts = explode ('-', $this->activeTopTab);

			$section = $this->usersSections['top'][$parts[1]];
			$this->addContentViewer('wkf.core.issues', 'wkf.core.viewers.DashboardIssuesSection', ['section' => $parts[1], 'viewerMode' => $viewerMode]);
		}
	}

	function createTabs ()
	{
		$tabs = [];

		$this->addLansTabs($tabs);

		$this->toolbar = ['tabs' => $tabs];
	}

	function addLansTabs (&$tabs)
	{
		$enum = $this->db()->query('SELECT * FROM [mac_lan_lans] WHERE docStateMain < 4 ORDER BY [order], shortName')->fetchPairs('ndx', 'shortName');

		foreach ($enum as $lanNdx => $lanName)
		{
			$icon = 'system/iconSitemap';
			$tabs['lan-'.$lanNdx] = ['icon' => $icon, 'text' => $lanName, 'action' => 'load-lan-'.$lanNdx];
		}

		if (count($enum) !== 1)
		{
			$tabs['lan-0'] = ['icon' => 'icon-globe', 'text' => 'Vše', 'action' => 'load-lan-0'];
		}
	}

	function addWorkflowSectionsTabs (&$tabs)
	{
		/** @var $tableSections \wkf\base\TableSections */
		$tableSections = $this->app()->table ('wkf.base.sections');

		/** @var $tableIssues \wkf\core\TableIssues */
		$tableIssues = $this->app()->table ('wkf.core.issues');

		$itSectionNdx = $tableIssues->defaultSection  (160);
		$this->usersSections = $tableSections->usersSections();

		$marks = new \lib\docs\Marks($this->app());
		$marks->setMark(100);
		$marks->loadMarks('wkf.base.sections', array_keys($this->usersSections['top']));

		foreach ($this->usersSections['top'] as $sectionNdx => $s)
		{
			if ($itSectionNdx != $sectionNdx)
				continue;
			if (isset($s['subSections']) && count($s['subSections']) && !count($s['ess']))
				continue;

			$tabs['sep-it'] = ['line' => ['text' => '|', 'class' => 'e10-off']];

			$icon = 'icon-file';
			if (isset($s['icon']) && $s['icon'] !== '')
				$icon = $s['icon'];

			$markEnable = 1;
			if ($s['sst'] == 10)
				$markEnable = 0;

			$tab = [];
			$tab[] = ['text' => $s['sn'], 'icon' => $icon, 'class' => ''];
			if ($markEnable)
			{
				$nv = isset($marks->marks[$sectionNdx]) ? $marks->marks[$sectionNdx] : 0;
				if (!isset($marks->markCfg['states'][$nv]))
					$nv = 0;
				$nt = $marks->markCfg['states'][$nv]['name'];
				$tab[] = ['code' => "<span class='e10-ntf-badge' id='ntf-badge-wkf-s{$sectionNdx}' style='display:none;'></span>"];
				$tab[] = [
					'text' => ''.$nv, 'docAction' => 'mark', 'mark' => 100, 'table' => 'wkf.base.sections', 'pk' => $sectionNdx,
					'value' => $nv, 'title' => $nt, 'class' => '', 'mark-st' => 'ts',
				];
			}
			$tabs['section-'.$s['ndx']] = ['line' => $tab, 'action' => 'load-section-' . $s['ndx']];
		}
	}

	protected function initRightTabs ()
	{
		if (substr ($this->activeTopTab, 0, 8) === 'section-')
		{
			$rt = [
				'viewer-mode-2' => ['text' =>'', 'icon' => 'icon-th', 'action' => 'viewer-mode-2'],
				'viewer-mode-1' => ['text' =>'', 'icon' => 'icon-th-list', 'action' => 'viewer-mode-1'],
				'viewer-mode-3' => ['text' =>'', 'icon' => 'icon-square', 'action' => 'viewer-mode-3'],
				'viewer-mode-0' => ['text' =>'', 'icon' => 'icon-th-large', 'action' => 'viewer-mode-0'],
			];
			$this->toolbar['rightTabs'] = $rt;
		}
		else
		{
			$rt = [
				'viewer-mode-overview' => ['text' => '', 'icon' => 'system/detailOverview', 'action' => 'viewer-mode-overview'],
				'viewer-mode-old' => ['text' => '', 'icon' => 'system/iconFile', 'action' => 'viewer-mode-old'],
				'viewer-mode-dashboard-srv' => ['text' => '', 'icon' => 'deviceTypes/server', 'action' => 'viewer-mode-dashboard-server'],
				//'viewer-mode-dashboard-nas' => ['text' => '', 'icon' => 'deviceTypes/nas', 'action' => 'viewer-mode-dashboard-nas'],
				'viewer-mode-dashboard-lan' => ['text' => '', 'icon' => 'tables/mac.lan.lans', 'action' => 'viewer-mode-dashboard-lan'],
				'viewer-mode-dashboard-printer' => ['text' => '', 'icon' => 'deviceTypes/printer', 'action' => 'viewer-mode-dashboard-printer'],
				'viewer-mode-dashboard-ups' => ['text' => '', 'icon' => 'deviceTypes/ups', 'action' => 'viewer-mode-dashboard-ups'],
				'viewer-mode-schema' => ['text' => '', 'icon' => 'system/iconImage', 'action' => 'viewer-mode-schema'],
				'viewer-mode-overviewnew' => ['text' => '', 'icon' => 'system/detailOverview', 'action' => 'viewer-mode-overviewnew'],
			];
			$this->toolbar['rightTabs'] = $rt;
		}
	}

	public function loadData ()
	{
		$addrTypes = $this->app->cfgItem('mac.lan.ifacesAddrTypes');

		$q[] = 'SELECT ifaces.*, devices.ndx AS deviceNdx, devices.fullName as deviceFullName, devices.deviceKind, devices.id as deviceId,';
		array_push ($q, ' devices.alerts, devices.lan, devices.place, devices.rack');
		array_push ($q, ' FROM [mac_lan_devicesIfaces] AS ifaces');
		array_push ($q, ' LEFT JOIN mac_lan_devices AS devices ON ifaces.device = devices.ndx');
		//array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON devices.lan = lans.ndx');
		array_push ($q, ' LEFT JOIN e10_base_places AS places ON devices.place = places.ndx');
		array_push ($q, ' WHERE devices.docStateMain < 3');

		if ($this->lanNdx)
			array_push ($q, ' AND devices.lan = %i', $this->lanNdx);

		array_push ($q, ' ORDER BY places.id, devices.fullName');
		$rows = $this->app->db()->query($q);

		$pks = [];
		foreach ($rows as $r)
		{
			$deviceNdx = $r['deviceNdx'];
			$deviceKind = $r['deviceKind'];
			$deviceRack = $r['rack'];
			$devicePlace = $r['place'];
			if (!isset($this->devices[$deviceNdx]))
			{
				$dk = $this->deviceKinds[$deviceKind];
				$alerts = $r['alerts'] ? $r['alerts'] : $dk['alerts'];

				$this->devices[$deviceNdx] = ['title' => $r['deviceFullName'], 'deviceId' => $r['deviceId'], 'icon' => $dk['icon'], 'rack' => $deviceRack, 'alerts' => $alerts, 'ifaces' => []];
				$this->groups[$deviceKind][] = $deviceNdx;

				$this->devicesPlaces[$devicePlace][] = $deviceNdx;

				if ($deviceRack)
					$this->devicesRacks[$deviceRack][] = $deviceNdx;

				$pks[] = $deviceNdx;
			}

			$ip = ['prefix' => $addrTypes[$r['addrType']]['sc'], 'text' => '!!!', 'class' => 'e10-small'];
			if ($r['addrType'] === 2)
			{
				$ip['text'] = 'dhcp';
			}
			else
			{
				if ($r['ip'] !== '')
					$ip['text'] = $r['ip'];
				else
					$ip['text'] = '???';
			}
			if ($r['mac'] !== '')
				$ip['title'] = $r['mac'];


			$newItem = ['ip' => $ip, 'mac' => $r['mac'], 't' => $r['addrType'], 'id' => $r['id'], 'r' => $r['range']];
			$this->devices[$deviceNdx]['ifaces'][] = $newItem;
		}

		$this->classification = \E10\Base\loadClassification ($this->app, 'mac.lan.devices', $pks, 'label id pull-right');
	}

	function createContentOneDevice($deviceNdx, $device)
	{
		$cntInterfaces = 0;
		foreach ($device['ifaces'] as $iface)
		{
			if ($iface['ip']['text'] === '')
				continue;
			$cntInterfaces++;
		}

		if (!$cntInterfaces)
			return '';

		$c = '';
		$devId = $this->widgetId.'-'.$deviceNdx;
		$devClass = 'e10-lans-device e10-ld-off';
		if ($device['alerts'] === 1)
			$devClass .= ' e10-ld-alert';

		$c .= "<div id='$devId' class='e10-document-trigger $devClass' data-table='mac.lan.devices' data-pk='$deviceNdx' data-action='edit'>";
		$c .= "<div>";
		$c .= $this->app()->ui()->icon($device['icon']).' ';
		$c .= utils::es($device['title']);
		$c .= "<label class='label label-default pull-right'>".utils::es($device['deviceId']).'</label> ';
		$c .= '</div>';

		$c .= "<div style='display: inline-block;'>";
		foreach ($device['ifaces'] as $iface)
		{
			//$c .= "<label class='label label-default'>".$this->app()->ui()->renderTextLine($iface['ip']).'</label>&nbsp;';
			$c .= $this->app()->ui()->renderTextLine($iface['ip']);
			break;
		}
		if (isset ($this->classification [$deviceNdx]))
		{
			forEach ($this->classification [$deviceNdx] as $clsfGroup)
				$c .= $this->app()->ui()->composeTextLine($clsfGroup);
		}

		$ifaceIdx = 0;
		$c .= "<div style='background-color: red; display:none;'>";
		foreach ($device['ifaces'] as $iface)
		{
			if ($iface['ip'] === '')
				continue;
			$ipId = str_replace('.', '-', $iface['ip']['text']);
			if ($cntInterfaces > 1 && $iface['id'] !== '')
				$iface['ip']['suffix'] = $iface['id'];

			$c .= "<div class='ip' id='{$this->widgetId}-ip-{$iface['r']}-{$ipId}'>".$this->app()->ui()->renderTextLine($iface['ip']).'</div>';
			$c .= "<div class='e10-lans-rt-info e10-small nowrap'>---</div>";

			$c .= "<span class='e10-lans-rt-flags nowrap'>";
			for ($i = 0; $i < self::rtCount; $i++)
				$c .= "<span data-rt-id='r{$iface['r']}-{$ipId}-$i'>?</span>";
			$c .= '</span>';

			$ifaceIdx++;
		}
		$c .= '</div>';

		$c .= '</div>';
		$c .= '</div>';

		$this->usedDevices[] = $deviceNdx;

		return $c;
	}

	public function createContent_Overview($newMode = 0)
	{
		if ($newMode)
		{
			$lor = new \mac\lan\libs\dashboard\Overview($this->app());
			$lor->setLan($this->lanNdx);
			$lor->run($this);

			$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $lor->code]);
		}
		else
		{
			//$lor = new \mac\lan\libs\LanOverviewRenderer($this->app());
			//$lor->setLan($this->lanNdx);
			//$lor->run('overview', $this);

			//$this->addContent(['type' => 'text', 'subtype' => 'rawhtml', 'text' => $lor->code]);
		}
	}

	public function createContent_Dashboard($groupId)
	{
		$lor = new \mac\lan\libs\LanOverviewRenderer($this->app());
		$lor->setLan($this->lanNdx);
		$lor->run($groupId, $this);

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $lor->code]);
	}

	public function createContent_Overview_OLD ()
	{
		$this->mobileMode = $this->app->mobileMode;
		$kinds = \E10\sortByOneKey($this->deviceKinds, 'overviewOrder', TRUE);

		$codeLeft = '';
		$codeRight = '';
		foreach ($kinds as $kindId => $kind)
		{
			if (!isset($this->groups[$kindId]) || !count($this->groups[$kindId]))
				continue;

			$title = [
					['text' => $kind['pluralName'], 'icon' => $kind['icon'], 'class' => ''],
					['text' => strval(count($this->groups[$kindId])), 'class' => 'pull-right e10-small'],
			];
			$cs = $this->mobileMode ? '3' : '4';
			$c = "<tr class='subheader'><td class='header' colspan='$cs'>";
			$c .= $this->app()->ui()->composeTextLine($title);

			$c .= '</td></tr>';

			foreach ($this->groups[$kindId] as $deviceNdx)
			{
				$d = $this->devices[$deviceNdx];
				$c .= $this->createContent_Overview_Device($deviceNdx, $d);
			}

			if ($kind['overviewOrder'] < 30000)
				$codeLeft .= $c;
			else
				$codeRight .= $c;
		}

		$c = '';
		$c .= "<div class='e10-gs-row'>";

		$c .= "<div class='e10-gs-col e10-gs-half'>";
		if (!$this->mobileMode)
			$c .= "<div class='e10-pane e10-pane-table'>";
		$c .= "<table class='e10-lans-devices default fullWidth'>";
		$c .= $codeLeft;
		$c .= '</table>';
		if (!$this->mobileMode)
			$c .= '</div>';
		$c .= '</div>';


		$c .= "<div class='e10-gs-col e10-gs-half'>";
		if ($codeRight !== '')
		{
			if (!$this->mobileMode)
				$c .= "<div class='e10-pane e10-pane-table'>";
			$c .= "<table class='e10-lans-devices default fullWidth'>";
			$c .= $codeRight;
			$c .= '</table>';
			if (!$this->mobileMode)
				$c .= '</div>';
			$c .= '</div>';
		}
		$c .= '</div>';

		$c .= '</div>';

		$c .= "<script>e10.widgets.macLan.init('{$this->widgetId}');</script>";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	public function createContent_Overview_Device ($deviceNdx, $d)
	{
		$cntInterfaces = 0;
		foreach ($d['ifaces'] as $iface)
		{
			if ($iface['ip']['text'] === '')
				continue;
			$cntInterfaces++;
		}

		if (!$cntInterfaces)
			return '';

		$c = '';
		$ifaceIdx = 0;
		foreach ($d['ifaces'] as $iface)
		{
			if ($iface['ip'] === '')
				continue;
			$ipId = str_replace('.', '-', $iface['ip']['text']);
			if ($cntInterfaces > 1 && $iface['id'] !== '')
				$iface['ip']['suffix'] = $iface['id'];
			$c .= "<tr class='e10-lans-device' id='{$this->widgetId}-nd-{$deviceNdx}'>";
			if ($ifaceIdx === 0)
			{
				$title = [[
					'text' => $d['title'], 'suffix' => $d['deviceId'], 'class' => '',
					'docAction' => 'edit', 'table' => 'mac.lan.devices', 'pk' => $deviceNdx
				]];

				if (isset ($this->classification [$deviceNdx]))
				{
					forEach ($this->classification [$deviceNdx] as $clsfGroup)
						$title = array_merge ($title, $clsfGroup);
				}

				$c .= "<td class='title' rowspan='$cntInterfaces'>";
				$c .= $this->app()->ui()->composeTextLine($title);
				$c .= '</td>';
			}
			if ($this->app->mobileMode)
			{
				$c .= "<td class='ip' id='{$this->widgetId}-ip-{$iface['r']}-{$ipId}'>".$this->app()->ui()->composeTextLine($iface['ip']).
						"<div class='e10-lans-rt-info e10-small nowrap'>---</div>".
						'</td>';
			}
			else
			{
				$c .= "<td class='ip' id='{$this->widgetId}-ip-{$iface['r']}-{$ipId}'>".$this->app()->ui()->renderTextLine($iface['ip']).'</td>';
				$c .= "<td class='e10-lans-rt-info e10-small nowrap'>---</td>";

			}
			$c .= "<td class='e10-lans-rt-flags nowrap'>";
			for ($i = 0; $i < self::rtCount; $i++)
				$c .= "<span data-rt-id='r{$iface['r']}-{$ipId}-$i'></span>";
			$c .= '</td>';
			$c .= '</tr>';

			$ifaceIdx++;
		}

		return $c;
	}

	public function title() {return FALSE;}

	public function createGraph ()
	{
		$lanSchema = new \mac\lan\dataView\LanSchema($this->app);
		$lanSchema->setRequestParams(['lan' => $this->lanNdx, /*'graphOrientation' => 'landscape'*/]);
		$lanSchema->run();

		$imgUrl = $this->app->urlProtocol . $_SERVER['HTTP_HOST'] . $this->app->dsRoot . '/' . $lanSchema->data['schemaFileName'];
		$c = "<a href='$imgUrl' target='_blank'><img id='e10-widget-lan-scheme' style='width: 100%; ' src='$imgUrl'></a>";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}
}
