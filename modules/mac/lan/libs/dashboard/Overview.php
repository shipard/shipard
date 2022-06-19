<?php

namespace mac\lan\libs\dashboard;


use \Shipard\Base\Content, \e10\utils, \e10\json;
use mac\data\libs\SensorHelper;

use function e10\sortByOneKey;

/**
 * Class Overview
 * @package mac\lan\libs\dashboard
 */
class Overview extends Content
{
	var $lanNdx = 0;

	/** @var \e10\widgetBoard */
	var $widget;

	/** @var \mac\lan\libs\dashboard\OverviewData */
	var $overviewData;

	/** @var \mac\lan\libs\LanTree */
	var $lanTree;

	var $macDataSourcesSensorsHelpers = [];
	var $lanSensorsHelpers = [];

	var $code = '';


	public function setLan($lanNdx)
	{
		$this->lanNdx = $lanNdx;
	}

	public function createContentOverview()
	{
		$changesNodeServers = $this->createContentOverview_Changes('nodeServers');
		$changesLanControl = $this->createContentOverview_Changes('lanControl');

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='e10-gs-row'>"]);
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='e10-gs-col e10-gs-half'>"]);
				if ($changesNodeServers)
					$this->addContent($changesNodeServers);
				$this->ccLanOverview('srv');
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "<div class='e10-gs-col e10-gs-half'>"]);
				if ($changesLanControl)
					$this->addContent($changesLanControl);
				$this->ccLanOverview();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => "</div>"]);
	}

	protected function ccLanOverview($deviceGroup = '')
	{
		$baseElementId = 'e10-lan-overview'.$deviceGroup;
		$c = '';
		$c .= "<div class='e10-static-tabs e10-pane e10-pane-info' id='{$baseElementId}' style='height: 100%;'>";

		// -- tabs
		$active = ' active';
		$c .= "<ul class='e10-static-tabs tabs'>";
		foreach ($this->overviewData->dgCfg as $dgId => $dg)
		{
			if ($deviceGroup !== '' && $deviceGroup !== $dgId)
				continue;
			if ($deviceGroup === '' && $dgId === 'srv')
				continue;
			if (!isset($this->overviewData->dgData[$dgId]['devices']) || !count($this->overviewData->dgData[$dgId]['devices']))
				continue;

			$c .= "<li class='e10-static-tab tab$active' data-content-id='{$baseElementId}-{$dgId}' style='border-right: 1px solid rgba(0,0,0,.3);'>";
			$c .= "&nbsp;";
			$c .= $this->app()->ui()->icon($dg['icon']).' ';
			$c .= "<span class='e10-ntf-badge'>?</span>";
			$c .= "&nbsp;</li>";

			$active='';
		}
		$c .= "</ul>";

		// -- content
		$active = 'active';
		$c .= "<div class='e10-static-tab-content' style='overflow-y: auto;'>";
		foreach ($this->overviewData->dgCfg as $dgId => $dg)
		{
			if ($deviceGroup !== '' && $deviceGroup !== $dgId)
				continue;
			if ($deviceGroup === '' && $dgId === 'srv')
				continue;
			if (!isset($this->overviewData->dgData[$dgId]['devices']) || !count($this->overviewData->dgData[$dgId]['devices']))
				continue;

			$c .= "<div class='$active' id='{$baseElementId}-{$dgId}'>";
			if ($dgId == OverviewData::dgiLan)
				$c .= $this->createContentOverview_LanTree();
			else
				$c .= $this->ccLanOverviewDevices($dgId);
			$c .= "</div>";

			$active='';
		}
		$c .= '</div>';

		$c .= '</div>';

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	function ccLanOverviewDevices($dgId)
	{
		$c = '';

		$c .= "<table class='compact fullWidth stripped'>";
		$devices = \e10\sortByOneKey($this->overviewData->dgData[$dgId]['devices'], 'treeOrder', TRUE);
		foreach ($devices as $deviceNdx => $deviceInfo)
		{
			$device = $this->overviewData->devices[$deviceNdx];
			if ($device['hideFromDR'])
				continue;
			$c .= "<tr data-overview-group='e10-lan-do-{$dgId}'>";

			$treeLevel = ($deviceInfo['treeLevel']) ? ('padding-left: '.($deviceInfo['treeLevel'] * 2).'em;') : '';
			$c .= "<td style='vertical-align: top; line-height: 1.8; padding-top: 1px; white-space: pre !important;$treeLevel' class='width20'>";
			$c .= "<span class='indicator' id='e10-lan-do-{$deviceNdx}'>".$this->app()->ui()->icon('system/iconCheck')."</span> ";
			$c .= $this->app()->ui()->renderTextLine(['text' => $device['title'], 'icon' => $device['icon']/*, 'suffix' => $device['deviceId']*/]);
			$c .= "</td>";

			$c .= "<td style='vertical-align: middle; padding-left: 1em; line-height: 1.8;padding-top: 1px;'>";

			if (isset($device['lanBadges']))
			{
				foreach ($device['lanBadges'] as $sb)
				{
					$sh = $this->lanSensorHelper($device['lan']);
					if ($sh) {
						$bc = $sh->lanBadgeImg($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams'], $sb['lanBadgesUrl'] ?? '');
						$c .= ' ' . "<span>".$bc."</span>";
					}
				}
			}

			foreach ($device['sensorsBadges'] as $sb)
			{
				$sh = $this->macDataSourceSensorHelper($sb['badgeDataSource']);
				if ($sh)
				{
					$bc = $sh->dsBadgeImg($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams']);
					$c .= ' '.$bc;
				}
			}

			if (isset($device['sensors']))
			{
				foreach ($device['sensors'] as $sb)
				{
					$c .= ' ' . $sb['code'];
				}
			}

			$c .= "</td>";


			$c .= "</tr>";
		}

		$c .= "</table>";

		return $c;
	}

	protected function createContentOverview_LanTree()
	{
		$c = '';

		$c .= "<table class='compact fullWidth stripped'>";
		foreach ($this->lanTree->dataTree as $treeItemNdx => $treeItem)
		{
			$device = $this->overviewData->devices[$treeItemNdx];
			if ($device['hideFromDR'])
				continue;

			$c .= "<tr data-overview-group='e10-lan-do-lan'>";

			$c .= "<td style='vertical-align: top; white-space: pre !important; line-height: 1.8;padding-top: 1px;' class='width20'>";
			$c .= "<span class='indicator' id='e10-lan-do-{$treeItemNdx}'>".$this->app()->ui()->icon('system/iconCheck')."</span> ";
			$c .= utils::es($treeItem['title']);
			$c .= "</td>";

			$c .= "<td style='vertical-align: middle; padding-left: 1em; line-height: 1.8;padding-top: 1px;'>";
			if (isset($this->overviewData->devices[$treeItemNdx]['uplinkPortsBadges']))
			{
				foreach ($this->overviewData->devices[$treeItemNdx]['uplinkPortsBadges'] as $sb)
				{
					$sh = $this->lanSensorHelper($device['lan']);
					if ($sh) {
						$bc = $sh->lanBadgeImg($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams']);
						$c .= ' ' . "<span>".$bc."</span>";
					}
				}
			}

			if (isset($this->overviewData->devices[$treeItemNdx]['lanBadges']))
			{
				foreach ($this->overviewData->devices[$treeItemNdx]['lanBadges'] as $sb)
				{
					$sh = $this->lanSensorHelper($device['lan']);
					if ($sh) {
						$bc = $sh->lanBadgeImg($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams'], $sb['lanBadgesUrl'] ?? '');
						$c .= ' ' . "<span>".$bc."</span>";
					}
				}
			}

			foreach ($device['sensorsBadges'] as $sb)
			{
				$sh = $this->macDataSourceSensorHelper($sb['badgeDataSource']);
				if ($sh)
				{
					$bc = $sh->dsBadgeImg($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams']);
				}
			}

			if (isset($device['sensors']))
			{
				foreach ($device['sensors'] as $sb)
				{
					$c .= '&nbsp;' . $sb['code'];
				}
			}

			$c .= "</td>";


			$c .= "</tr>";
			$c .= $this->createContentOverview_LanTree_Code(2, $treeItem['items']);
		}
		$c .= "</table>";

		return $c;
	}

	protected function createContentOverview_LanTree_Code($level, $items)
	{
		$c = '';
		if (!count($items))
			return '';


		foreach ($items as $treeItemNdx => $treeItem)
		{
			$device = $this->overviewData->devices[$treeItemNdx];
			if ($device['hideFromDR'])
				continue;

			$c .= "<tr data-overview-group='e10-lan-do-lan'>";

			//$rack = $this->lanTree->racks[$treeItem['rackNdx']];

			$c .= "<td style='vertical-align: middle;  padding-left: {$level}em; padding-top: 1px; white-space: pre !important; line-height: 1.8;' class='width20'>";
			$c .= "<span class='indicator' id='e10-lan-do-{$treeItemNdx}'>".$this->app()->ui()->icon('system/iconCheck')."</span> ";
			$c .= utils::es($treeItem['title']);
			$c .= "</td>";
			
			$c .= "<td style='vertical-align: middle; padding-left: 1em; line-height: 1.8; padding-top: 1px;'>";
			if (isset($this->overviewData->devices[$treeItemNdx]['uplinkPortsBadges']))
			{
				foreach ($this->overviewData->devices[$treeItemNdx]['uplinkPortsBadges'] as $sb)
				{
					$sh = $this->lanSensorHelper($device['lan']);
					if ($sh) {
						$bc = $sh->lanBadgeImg($sb['label'], $sb['badgeQuantityId'], $sb['badgeParams']);
						$c .= ' ' . "<span>".$bc."</span>";
					}
				}
			}
			$c .= "</td>";

			$c .= "</tr>";

			if (count($treeItem['items']))
			{
				$c .= $this->createContentOverview_LanTree_Code($level + 1, $treeItem['items']);
			}
		}

		return $c;
	}

	protected function ccAlerts()
	{
		$this->createContentOverview_Changes();

		$c = '';

		$alertGroups = $this->app()->cfgItem('mac.lan.alerts.dashboardGroups');
		$c .= "<div id='e10-lan-alerts'>";
		foreach ($alertGroups as $agId => $ag)
		{
			if (isset($this->overviewData->dgData[$agId]) && (!isset($this->overviewData->dgData[$agId]['devices']) || !count($this->overviewData->dgData[$agId]['devices'])))
				continue;

			$c .= "<div id='e10-lan-alerts-{$agId}' class='e10-pane e10-pane-table' data-scope-id='{$agId}'>";

			$c .= "<div class='alert-title'>";
			$c .= $this->app()->ui()->renderTextLine(['text' => $ag['fn'], 'icon' => $ag['icon'], 'class' => 'e10-widget-big-text']);
			$groupData = $this->overviewData->dgData[$agId] ?? [];
			if (isset($groupData['dpInfo']) && count($groupData['dpInfo']))
			{

				$c .= "<span class='pull-right'>";
				foreach ($groupData['dpInfo'] as $dpi)
				{
					/*
					if (isset($dpi['badgeQuantityId']))
					{
						$sh = $this->lanSensorHelper($dpi['lan']);
						if ($sh)
							$c .= '&nbsp; '.$sh->lanBadgeImg($dpi['label'], $dpi['badgeQuantityId'], $dpi['badgeParams']);
					}
					*/
				}
				$c .= '</span>';
			}
			$c .= "</div>";

			$c .= "<details class='pt1'>";
			$c .= "<summary class='pb1'>";
			$c .= "</summary>";
			$c .= "<div class='content'>";
			$c .= "</div>";
			$c .= "</details>";
			$c .= "</div>";

		}
		$c .= "</div>";

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
	}

	protected function createContentOverview_Changes($changesType = '')
	{
		if ($changesType === 'nodeServers')
		{
			if (isset($this->overviewData->lanChanges['nodeServers']))
			{
				$title = $this->overviewData->lanChanges['nodeServers']['title'];
				$title[] = [
					'type' => 'action', 'action' => 'addwizard', 'data-class' => 'mac.lan.libs.NodeServerCfgWizard',
					'text' => 'Potvrdit změny', 'icon' => 'system/iconCheck', 'class' => 'btn-sm pull-right', 'btnClass' => 'btn-primary',
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widget->widgetId,
				];
				$title[] = ['text' => '', 'class' => 'block'];
				$title[] = ['code' => '<hr style="margin-bottom: 1ex">'];

				return [
					'type' => 'line', 'pane' => 'e10-pane-core e10-pane-info pa1 e10-bg-t2',
					'line' => $this->overviewData->lanChanges['nodeServers']['labels'],
					'paneTitle' => $title
				];
			}
			return NULL;
		}

		if ($changesType === 'lanControl')
		{
			if (isset($this->overviewData->lanChanges['lanControl']))
			{
				$title = $this->overviewData->lanChanges['lanControl']['title'];
				$title[] = [
					'type' => 'action', 'action' => 'addwizard', 'data-class' => 'mac.lan.libs.LanControlCfgWizard',
					'text' => 'Potvrdit změny', 'icon' => 'system/iconCheck', 'class' => 'btn-sm pull-right', 'btnClass' => 'btn-primary',
					'data-srcobjecttype' => 'widget', 'data-srcobjectid' => $this->widget->widgetId,
				];
				$title[] = ['text' => '', 'class' => 'block'];
				$title[] = ['code' => '<hr style="margin-bottom: 1ex">'];

				$c = '';
				$c .= $this->app()->ui()->composeTextLine($this->overviewData->lanChanges['lanControl']['labels']);

				$c .= "<details class='pt1'>";
				$c .= "<summary class='pb1'>".utils::es('Přehled změn');
				$c .= "</summary>";
				$c .= "<div class='content2'>";

				foreach ($this->overviewData->lanChanges['lanControl']['table'] as $changes)
				{
					$c .= $this->app()->ui()->renderTextLine(['text' => $changes['changes']['title'], 'class' => 'h2 block']);
					$c .= "<div style='display: overflow-x: auto; border: 1px solid rgba(0,0,0,.25); background-color: #F0F0F0; margin-bottom: 2ex; padding: 2px;'><pre><code>".$changes['changes']['text'].'</code></pre></div>';
				}

				$c .= "</div>";
				$c .= "</details>";

				return [
					'type' => 'text', 'subtype' => 'rawhtml',
					'pane' => 'e10-pane-core e10-pane-info pa1 e10-bg-t2',
					'text' => $c,
					'paneTitle' => $title
				];
			}
			return NULL;
		}	

		return NULL;
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

	function lanSensorHelper($lanNdx) : ?SensorHelper
	{
		if (!isset($this->lanSensorsHelpers[$lanNdx]))
		{
			$this->lanSensorsHelpers[$lanNdx] = NULL;

			$lanRecData = $this->overviewData->lanRecData($lanNdx);
			if (!$lanRecData || !isset($lanRecData['mainServerLanControl']) || !$lanRecData['mainServerLanControl'])
				return NULL;

			$mainServerLanControl = $this->overviewData->devices[$lanRecData['mainServerLanControl']] ?? NULL;
			if (!$mainServerLanControl)
				return NULL;
		
			$httpsPort = (isset($mainServerLanControl['macDeviceCfg']['httpsPort']) && (intval($mainServerLanControl['macDeviceCfg']['httpsPort']))) ? intval($mainServerLanControl['macDeviceCfg']['httpsPort']) : 443;
			$url = 'https://'.$mainServerLanControl['macDeviceCfg']['serverFQDN'].':'.$httpsPort.'/netdata/';

			$sh = new SensorHelper($this->app());
			$sh->lanInfo = ['baseUrl' => $url, 'lanBadgesUrl' => $mainServerLanControl['lanBadgesUrl']];

			$this->lanSensorsHelpers[$lanNdx] = $sh;
		}

		return $this->lanSensorsHelpers[$lanNdx];
	}

	function createCode()
	{
		$cr = new \e10\ContentRenderer($this->app());
		$cr->content = $this->content;
		$this->code .= $cr->createCode('body');
		$this->code .= "<script>e10.widgets.macLan.init('{$this->widget->widgetId}');</script>";
	}

	public function run(\Shipard\UI\Core\WidgetBoard $widget)
	{
		$this->widget = $widget;

		$this->overviewData = new \mac\lan\libs\dashboard\OverviewData($this->app());
		$this->overviewData->setLan($this->lanNdx);
		$this->overviewData->run();

		$this->lanTree = new \mac\lan\libs\LanTree($this->app());
		$this->lanTree->init();
		$this->lanTree->setLan($this->lanNdx);
		$this->lanTree->load();

		$this->createContentOverview();
		$this->createCode();
	}
}
