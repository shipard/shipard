<?php

namespace mac\vs;

use e10\utils, \mac\data\libs\SensorHelper;


/**
 * Class WidgetLiveNG
 */
class WidgetLiveNG extends \Shipard\UI\Core\UIWidgetBoard
{
	var $code = '';
	var $viewerMode = '';
	var $gridDefinition = [];

	var $cameras = [];
	var $sensors = [];
	var $servers;

	var $archiveNow;
	var $archiveNowHour;
	var $archiveNowDate;
	var $archiveNowYear;
	var $archiveNowMonth;
	var $archiveNowDay;
	var $archiveContent = [];
	var $archiveContentByDate = [];
	var $archiveEnabledHours = [];

	var $zoneNdx = 0;
	var $zone = NULL;

	/** @var  \mac\base\TableZones */
	var $tableZones;

	var $usersZones;

	var $iotSC = [];
	var $iotScenes = [];



	public function init ()
	{
		$this->tableZones = $this->app->table ('mac.base.zones');

		if (!$this->zoneNdx)
			$this->zoneNdx = 1;

		$this->usersZones = $this->tableZones->usersZones('vs-sub', $this->zoneNdx);

		//error_log("__UZ: `{$this->zoneNdx}`".json_encode($this->usersZones));

		$this->servers = $this->app->cfgItem('mac.localServers');

		$this->createTabs();

		parent::init();

		$this->viewerMode = 'matrix2';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$this->viewerMode = $vmp[2];





		$this->panelStyle = self::psFixed;

		$parts = explode ('-', $this->activeTopTab);
		$activeSubZone = intval($parts['1'] ?? 1);

		$allCameras = $this->app->cfgItem('mac.cameras');
		$this->zone = $this->app()->cfgItem('mac.base.zones.'.$activeSubZone, NULL);
		if ($this->zone)
		{
			foreach ($this->zone['cameras'] as $zoneCamNdx)
			{
				$this->cameras[$zoneCamNdx] = $allCameras[$zoneCamNdx];
			}
		}
	}

	function createTabs ()
	{
		$tabs = [];

		foreach ($this->usersZones as $z)
		{
			$icon = 'tables/mac.base.zones';
			$tabs['subzone-'.$z['ndx'].'-'.$this->zoneNdx] = ['icon' => $icon, 'text' => $z['sn'], 'action' => 'load-subzone-' . $z['ndx'].'-'.$this->zoneNdx];
		}
		$this->toolbar = ['tabs' => $tabs];
	}

	function createGridDefinition ()
	{
    $this->createGridDefinitionMatrix(1);
	}

	function createGridDefinitionSmart ()
	{
		$this->gridDefinition = ['rows' => []];

		$cntCameras = count($this->cameras);

		$w = 3;
		while ($cntCameras < (12/$w))
			$w++;


		// -- first row with smart cell
		$row = ['cells' => []];
		if ($cntCameras === 2)
		{
			$row['cells'][] = ['width' => 8, 'type' => 'smart'];
			$secondCell = ['width' => 4, 'rows' => []];

			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
		}
		elseif ($cntCameras === 3)
		{
			$row['cells'][] = ['width' => 9, 'type' => 'smart'];
			$secondCell = ['width' => 3, 'rows' => []];
			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
		}
		elseif ($cntCameras <= 7)
		{
			$row['cells'][] = ['width' => 9, 'type' => 'smart'];
			$secondCell = ['width' => 3, 'rows' => []];
			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
			$secondCell['rows'][] = ['cells' => [['width' => 12]]];
		}
		else
		{
			$row['cells'][] = ['width' => 8, 'type' => 'smart'];
			$secondCell = ['width' => 4, 'rows' => []];
			$secondCell['rows'][] = ['cells' => [['width' => 6], ['width' => 6]]];
			$secondCell['rows'][] = ['cells' => [['width' => 6], ['width' => 6]]];
			$secondCell['rows'][] = ['cells' => [['width' => 6], ['width' => 6]]];
			$secondCell['rows'][] = ['cells' => [['width' => 6], ['width' => 6]]];
		}

		$row['cells'][] = $secondCell;
		$this->gridDefinition['rows'][] = $row;

		// -- next rows
		$cntRows = $cntCameras / (12/$w) + 1;

		$cellNdx = 1;
		for ($rowNdx = 1; $rowNdx <= $cntRows; $rowNdx++)
		{
			$row = ['cells' => []];
			$cntCols = 12/$w;
			for ($colNdx = 1; $colNdx <= $cntCols; $colNdx++)
			{
				$row['cells'][] = ['width' => $w];

				$cellNdx++;

				if ($cellNdx > $cntCameras)
					break;
			}
			$this->gridDefinition['rows'][] = $row;

			if ($cellNdx > $cntCameras)
				break;
		}
	}

  function createGridDefinitionMatrix ($matrixSize)
	{
		$this->gridDefinition = ['rows' => []];

		$cntCameras = count($this->cameras);

		if (!$cntCameras)
			return;

		if ($matrixSize === 1)
			$w = 3;
		elseif ($matrixSize === 2)
			$w = 6;

		while ($cntCameras < (12/$w))
			$w++;

		$cntRows = $cntCameras / (12/$w) + 1;

		$cellNdx = 1;
		for ($rowNdx = 1; $rowNdx <= $cntRows; $rowNdx++)
		{
			$row = ['cells' => []];
			$cntCols = 12/$w;
			for ($colNdx = 1; $colNdx <= $cntCols; $colNdx++)
			{
				$row['cells'][] = ['width' => $w];

				$cellNdx++;

				if ($cellNdx > $cntCameras)
					break;
			}
			$this->gridDefinition['rows'][] = $row;

			if ($cellNdx > $cntCameras)
				break;
		}
	}

	function createGridCode()
	{
		$c = '';

		$camIndex = 0;
		$usedLocalServers = [];

		$camsStyle = '';

		$c .= "<div id='e10-widget-vs-error' style='display: none; text-align: center; flex-direction: column; position: absolute; width: 100%; z-index: 2010; padding: 1rem; background-color: rgba(255,0,0,.5); color: white; top: 50%;'>";
		$c .= "<span class='h1 pt1 pb1'>".utils::es('Chyba načítání obrázků ze serveru...').'</span>';
		$c .= "</div>";

		$c .= "<div class='container-fluid'$camsStyle>";
		$c .= $this->createGridCodeCell($this->gridDefinition, $usedLocalServers, $camIndex);
		$c .= "</div>";

		$this->code .= $c;
	}

	function createGridCodeCell($gridCell, &$usedLocalServers, &$camIndex)
	{
		$cntCameras = count($this->cameras);
		$c = '';

    $colsInRow = 3;
    if ($cntCameras < $colsInRow)
      $colsInRow = $cntCameras;
    //elseif ($cntCameras === 4)
    //  $colsInRow = 2;
    elseif ($cntCameras > 9)
      $colsInRow++;

    $c .= "<div class='row row-cols-$colsInRow g-3'>"; // row-cols-1 row-cols-md-3 g-3

		foreach ($gridCell['rows'] as $row)
		{
			//$c .= "<div class='e10-fx-row e10-fx-sm-wrap'>";
			foreach ($row['cells'] as $cell)
			{
				if ($camIndex >= $cntCameras)
					break;

				$cellClass = '';
				$cellParams = '';

				if (isset($cell['type']) && $cell['type'] === 'smart')
				{
					$camNdx = $this->zone['cameras'][$camIndex];
					$cam = $this->cameras[$camNdx];
					$cellClass .= ' e10-wvs-smart-main-box e10-widget-trigger e10-fx-sm-hide e10-fx-sp-around';
					$cellParams .= "id='e10-vs-smart-main' data-active-cam='{$cam['ndx']}' data-call-function='e10.widgets.macVs.zoomMainPicture'";
				}

				//$c .= "<div class='e10-fx-col e10-fx-sm-fw e10-fx-{$cell['width']}{$cellClass}'{$cellParams} style='position: relative; justify-content: flex-start;'>";
        //$c .= "<div class='col col-{$cell['width']}'>";
        $c .= "<div class='col'>";
				if (isset($cell['rows']))
				{
					$c .= $this->createGridCodeCell($cell, $usedLocalServers, $camIndex);
				}
				else
				{
					$camNdx = $this->zone['cameras'][$camIndex];
					$cam = $this->cameras[$camNdx];
					$srvNdx = $cam['localServer'];
					if (!isset($usedLocalServers[$srvNdx]))
					{
						$usedLocalServers[$srvNdx] = $this->servers[$srvNdx];
						if (isset($usedLocalServers[$srvNdx]['subsystems']))
							unset($usedLocalServers[$srvNdx]['subsystems']);
						if (isset($usedLocalServers[$srvNdx]['cameras']))
							unset($usedLocalServers[$srvNdx]['cameras']);
						if (isset($usedLocalServers[$srvNdx]['lan']))
							unset($usedLocalServers[$srvNdx]['lan']);
					}

					$c .= $this->gridImgElement($cell, $cam);

					if (!isset($cell['type']))
						$camIndex++;
				}
				$c .= '</div>';

				if ($camIndex >= $cntCameras)
					break;
			}
		}
    $c .= "</div>";

		return $c;
	}

	protected function gridImgElement ($cell, $cam)
	{

		$cameraNdx = $cam['ndx'];
		$phUrl = $this->app->urlRoot.'/www-root/sc/shipard/ph-image-1920-1080.svg';

		$srv = $this->servers[$cam['localServer']];


		$badgesSmall = '';
		$badgesBig = '';
		if ($this->viewerMode !== 'videoArchive' && isset($this->sensors[$cameraNdx]))
		{
			foreach ($this->sensors[$cameraNdx] as $placeId => $placeContent)
			{
				$posStyle = '';

				switch ($placeContent['camPosH'])
				{
					case 0: $posStyle .= 'left: 5px;'; break; // left
					case 1: $posStyle .= 'right: 5px;'; break; // right
				}
				switch ($placeContent['camPosV'])
				{
					case 0: $posStyle .= 'bottom: 5px;'; break; // bottom
					case 1: $posStyle .= 'top: 5px;'; break; // top
				}

				$badgesSmall .= "<div class='e10-cam-sensor-display' style='position: absolute; $posStyle'>";
				$badgesBig .= "<div class='e10-cam-sensor-display' style='position: absolute; $posStyle'>";
				foreach ($placeContent['sensors'] as $sensor)
				{
					$badgesSmall .= $sensor['code'];
					$badgesBig .= $sensor['code'];
				}
				$badgesSmall .= "</div>";
				$badgesBig .= "</div>";
			}
		}

		$c = '';

		$c .= "<div class='e10-vs-img' style='position: relative;'>";
		$c .= "{{{@iotControl;type:camPicture;pictStyle:video;ndx:$cameraNdx}}}";
		$c .= '</div>';

    $this->uiTemplate->loadTemplate ('e10pro.templates.basic', 'page.mustache', $c);
    $c = $this->uiTemplate->renderTemplate();

		return $c;
	}

	function baseVideoFileName ($cam)
	{
		$hp = explode ('-', $this->archiveNowHour);
		$hpid = intval($hp[0]);
		$camNdx = $cam['ndx'];
		$files = $this->archiveContent['archive'][$this->archiveNowDate][$hpid][$camNdx];

		$fileBegin = $this->archiveNowDate.'_'.$this->archiveNowHour;

		foreach ($files as $f)
		{
			if (substr($f, 0, 16) === $fileBegin)
				return $hp[0].'/'.$camNdx.'/'.$f;
		}

		return '';
	}

	public function createContent ()
	{
		$this->createGridDefinition();

		if (substr ($this->activeTopTab, 0, 8) === 'subzone-')
		{
			$this->createGridCode();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
		}
	}

	public function createCodeInitJS()
	{
		if ($this->fullCode)
			return "\n<script>(() => {initWidgetVS ('{$this->widgetId}');})();</script>";

		return '';
	}

	public function title()
	{
		return FALSE;
	}

	public function pageType()
	{
		return 'widget';
	}

	function createCodeToolbar ()
	{
		if (!$this->toolbar)
			return '';

		$c = '';
		$c .= "<div class='shp-wb-toolbar d-flex pe-3'>";

		$tabsClass = 'e10-wf-tabs';
		if (!count ($this->toolbar['tabs']))
			$tabsClass .= ' e10-wf-tabs-inside-viewer';

		foreach ($this->toolbar as $key => $obj)
		{
			if ($key === 'tabs')
			{
				$c .= "<input type='hidden' name='topTabId' id='{$this->widgetId}_mainTabs_Value' data-wid='$this->widgetId' value='{$this->activeTopTab}'>";
        $c .= "<ul class='nav nav-pills' id='{$this->widgetId}_mainTabs'>\n";

				foreach ($this->toolbar['tabs'] as $tabId => $tab)
				{
					$tabParams = '';
					if (isset($tab['title']))
						$tabParams = ' title="'.utils::es($tab['title']).'"';
					$active = ($this->activeTopTab === $tabId) ? ' active' : '';
          $c .= "<li class='nav-item'>\n";

          $c .= "<a class='shp-widget-action nav-link$active' href='#' data-tabs='mainTabs' data-tab-id='{$tabId}' data-action='select-main-tab'>";
          if (isset($tab['line']))
          {
            $c .= '<span>'.$this->app()->ui()->composeTextLine($tab['line']).'</span>';
          }
          elseif ($tab['text'] !== '')
          {
            if (isset($tab['icon']))
              $c .= $this->app()->ui()->icon($tab['icon']);

            $c .= '&nbsp;' . utils::es($tab['text']);
          }
          $c .= "</a>";

					$c .= "</li>\n";
				}
				$c .= "</ul>\n";
			}

			if ($key === 'rightTabs')
			{
				//$c .= "<input type='hidden' name='e10-widget-topTab-right' id='e10-widget-topTab-value-right' value='{$this->activeTopTabRight}'>";
        $c .= "<input type='hidden' name='rightTabId' id='{$this->widgetId}_rightTabs_Value' data-wid='$this->widgetId' value='{$this->activeTopTabRight}'>";
				$c .= "<div class='btn-group' role='group' id='{$this->widgetId}_rightTabs' style='margin-left: auto;'>";
				foreach ($this->toolbar['rightTabs'] as $tabId => $tab)
				{
					$active = ($this->activeTopTabRight === $tabId) ? ' active' : '';
					$icon = $this->app()->ui()->icon($tab['icon']);
          $c .= "<a href='#' class='shp-widget-action btn btn-outline-primary$active' data-tabs='rightTabs' data-tab-id='{$tabId}' data-action='select-main-tab'>";
          $c .= $icon;
          if (isset($tab['text']) && $tab['text'] !== '')
            $c .= utils::es($tab['text']);
          $c .= '</a>';
				}
				$c .= '</div>';
			}

      /*
			if ($key === 'buttons')
			{
				$c .= "<ul class='e10-wf-tabs' style='float:right;'>";
				foreach ($this->toolbar['buttons'] as $b)
				{
					if ((isset($b['element']) && $b['element'] === 'li') || (isset($b['type']) && $b['type'] === 'li'))
						$c .= $this->app()->ui()->composeTextLine($b);
					else
					{
						$c .= "<li>";
						$c .= $this->app()->ui()->composeTextLine($b);
						$c .= "</li>";
					}
				}
				$c .= "</ul>";
			}
      */
		}


		$c .= '</div>';

		return $c;
	}
}
