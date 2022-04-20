<?php

namespace hosting\core\libs;
use \mac\data\libs\SensorsBadges;
use \Shipard\Utils\Utils;


/**
 * @class WidgetHostingServer
 */
class WidgetHostingServer extends \Shipard\UI\Core\WidgetPane
{
  var $mainServerNdx = 0;
  var $mainServerRecData = NULL;
  var $mainServerBadges = [];
  var $mainServerInfo = [];

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

    $b = $this->updownIOBadge($this->mainServerRecData);
    if ($b !== '')
      $this->mainServerInfo[] = $b;

    if ($useNetdata)
    {
      $bc = $this->sensorsBadges->netdataBadgeImg(
        'main-server-netdata', 'CPU', 'system.cpu', 
        ['before' => '-60', 'after' => '-60', 'units' => '%', 'precision' => 2, 'value_color' => 'COLOR:null|orange>50|red>90|00A000>=0', 'badgeClass' => 'pl1 pb1'],
      );
      $this->mainServerBadges[] = $bc;

      $bc = $this->sensorsBadges->netdataBadgeImg(
        'main-server-netdata', 'load15', 'system.load', 
        ['dimensions' => 'load15', 'units' => ' ', 'precision' => 2, 'value_color' => 'COLOR:null|orange>1|red>4|00A000>=0', 'badgeClass' => 'pl1 pb1 pr1'],
      );
      $this->mainServerBadges[] = $bc;

      $bc = $this->sensorsBadges->netdataBadgeImg(
        'main-server-netdata', 'uptime', 'system.uptime', 
        ['dimensions' => 'uptime', 'value_color' => 'COLOR:null|orange>3888000|red>15552000|00A000>=0', 'badgeClass' => 'pb1 pr1'],
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

      $updownIOBadge = $this->updownIOBadge($r);
      if ($updownIOBadge !== '')
      {
        $srv['server'][] = ['code' => $updownIOBadge];
      }

      if ($r['vmId'] !== '')
      {
        //if ($updownIOBadge !== '')
        //  $srv['server'][] = ['text' => '', 'class' => 'break block pb05'];

        $vmId = $r['vmId'];
        $bc = $this->sensorsBadges->netdataBadgeImg(
          'main-server-netdata', 'CPU', 'cgroup_'.$vmId.'.cpu_limit', 
          ['dimensions' => 'used', 'units' => '%', 'precision' => 1, 'value_color' => 'COLOR:null|orange>50|red>90|00A000>=0', 'badgeClass' => 'pr1 pl1'],
        );
        $srv['server'][] = ['code' => $bc];

        $bc = $this->sensorsBadges->netdataBadgeImg(
          'main-server-netdata', 'MEM', 'cgroup_'.$vmId.'.mem_usage_limit', 
          ['dimensions' => 'used', 'precision' => 1, 'value_color' => 'COLOR:null|orange>4000|red>8000|00A000>=0', 'badgeClass' => 'pr1'],
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
    foreach ($this->mainServerInfo as $mbc)
    {
      $mainServerLine [] = ['code' => $mbc, 'class' => 'pl1'];
    }
    $mainServerLine [] = ['text' => '', 'class' => 'break'];
    foreach ($this->mainServerBadges as $mbc)
    {
      $mainServerLine [] = ['code' => $mbc/*, 'class' => 'pl1'*/];
    }
    $mainServerLine [] = ['text' => '', 'class' => 'break bb1 block'];

    $this->addContent(['type' => 'line', 'line' => $mainServerLine]);
    $this->addContent([
      'table' => $this->subServersTable, 'header' => $this->subServersHeader,
      'params' => ['hideHeader' => 1, 'forceTableClass' => 'dcInfo fullWidth']
    ]);
  }

	public function setDefinition ($d)
	{
		$this->definition = ['class' => 'e10-pane POKUS1', 'type' => 'hosting-server'];
	}

  protected function updownIOBadge($recData)
  {
    if ($recData['updownIOId'] === '')
      return '';

    $uptimeVal = $recData['updownIOUptime'];
    $ssl = $recData['updownIOSSLValid'];
    $httpStatus = intval($recData['updownIOStatus']);
    
    $url = 'https://updown.io/'.$recData['updownIOId'];
    $b = '';
    $b .= "<span class='df2-action-trigger shp-badge ml1' data-url-download='$url' data-with-shift='tab' data-action='open-link' data-popup-id='updownio' style='white-space: pre; font-size:.85rem; display: inline-block; border-radius: 2px; border:none;'>";
    $b .= "<span class='e10-bg-bt' style='border-top-left-radius: 3px;border-bottom-left-radius: 3px;'>";

    $sensorIcon = 'system/iconGlobe';
    $b .= $this->app()->ui()->icon($sensorIcon);
    //$b .= Utils::es('UP');
    $b .= '</span>';

    $color = $uptimeVal < 99.99 ? 'orange' : '#00AA00';
    $b .= "<span class='value' style='background-color: $color; border-top-right-radius: 3px; border-bottom-right-radius: 3px;'> ";
    $b .= strval($uptimeVal).'% ';
    $b .= "</span>";

    if ($ssl !== 2)
    {
      if (!$ssl)
      {
        $b .= "<span class='e10-bg-bt'>" . '&nbsp;SSL ' . "</span>";

        $color = $ssl ? '#00AA00' : '#CC0000';
        $b .= "<span class='value' style='background-color: $color;'> ";
        $b .= ($ssl) ? 'OK&nbsp;&nbsp;' : 'INVALID';
        $b .= "</span>";
      }

      if ($httpStatus !== 200)
      {
        $b .= "<span class='e10-bg-bt'>" . '&nbsp;HTTP ' . "</span>";
        $color = ($httpStatus === 200) ? '#00AA00' : '#CC0000';
        $b .= "<span class='value' style='background-color: $color; border-top-right-radius: 3px; border-bottom-right-radius: 3px;'> ";
        $b .= ($httpStatus === 200) ? '200 OK' : '!!!'.$httpStatus;
        $b .= "</span>";
      }
    }  
    $b .= "</span>";

    return $b;
  }

	public function title()
	{
		return FALSE;
	}
}
