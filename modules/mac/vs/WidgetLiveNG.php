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
		//error_log("__ZN `$activeSubZone`: ".json_encode($this->zone['cameras']));
		//error_log("__CAMS2: !{$this->activeTopTab}! `{$this->zoneNdx}` / `{$activeSubZone}`".json_encode(array_keys($this->cameras)));
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

		$rt = [
			//'viewer-mode-smart' => ['text' =>'', 'icon' => 'system/dashboardModeRows', 'action' => 'viewer-mode-smart'],
			//'viewer-mode-matrix1' => ['text' =>'', 'icon' => 'system/dashboardModeTilesSmall', 'action' => 'viewer-mode-matrix1'],
			'viewer-mode-matrix2' => ['text' =>'', 'icon' => 'user/eye', 'action' => 'viewer-mode-live'],
			'viewer-mode-videoArchive' => ['text' =>'', 'icon' => 'system/dashboardModeCamera', 'action' => 'viewer-mode-videoArchive'],
		];

		$this->toolbar['rightTabs'] = $rt;
	}

	function createGridDefinition ()
	{

    //$this->createGridDefinitionSmart();

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

		/*
		if (count($this->iotSC) && $this->viewerMode !== 'videoArchive')
		{
			$c .= "<div style='position: absolute; width: 100%; z-index: 2000; display: flex; padding: 6px; background-color: rgba(0,0,0,.4); top: 0px; height: 3em;'>";
			foreach ($this->iotSC as $sc)
			{
				$c .= $sc['object']->controlCode();
			}
			$c .= "</div>";

			$camsStyle = " style='margin-top: 3em;'";
		}
		*/

		$c .= "<div class='container-fluid'$camsStyle>";
		$c .= $this->createGridCodeCell($this->gridDefinition, $usedLocalServers, $camIndex);
		$c .= "</div>";

		//$c .= "<input type='hidden' id='e10-widget-vs-type' value='{$this->viewerMode}'>";

		//$serversStr = json_encode($usedLocalServers);

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

		if (isset($cell['type']) && $cell['type'] === 'smart')
			return "<img id='e10-vs-smart-main-img' style='width: 100%;' src='$phUrl'>";

		$c .= "<div class='e10-vs-img' style='position: relative;'>";
		if ($this->viewerMode !== 'videoArchive')
		{ // pictures
			/*
			$pictureFolder = ($cam['cfg']['picturesFolder'] === '') ? $cam['ndx'] : $cam['cfg']['picturesFolder'];
			$class = 'e10-camp';
			$params = '';

			$class .= ' e10-widget-trigger';
			$params .= " data-call-function='e10.widgets.macVs.setMainPicture'";
			if($badgesBig !== '')
				$params .= " data-badges-code='".base64_encode($badgesBig)."'";
			*/
      $c .= "{{{@iotControl;type:camPicture;pictStyle:full;ndx:$cameraNdx}}}";

			//$c .= $badgesSmall;
		}
		else
		{ // video
			/*
			$baseFileName = $this->baseVideoFileName ($cam);
      $baseFileName="AHOJ.jpg";
			if ($baseFileName)
			{
				$c .= "<div class='e10-camv' id='e10-camv-{$cam['ndx']}' data-camera='{$cam['ndx']}' data-cam-url='{$srv['camerasURL']}' data-bfn='$baseFileName'>$baseFileName";
				$c .= '</div>';
			}
			*/
		}
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
			if ($this->viewerMode === 'videoArchive')
			{
        //error_log("_FIXED_");
				$this->panelStyle = self::psFixed;
				$this->loadArchiveContent();
				$this->addDayDateParam();
				$this->addDayHourParam();
			}

			$this->createGridCode();

			//error_log("__CC2__".$this->code);
			//error_log("__CC3__");

			//$this->code = json_encode($this->zone['cameras']).'<br/>'.$this->code;

			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
		}
	}

	public function createCodeInitJS()
	{
		if ($this->fullCode)
			return "\n<script>(() => {initWidgetVS ('{$this->widgetId}');})();</script>";

		return '';
	}

	public function loadArchiveContent ()
	{
		//$this->downloadVideoArchives();

		$doneServers = [];
		foreach ($this->cameras as $camNdx => $cam)
		{
			if (in_array($cam['localServer'], $doneServers))
				continue;

			$resultString = file_get_contents(__APP_DIR__.'/tmp/e10-vs-archive-'.$cam['localServer'].'.json');
			if (!$resultString)
				continue;
			$resultData = json_decode($resultString, TRUE);
			if (!$resultData)
				continue;
			$this->archiveContent = $resultData;
			$this->archiveContentByDate += $resultData['days'];
			$doneServers[] = $cam['localServer'];
		}

		$dayParam = $this->app->testGetParam('e10-widget-vs-day');

		if ($dayParam !== '')
		{
			$this->archiveNow = new \DateTime($dayParam . ' 07.00:00');
			$this->archiveNowHour = $this->app->testGetParam('e10-widget-vs-hour');
		}
		else
		{
			$this->archiveNow = new \DateTime('2 hour ago');
			$this->archiveNowHour = '';
		}

		$this->archiveNowDate = $this->archiveNow->format('Y-m-d');
		$this->archiveNowYear = intval($this->archiveNow->format('Y'));
		$this->archiveNowMonth = intval($this->archiveNow->format('m'));
		$this->archiveNowDay = intval($this->archiveNow->format('d'));

		$lastHour = '';
		$firstHour = '';
		foreach ($this->archiveContentByDate[$this->archiveNowDate] as $hourId => $hourCntFiles)
		{
			$this->archiveEnabledHours[] = $hourId;
			$lastHour = sprintf('%02d', $hourId).'-'.'45';
			if ($firstHour === '')
				$firstHour = sprintf('%02d', $hourId).'-'.'00';
		}
		if ($this->archiveNowHour === '')
			$this->archiveNowHour = $lastHour;

		if ($this->archiveNowHour < $firstHour)
			$this->archiveNowHour = $firstHour;
		elseif ($this->archiveNowHour > $lastHour)
			$this->archiveNowHour = $lastHour;
	}

	public function title()
	{
		return FALSE;
	}

	public function pageType()
	{
		return 'widget';
	}

	function addDayDateParam()
	{
		$enumDate = [];
		$paramTitle = 'Den';

		$paramId = 'e10-widget-vs-day';
		foreach ($this->archiveContentByDate as $dayKey => $dayHours)
		{
			$dayDate = new \DateTime($dayKey);
			$dayTitle = utils::datef ($dayDate, '%n %d');
			$enumDate[$dayKey] = $dayTitle;
		}

		$this->addParam ('switch', $paramId, ['defaultValue' => $this->archiveNowDate, 'title' => $paramTitle, 'switch' => $enumDate, 'XXXradioBtn' => 1,'place' => 'panel']);
	}

	function addDayHourParam ()
	{
		$paramId = 'e10-widget-vs-hour';
		$enumTime = [];

		$cntHourParts = 4;
		$cntHourPartMinutes = 15;

		for ($hr = 0; $hr < 24; $hr++)
		{
			if (!in_array($hr, $this->archiveEnabledHours))
				continue;


			for ($hourPart = 0; $hourPart < $cntHourParts; $hourPart++)
			{
				$firstMinute = $hourPart * $cntHourPartMinutes;
				$t = sprintf('%02d:%02d', $hr, $firstMinute);
				$pid = sprintf('%02d-%02d', $hr, $firstMinute);

				$enumTime[$pid] = $t;
			}
		}
		$this->addParam ('switch', $paramId, ['defaultValue' => $this->archiveNowHour, 'title' => 'Čas', 'switch' => $enumTime, 'radioBtn' => 2, 'place' => 'panel']);
	}

	public function downloadVideoArchives ()
	{
		$cameras = $this->app->cfgItem('e10.terminals.cameras');
		$servers = $this->app->cfgItem('e10.terminals.servers');
		$doneServers = [];

		foreach ($cameras as $camNdx => $cam)
		{
			if (in_array($cam['localServer'], $doneServers))
				continue;

			$srv = $servers[$cam['localServer']];
			$url = $srv['camerasURL'].'archive';

			$opts = ['http'=> ['timeout' => 10, 'method'=>'GET', 'header'=> "Connection: close\r\n"]];
			$context = stream_context_create($opts);
			$resultString = file_get_contents ($url, FALSE, $context);
			if (!$resultString)
				continue;
			$resultData = json_decode ($resultString, TRUE);
			if (!$resultData)
				continue;

			file_put_contents(__APP_DIR__.'/tmp/e10-vs-archive-'.$cam['localServer'].'.json', $resultString);

			$doneServers[] = $cam['localServer'];
		}
	}

	function createEnumParamCode ($p)
	{
		$paramId = $p['paramId'] ?? '';
		$activeValue = '';
		if (isset($p['defaultValue']))
			$activeValue = $p['defaultValue'];

		$c = '';

		$justified = isset($p['justified']) ? intval($p['justified']) : 0;
		$grpClass = 'btn-group e10-param-inline';
		if ($justified)
			$grpClass .= ' btn-group-justified';

		$c .= "<div class='$grpClass' data-paramid='$paramId'>";
		if (isset ($p['title']))
			$c .= "<span class='btn btn-default'><b>" . Utils::es($p['title']) . ':</b></span>';
		$first = TRUE;
		forEach ($p['enum'] as $pid => $pc)
		{
			$t = is_string($pc['title']) ? Utils::es($pc['title']) : Utils::es($pc['title']['text']);

			$class = ($pid == $activeValue) ? 'active ': '';
			$class .= 'btn btn-default df2-action-trigger';

			if ($justified)
				$c .= "<div class='btn-group' role='group'>";
			$c .= "<button data-value='$pid' data-title='$t' class='$class'";

			if (isset($pc['data']))
			{
				foreach ($pc['data'] as $btnPartId => $btnPartValue)
					$c .= ' data-'.$btnPartId."='".$btnPartValue."'";
			}

			$c .= '>' . $this->app()->ui()->composeTextLine($pc['title']);
			$c .= '</button>';
			if ($justified)
				$c .= '</div>';
		}
		$c .= '</div>';

		return $c;
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

		//$c .= "<div class='$tabsClass'>";

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

					//if (isset($tab['action']))
					//	$c .= "<li class='tab e10-widget-trigger{$active}' data-action='{$tab['action']}' data-tabid='{$tabId}'$tabParams>";
					//else
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

//					if (isset($tab['ntfBadgeId']))
//						$c .= "<span class='e10-ntf-badge' id='{$tab['ntfBadgeId']}' style='display:none; left: auto;'></span>";

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

  public function createContentInside__DELETE ()
	{
		//$cr = new \Shipard\UI\Core\ContentRenderer ($this->app);
		//$cr->setWidget($this);

		$c = '';
		return $c;
		if (1)
		{
      $c .= "<div class='d-flex'>";
      //$c .= "<div class='container-fluid'>";
			$c .= $this->renderContentTitle();
      $c .= '</div>';

			$c .= "<div class='e10-widget-content e10-widget-board e10-widget-" . $this->widgetType() . "'>";
				$c .= "<div class='e10-wr-data'>";
				//$c .= $cr->createCode();
				$c .= "</div>";

				if ($this->panelStyle != self::psNone)
				{
					if ($this->panelStyle === self::psFloat)
					{
						$c .= "<div class='e10-wr-params close'>";
						$c .= "<div class='tlbr e10-reportPanel-toggle'><i class='fa fa-bars'></i></div>";
					}
					else
						$c .= "<div class='e10-wr-params {$this->panelWidth} fixed'>";
					$c .= "<div class='params' id='e10-widget-panel'>";
					//$c .= $this->createPanelCode();
					$c .= "</div>";
					$c .= "</div>";
				}
			$c .= '</div>';

      //$c .= '</div>';
		}


		//$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
		//else
		//	$c .= $cr->createCode();

		return $c;
	}

  public function renderContentXXX ($forceFullCode = FALSE)
	{
		$fullCode = 0; //intval($this->app->testGetParam('fullCode'));
		if ($forceFullCode)
			$fullCode = 1;
		if ($this->forceFullCode)
			$fullCode = 1;

		$c = '';

		if (1 || $fullCode)
		{
			$params = "data-object='widget' data-request-type='widgetBoard' data-class-id='{$this->definition['class']}'";
			$pv = [];
			forEach ($this->params->getParams() as $paramId => $paramContent)
			{
				$pv [$paramId] = $paramId.'='.$this->reportParams [$paramId]['value'];
			}
			$params .= " data-widget-params='".implode('&', $pv)."'";

			if ($this->app()->remote !== '')
				$params .= " data-remote='".$this->app()->remote."'";

			foreach ($this->widgetSystemParams as $wspId => $wspValue)
				$params .= " $wspId='$wspValue'";

			$c .= "<div id='{$this->widgetId}' class='{$this->widgetMainClass} e10-widget-".$this->widgetType()."' $params>";
		}

		$c .= $this->createContentInside();

		if ($fullCode)
			$c .= '</div>';

		//if ($fullCode)
		//	$c .= "\n<script>(() => {initWidgetVS ('{$this->widgetId}');})();</script>";

		return $c;
	}

  protected function uiTemplate()
  {
    //$this->template = new \Shipard\UI\ng\TemplateUI ($this->app());

  }

	public function prepareData()
	{
	}
}


