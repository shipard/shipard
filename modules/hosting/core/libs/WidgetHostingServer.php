<?php

namespace hosting\core\libs;
use \mac\data\libs\SensorsBadges;


/**
 * @class WidgetHostingServer
 */
class WidgetHostingServer extends \Shipard\UI\Core\WidgetPane
{
  var $mainServerNdx = 0;
  var $mainServerRecData = NULL;
  var $mainServerBadges = [];

  var $subServersTable = [];
  var $subServersHeader;

  var \hosting\Core\TableServers $tableServers;
  var \mac\data\libs\SensorsBadges $sensorsBadges;


  protected function loadServer()
  {
    $this->mainServerRecData = $this->tableServers->loadItem($this->mainServerNdx);

    $useNetdata = 0;
    $this->sensorsBadges = new \mac\data\libs\SensorsBadges($this->app());
    if ($this->mainServerRecData['netdataUrl'] !== '')
    {
      $this->sensorsBadges->addSource('main-server-netdata', ['type' => SensorsBadges::bstNetdata, 'url' => $this->mainServerRecData['netdataUrl']]);
      $useNetdata = 1;
    }

    if ($useNetdata)
    {
      $bc = $this->sensorsBadges->netdataBadgeImg(
        'main-server-netdata', 'load15', 'system.load', 
        ['dimensions' => 'load15', 'units' => ' ', 'precision' => 2, 'value_color' => 'COLOR:null|orange>3|red>6|00A000>=0', 'badgeClass' => 'pl1 pb1 pr1'],
      );
      $this->mainServerBadges[] = $bc;

      $bc = $this->sensorsBadges->netdataBadgeImg(
        'main-server-netdata', 'uptime', 'system.uptime', 
        ['dimensions' => 'uptime', 'precision' => 2, 'value_color' => 'COLOR:null|orange>7776000|red>15552000|00A000>=0', 'badgeClass' => 'pb1 pr1'],
      );
      $this->mainServerBadges[] = $bc;
    }


    // containers  
    $q[] = 'SELECT * FROM [hosting_core_servers]';
    array_push($q, 'WHERE [hwServer] = %i', $this->mainServerNdx);
    array_push($q, 'ORDER BY name, ndx');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $srv = [
        'icon' => ['text' => '', 'icon' => $this->tableServers->tableIcon($r), 'class' => 'pl1 e10-widget-big-text'],
        'server' => [['text' => $r['name'], 'class' => 'e10-widget-big-text']],
      ];

      if ($r['vmId'] !== '')
      {
        $vmId = $r['vmId'];
        $bc = $this->sensorsBadges->netdataBadgeImg(
          'main-server-netdata', 'CPU', 'cgroup_'.$vmId.'.cpu_limit', 
          ['dimensions' => 'used', 'units' => '%', 'precision' => 1, 'value_color' => 'COLOR:null|orange>50|red>90|00A000>=0', 'badgeClass' => 'pl1 pb1 pr1'],
        );
        $srv['server'][] = ['code' => $bc];

        $bc = $this->sensorsBadges->netdataBadgeImg(
          'main-server-netdata', 'MEM', 'cgroup_'.$vmId.'.mem_usage_limit', 
          ['dimensions' => 'used', 'precision' => 1, 'value_color' => 'COLOR:null|orange>4000|red>8000|00A000>=0', 'badgeClass' => 'pr1 pb1'],
        );
        $srv['server'][] = ['code' => $bc];
      }

      $this->subServersTable[] = $srv;
    }

    $this->subServersHeader = ['icon' => 'i', 'server' => 's'];
  }


  public function createContent ()
	{
    $this->mainServerNdx = intval($this->app()->testGetParam('serverNdx'));
    if (!$this->mainServerNdx)
    {
      $this->addContent (['type' => 'line', 'line' => ['text' => 'server `'.$this->mainServerNdx.'` not found...']]);
      return;
    }
    $this->tableServers = $this->app()->table('hosting.core.servers');
    $this->loadServer();


    $mainServerLine = [];
    $mainServerLine [] = ['text' => $this->mainServerRecData['name'], 'class' => 'e10-widget-big-number', 'icon' => $this->tableServers->tableIcon($this->mainServerRecData)];
    foreach ($this->mainServerBadges as $mbc)
    {
      $mainServerLine [] = ['code' => $mbc, 'class' => 'pl1'];
    }
    $mainServerLine [] = ['text' => '', 'class' => 'break bb1 block'];

    $this->addContent(['type' => 'line', 'line' => $mainServerLine]);
    $this->addContent([
      'table' => $this->subServersTable, 'header' => $this->subServersHeader,
      'params' => ['hideHeader' => 1, 'forceTableClass' => 'dcInfo fullWidth']
    ]);
  }

	public function title()
	{
		return FALSE;
	}
}
