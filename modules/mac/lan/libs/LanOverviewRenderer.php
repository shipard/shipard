<?php

namespace mac\lan\libs;

use \Shipard\Base\Content, \e10\utils, \e10\json, \mac\lan\libs\dashboard\OverviewData,  \mac\data\libs\SensorHelper;


/**
 * Class LanOverviewRenderer
 * @package mac\lan\libs
 */
class LanOverviewRenderer extends Content
{
	var $lanNdx = 0;

	/** @var \mac\lan\libs\dashboard\OverviewData */
	var $lanOverviewData;

	/** @var \e10\widgetBoard */
	var $widget;

	var $mainViewType = '';
	var $dashboardDevicesGroup = LanOverviewData::dgiNONE;
	var $dashboardDeviceNdx = 0;
	var $dashboardDeviceViewId = '';

	var $macDataSourcesSensorsHelpers = [];

	var $code = '';

	public function setLan($lanNdx)
	{
		$this->lanNdx = $lanNdx;
	}

	public function createContentOverview()
	{
		$this->createContentOverview_Changes();
		$this->createContentOverview_Groups();
		$this->createContentOverview_Racks();
	}

	protected function createContentOverview_Changes()
	{
		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-wrap e10-fx-align-end e10-fx-align-stretch e10-fx-sp-between e10-row-pause bb1 pb1']);

		if (isset($this->lanOverviewData->lanChanges['nodeServers']))
		{
			$title = $this->lanOverviewData->lanChanges['nodeServers']['title'];
			$title[] = [
				'type' => 'action', 'action' => 'addwizard', 'data-class' => 'mac.lan.libs.NodeServerCfgWizard',
				'text' => 'Potvrdit změny', 'icon' => 'system/iconCheck', 'class' => 'btn-sm pull-right', 'btnClass' => 'btn-primary',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widget->widgetId,
			];
			$title[] = ['text' => '', 'class' => 'block'];
			$title[] = ['code' => '<hr style="margin-bottom: 1ex">'];

			$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col e10-fx-6 e10-fx-sm-fw e10-fx-grow e10-fx-wrap e10-fx-sp-between e10-widget-graph-pane']);

			$this->addContent([
				'type' => 'line', 'pane' => 'e10-fx-grow',
				'line' => $this->lanOverviewData->lanChanges['nodeServers']['labels'],
				'paneTitle' => $title
			]);

			$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
		}

		if (isset($this->lanOverviewData->lanChanges['lanControl']))
		{
			$title = $this->lanOverviewData->lanChanges['lanControl']['title'];
			$title[] = [
				'type' => 'action', 'action' => 'addwizard', 'data-class' => 'mac.lan.libs.LanControlCfgWizard',
				'text' => 'Potvrdit změny', 'icon' => 'system/iconCheck', 'class' => 'btn-sm pull-right', 'btnClass' => 'btn-primary',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widget->widgetId,
			];
			$title[] = ['text' => '', 'class' => 'block'];
			$title[] = ['code' => '<hr style="margin-bottom: 1ex">'];

			$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col e10-fx-6 e10-fx-sm-fw e10-fx-grow e10-fx-wrap e10-fx-sp-between e10-widget-graph-pane']);
			$this->addContent([
				'type' => 'line', 'pane' => 'e10-fx-grow',
				'line' => $this->lanOverviewData->lanChanges['lanControl']['labels'],
				'paneTitle' => $title
			]);
			$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}

	protected function createContentOverview_Groups()
	{
		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-wrap e10-fx-align-end e10-fx-align-stretch e10-fx-sp-between mt1 pt1 pb1 bb1']);

		$cntGroups = 0;

		foreach ($this->lanOverviewData->dgCfg as $groupId => $groupCfg)
		{
			if (!isset($this->lanOverviewData->dgData[$groupId]))
				continue;

			if (isset($this->lanOverviewData->dgData[$groupId]['disableAsDG']))
				continue;

			$cntGroups += $this->createContentOverview_Groups_One($groupId, $this->lanOverviewData->dgData[$groupId]);
		}

		if (!$cntGroups)
		{
			$info = [];
			$info[] = ['text' => 'Nic tu není...', 'class' => 'h1 block pa1'];
			$info[] = [
				'type' => 'action', 'action' => 'addwizard',
				'text' => 'Přidat první síť', 'data-class' => 'mac.lan.libs.AddWizardLan', 'icon' => 'icon-plus-square',
				'class' => 'text-center',
				'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widget->widgetId,
			];

			$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col e10-fx-12 e10-fx-sm-fw e10-fx-wrap e10-fx-sp-between center']);
			$this->addContent(['type' => 'line', 'line' => $info]);
			$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}

	protected function createContentOverview_Groups_One($groupId, $groupData)
	{
		$cntDevices = count($groupData['devices']);
		if (!$cntDevices)
			return 0;

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-align-grow e10-fx-grow e10-pane ml1 mr1 gg-'.$groupId]);

		$cntStatesCode = '';
		$cntStatesCode .= "<div class='dev-states'>";
		$cntStatesCode .= "<span class='dev-states-online'><i class='fa fa-circle'></i><span class='cnt'> {$cntDevices}</span></span>";
		$cntStatesCode .= '</div>';


		$this->addContent(['type' => 'line', 'line' => ['text' => $groupData['title'], 'icon' => $groupData['icon'], 'class' => 'e10-widget-big-number'], 'openCell' => 'e10-fx-col pa1']);
		$this->addContent(['type' => 'line', 'line' => ['code' => $cntStatesCode], 'closeCell' => 1]);

		if (isset($groupData['dpInfo']) && count($groupData['dpInfo']))
		{
			$info = [];
			foreach ($groupData['dpInfo'] as $dpi)
			{
				if (isset($dpi['badgeQuantityId']))
				{
					$sh = $this->macDataSourceSensorHelper($dpi['badgeDataSource']);
					if ($sh)
					{
						$bc = $sh->dsBadgeCode($dpi['label'], $dpi['badgeQuantityId'], $dpi['badgeParams']);
						$info[] = ['code' => "<div>".$bc."</div>"];
					}
				}
				else
				{
					$i = ['prefix' => $dpi['label'], 'class' => 'block'];
					if (isset($dpi['icon']))
						$i['icon'] = $dpi['icon'];
					$i['text'] = '---';
					$info[] = $i;
				}
			}

			$this->addContent(['type' => 'line', 'line' => $info, 'openCell' => 'e10-fx-col e10-fx-grow align-right pa1', 'closeCell' => 1]);
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		return 1;
	}

	protected function createContentOverview_Racks()
	{
		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-wrap e10-fx-align-end e10-fx-align-stretch e10-fx-sp-between padd5 mt1 pt1 pb1']);


		foreach ($this->lanOverviewData->racks as $rackNdx => $rackCfg)
		{

			$this->createContentOverview_Racks_One($rackNdx, $rackCfg);
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}

	protected function createContentOverview_Racks_One($rackNdx, $rackCfg)
	{
		if (!count($rackCfg['devices']) && !count($rackCfg['ups']))
			return;

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-col e10-fx-align-grow e10-fx-grow e10-fx-sp-round e10-pane ml1 mr1']);

		// -- title sensors
		$info = [];
		foreach ($rackCfg['titleSensors'] as $ts)
		{
			$sh = new SensorHelper($this->app());
			$sh->setSensorInfo($ts['info']);
			$bc = $sh->badgeCode();
			$info[] = ['code' => ' '.$bc];
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row e10-fx-sp-between e10-bg-t9 padd5 e10-fx-col-header e10-fx-col-header3']);
			$this->addContent(['type' => 'line', 'line' => ['text' => $rackCfg['title'], 'icon' => $rackCfg['icon'], 'class' => 'e10-widget-big-text'],
				'openCell' => 'e10-fx-col align-left', 'closeCell' => 1]);
			$this->addContent(['type' => 'line', 'line' => $info,
				'openCell' => 'align-right', 'closeCell' => 1]);
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);



		// -- devices
		$devTable = [];
		$devHeader = ['icon' => 'icon', 'title' => 'Zařízení', 'state' => ' Stav'];

		foreach ($rackCfg['devices'] as $deviceNdx)
		{
			$dev = $this->lanOverviewData->devices[$deviceNdx];

			$item = [
				'icon' => ['text' => '', 'icon' => $dev['icon'], 'docAction' => 'edit', 'pk' => $deviceNdx, 'table' => 'mac.lan.devices'],
				'title' => $dev['deviceId'],
				'_options' => ['cellClasses' => ['icon' => 'e10-icon']]
			];

			if (isset($dev['badgesTitle']))
				$item['_options']['cellTitles']['state'] = $dev['badgesTitle'];

			$sbCode = '';
			foreach ($dev['sensorsBadges'] as $sb)
			{
				$sh = $this->macDataSourceSensorHelper($sb['badgeDataSource']);
				if ($sh)
				{
					$bc = $sh->dsBadgeCode($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams']);
					$sbCode .= $bc;
				}
			}

			if (isset($dev['uplinkPortsBadges']))
			{
				foreach ($dev['uplinkPortsBadges'] as $sb) {
					$sh = $this->macDataSourceSensorHelper($sb['badgeDataSource']);
					if ($sh) {
						$bc = $sh->dsBadgeCode($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams']);
						$sbCode .= $bc;
					}
				}
			}

			if (isset($dev['sensors']))
			{
				foreach ($dev['sensors'] as $sb)
				{
					$sbCode .= ' ' . $sb['code'];
				}
			}

			$item['state'] = ['code' => $sbCode];


			$devTable[] = $item;
		}


		foreach ($rackCfg['ups'] as $deviceNdx)
		{
			$dev = $this->lanOverviewData->devices[$deviceNdx];

			$item = [
				'icon' => ['text' => '', 'icon' => $dev['icon'], 'docAction' => 'edit', 'pk' => $deviceNdx, 'table' => 'mac.lan.devices'],
				'title' => $dev['deviceId'],
				'_options' => ['cellClasses' => ['icon' => 'e10-icon']]
			];

			$sbCode = '';
			foreach ($dev['sensorsBadges'] as $sb)
			{
				$sh = $this->macDataSourceSensorHelper($sb['badgeDataSource']);
				if ($sh)
				{
					$bc = $sh->dsBadgeCode($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams']);
					$sbCode .= ' '.$bc;
				}
			}
			$item['state'] = ['code' => $sbCode];

			$devTable[] = $item;
		}

		$this->addContent (['type' => 'grid', 'cmd' => 'e10-fx-row']);
		$this->addContent(['type' => 'table', 'table' => $devTable, 'header' => $devHeader, 'params' => ['hideHeader' => 1, 'forceTableClass' => 'fullWidth compact Xstripped'],
			'openCell' => 'e10-fx-row e10-fx-grow', 'closeCell' => 1]);
		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);

		$this->addContent (['type' => 'grid', 'cmd' => 'fxClose']);
	}

	function createDashboard()
	{
		$dgStrToInt = [
			'server' => LanOverviewData::dgiServer, 'lan' => LanOverviewData::dgiLan, 'nas' => LanOverviewData::dgiNAS,
			'printer' => LanOverviewData::dgiPrinter, 'ups' => LanOverviewData::dgiUPS,
		];

		$this->dashboardDevicesGroup = LanOverviewData::dgiNONE;
		if (isset($dgStrToInt[$this->mainViewType]))
			$this->dashboardDevicesGroup = $dgStrToInt[$this->mainViewType];

		$c = '';

		$c .= $this->createDashboard_LeftBar();
		$c .= $this->createDashboard_RightBar();

		$this->code = $c;
	}

	function createDashboard_LeftBar()
	{
		$activeDeviceId = intval($this->app()->testGetParam ('e10-widget-dashboard-device-ndx'));

		if (isset($this->lanOverviewData->dgData[$this->mainViewType]['devices']))
		{
			if (!isset($this->lanOverviewData->dgData[$this->mainViewType]['devices'][$activeDeviceId]))
				$activeDeviceId = 0;
		}
		$c = '';

		$c .= "<div class='e10-wf-tabs-vertical' style='width: 12em; padding-top: 1em;'>";
		$c .= "<input type='hidden' name='e10-widget-dashboard-device-ndx' id='e10-widget-dashboard-device-ndx' value='$activeDeviceId'>";

		$c .= "<ul class='e10-wf-tabs' data-value-id='e10-widget-dashboard-device-ndx'>";
		//$c .= json_encode($this->mainViewType);
		//$c .= json_encode($this->lanOverviewData->dgData);
		foreach ($this->lanOverviewData->dgData[$this->mainViewType]['devices'] as $deviceNdx => $deviceInfo)
		{
			$device = $this->lanOverviewData->devices[$deviceNdx];
			if (!isset($device['dkCfg']['useMonitoring']))
				continue;
			if (!$device['monitored'] && in_array('active', $device['dkCfg']['useMonitoring']))
				continue;

			if (!$activeDeviceId)
				$activeDeviceId = $deviceNdx;

			$active = ($deviceNdx === $activeDeviceId) ? ' active' : '';
			$c .= "<li class='tab e10-widget-trigger$active' data-tabid='".$deviceNdx."'>";
			$c .= utils::es($device['title']);
			$c .= "</li>";
		}
		$c .= "<ul>";

		$c .= "</div>";

		$this->dashboardDeviceNdx = $activeDeviceId;

		return $c;
	}

	function createDashboard_RightBar()
	{
		$activeViewId = $this->app()->testGetParam ('e10-widget-dashboard-device-view-id');

		$topBar = [];

		$dde = new \mac\lan\libs\DeviceDashboardEngine($this->app());
		$dde->setDevice($this->dashboardDeviceNdx);
		$dde->createTopBar($topBar);

		if (!isset($topBar[$activeViewId]))
			$activeViewId = '';

		$c = '';


		$c .= "<div style='width: calc(100% - 12em); height: 100%; float: left;'>";

		$c .= "<div class='e10-wf-tabs-horizontal' style='padding-top: .5ex; padding-left: 1ex;'>";
		$c .= "<input type='hidden' name='e10-widget-dashboard-device-view-id' id='e10-widget-dashboard-device-view-id' value='$activeViewId'>";

		$c .= "<ul class='e10-wf-tabs' data-value-id='e10-widget-dashboard-device-view-id'>";

		foreach ($topBar as $viewId => $topBarItem)
		{
			if ($activeViewId === '')
				$activeViewId = $viewId;

			$active = ($viewId === $activeViewId) ? ' active' : '';

			$c .= "<li class='tab e10-widget-trigger$active' data-tabid='$viewId'>";
			$c .= $this->app()->ui()->composeTextLine($topBarItem['title']);
			$c .= "</li>";
		}

		$c .= '</ul>';

		$c .= '</div>';

		$this->dashboardDeviceViewId = $activeViewId;

		$activeTopBarItem = $topBar[$activeViewId];
		if ($activeTopBarItem['type'] === 'nothing')
		{
			$c .= $this->app()->ui()->composeTextLine(['text' => 'Monitoring tohoto zařízení není nastaven...', 'class' => 'padd5 e10-error']);
		}
		elseif ($activeTopBarItem['type'] === 'iframe')
		{
			$c .= "<iframe data-sandbox='allow-scripts' frameborder='0' height='100%' width='100%' style='width:100%;height:calc(100% - 2.7em - .5ex);' src='{$activeTopBarItem['url']}'></iframe>";
		}

		return $c;
	}

	function createCode()
	{
		$cr = new \e10\ContentRenderer($this->app());
		$cr->content = $this->content;
		$this->code .= $cr->createCode('body');
	}

	function macDataSourceSensorHelper($dsNdx)
	{
		if (!isset($this->macDataSourcesSensorsHelpers[$dsNdx]))
		{
			$this->macDataSourcesSensorsHelpers[$dsNdx] = NULL;

			$dsInfo = $this->db()->query ('SELECT * FROM [mac_data_sources] WHERE ndx = %i', $dsNdx)->fetch();
			if ($dsInfo)
			{
				$sh = new SensorHelper($this->app());
				$sh->dataSource = $dsInfo->toArray();

				$this->macDataSourcesSensorsHelpers[$dsNdx] = $sh;
			}
		}

		return $this->macDataSourcesSensorsHelpers[$dsNdx];
	}

	public function run($mainViewType, \Shipard\UI\Core\WidgetBoard $widget)
	{
		$this->widget = $widget;
		$this->mainViewType = $mainViewType;

		$this->lanOverviewData = new \mac\lan\libs\dashboard\OverviewData($this->app());
		//$this->lanOverviewData = new \mac\lan\libs\LanOverviewData($this->app());
		$this->lanOverviewData->setLan($this->lanNdx);
		$this->lanOverviewData->run();

		if ($mainViewType === 'overview')
		{
			//$this->createContentOverview();
			//$this->createCode();
		}
		else
		{
			$this->createDashboard();
		}
	}
}
