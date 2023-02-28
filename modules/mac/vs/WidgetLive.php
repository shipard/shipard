<?php

namespace mac\vs;

use e10\utils, \Shipard\UI\Core\WidgetBoard, \mac\data\libs\SensorHelper;


/**
 * Class WidgetLive
 * @package mac\vs
 */
class WidgetLive extends WidgetBoard
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

	/** @var  \e10\DbTable */
	var $tableZones;
	var $usersZones;

	var $iotSC = [];
	var $iotScenes = [];

	public function init ()
	{
		$this->forceFullCode = 1;

		$panelId = $this->app->testGetParam('widgetPanelId');
		$parts = explode('-', $panelId);
		if (count($parts) === 2 && $parts[0] === 'zone')
			$this->zoneNdx = intval($parts[1]);

		if (!$this->zoneNdx)
		{
			$parts = explode ('-', $this->app->testGetParam('e10-widget-topTab'));
			if (isset($parts['2']))
				$this->zoneNdx = intval($parts['2']);
		}

		if (!$this->zoneNdx)
		{
			$panelId = $this->app->requestPath(3);
			$parts = explode('-', $panelId);
			if (count($parts) === 2 && $parts[0] === 'zone')
				$this->zoneNdx = intval($parts[1]);
		}

		if (!$this->zoneNdx)
		{
			$parts = explode ('-', $this->app->testGetParam('subtype'));
			if (isset($parts['1']))
				$this->zoneNdx = intval($parts['1']);
		}

		if (!$this->zoneNdx)
			$this->zoneNdx = 1;

		$this->tableZones = $this->app->table ('mac.base.zones');
		$this->usersZones = $this->tableZones->usersZones('vs-sub', $this->zoneNdx);

		$this->servers = $this->app->cfgItem('mac.localServers');

		$this->createTabs();

		parent::init();

		$this->viewerMode = 'smart';
		$vmp = explode ('-', $this->activeTopTabRight);
		if (isset($vmp[2]))
			$this->viewerMode = $vmp[2];
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
			'viewer-mode-smart' => ['text' =>'', 'icon' => 'system/dashboardModeRows', 'action' => 'viewer-mode-smart'],
			'viewer-mode-matrix1' => ['text' =>'', 'icon' => 'system/dashboardModeTilesSmall', 'action' => 'viewer-mode-matrix1'],
			'viewer-mode-matrix2' => ['text' =>'', 'icon' => 'system/dashboardModeTilesBig', 'action' => 'viewer-mode-matrix2'],
			'viewer-mode-videoArchive' => ['text' =>'', 'icon' => 'system/dashboardModeCamera', 'action' => 'viewer-mode-videoArchive'],
		];

		$this->toolbar['rightTabs'] = $rt;
	}

	function createGridDefinition ()
	{
		if ($this->viewerMode === 'matrix1')
			$this->createGridDefinitionMatrix(1);
		elseif ($this->viewerMode === 'matrix2')
			$this->createGridDefinitionMatrix(2);
		elseif ($this->viewerMode === 'smart')
			$this->createGridDefinitionSmart();
		else
			$this->createGridDefinitionMatrix(2);
	}

	function createGridDefinitionMatrix ($matrixSize)
	{
		$this->gridDefinition = ['rows' => []];

		$cntCameras = count($this->cameras);

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

		$c .= "<div class='e10-fx-borders'$camsStyle>";
		$c .= $this->createGridCodeCell($this->gridDefinition, $usedLocalServers, $camIndex);
		$c .= "</div>";

		$c .= "<input type='hidden' id='e10-widget-vs-type' value='{$this->viewerMode}'>";

		$serversStr = json_encode($usedLocalServers);
		//$c .= "<script>e10.widgets.macVs.init('{$this->widgetId}', {$serversStr});</script>";
		$c .= "<script> $(function () {e10.widgets.macVs.init('{$this->widgetId}', {$serversStr});});</script>";

		$this->code .= $c;
	}

	function createGridCodeCell($gridCell, &$usedLocalServers, &$camIndex)
	{
		$cntCameras = count($this->cameras);
		$c = '';

		foreach ($gridCell['rows'] as $row)
		{
			$c .= "<div class='e10-fx-row e10-fx-sm-wrap'>";
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

				$c .= "<div class='e10-fx-col e10-fx-sm-fw e10-fx-{$cell['width']}{$cellClass}'{$cellParams} style='position: relative; justify-content: flex-start;'>";

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
			$c .= "</div>";
		}

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
			$pictureFolder = ($cam['cfg']['picturesFolder'] === '') ? $cam['ndx'] : $cam['cfg']['picturesFolder'];
			$class = 'e10-camp';
			$params = '';

			$class .= ' e10-widget-trigger';
			$params .= " data-call-function='e10.widgets.macVs.setMainPicture'";
			if($badgesBig !== '')
				$params .= " data-badges-code='".base64_encode($badgesBig)."'";
			$c .= "<img class='$class' src='$phUrl' id='e10-camp-{$cameraNdx}' data-camera='{$cameraNdx}' data-folder='$pictureFolder' data-cam-url='{$srv['camerasURL']}' style='width:100%;' $params>";
			$c .= $badgesSmall;
		}
		else
		{ // video
			$baseFileName = $this->baseVideoFileName ($cam);

			if ($baseFileName)
			{
				$c .= "<div class='e10-camv' id='e10-camv-{$cam['ndx']}' data-camera='{$cam['ndx']}' data-cam-url='{$srv['camerasURL']}' data-bfn='$baseFileName'>$baseFileName";
				$c .= '</div>';
			}
		}
		$c .= '</div>';

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
		$this->panelStyle = self::psNone;

		$parts = explode ('-', $this->activeTopTab);
		$activeSubZone = intval($parts['1']);

		$allCameras = $this->app->cfgItem('mac.cameras');
		$this->zone = $this->app()->cfgItem('mac.base.zones.'.$activeSubZone, NULL);
		if ($this->zone)
		{
			foreach ($this->zone['cameras'] as $zoneCamNdx)
			{
				$this->cameras[$zoneCamNdx] = $allCameras[$zoneCamNdx];
			}
		}

		$this->createContent_Toolbar ();
		$this->createGridDefinition();
		$this->loadSensors();

		if (substr ($this->activeTopTab, 0, 8) === 'subzone-')
		{

			if ($this->viewerMode === 'videoArchive')
			{
				$this->panelStyle = self::psFixed;
				$this->loadArchiveContent();
				$this->addDayDateParam();
				$this->addDayHourParam();
			}

			$this->createGridCode();
			$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
		}
	}

	public function createContent_Toolbar()
	{
		$this->loadScenes();
		$this->loadIoTSC();
		$c = '';

		$c .= "<div class='padd5' style='display: inline-block; width: 100%;'>";

		$c .= "<span class='_pull-right'>";
		foreach ($this->iotScenes as $setupNdx => $setupCfg)
		{
			$c .= $this->createEnumParamCode ($setupCfg);

			if (count($setupCfg['controls']))
			{
				foreach ($setupCfg['controls'] as $cc)
				{
					$c .= $cc;
				}
				$c .= "<span class='pr1'>&nbsp;<span>";
			}
		}
		$c .= '</span>';

		if (count($this->iotSC))
		{
			$c .= "<span class='pl1'>";
			foreach ($this->iotSC as $sc)
			{
				if ($sc['type'] === 1)
					$c .= $sc['code'];
			}
			$c .= '</span>';

			$c .= "<span class='pull-right'>";
			foreach ($this->iotSC as $sc)
			{
				if ($sc['type'] === 0)
					$c .= $sc['code'];
			}
			$c .= '</span>';
		}

		$c .= '</div>';

		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $c]);
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

	public function setDefinition ($d)
	{
		$this->definition = ['class' => 'mac.vs.WidgetLive', 'type' => 'wkfWall e10-widget-dashboard'];
	}

	function loadSensors()
	{
		$q [] = 'SELECT sensorsToShow.*';
		array_push ($q, ' FROM [mac_lan_devicesSensorsShow] AS sensorsToShow');
		array_push ($q, ' LEFT JOIN [mac_iot_sensors] AS sensors ON sensorsToShow.sensor = sensors.ndx');
		array_push ($q, ' LEFT JOIN [mac_lan_devices] AS devices ON sensorsToShow.device = devices.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND sensorsToShow.[device] IN %in', $this->zone['cameras']);
		array_push ($q, ' ORDER BY sensorsToShow.[rowOrder]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$placeId = $r['camPosH'].'-'.$r['camPosV'];

			$sh = new SensorHelper($this->app());
			$sh->setSensor($r['sensor']);
			$sensorCode = $sh->badgeCode(1);

			if (!isset($this->sensors[$r['device']][$placeId]))
			{
				$this->sensors[$r['device']][$placeId] = ['camPosH' => $r['camPosH'], 'camPosV' => $r['camPosV'], 'sensors' => []];
			}

			$sensor = ['info' => $r->toArray(), 'code' => $sensorCode];
			$this->sensors[$r['device']][$placeId]['sensors'][] = $sensor;
		}
	}

	function loadIoTSC()
	{
		if (!$this->zone)
			return;

		$q [] = 'SELECT iotSC.* ';
		array_push ($q, ' FROM [mac_base_zonesIoTSC] AS iotSC');
		array_push ($q, ' WHERE 1');
		if (isset($this->zone['oz']) )
			array_push ($q, ' AND (iotSC.[zone] = %i', $this->zone['ndx'], ' OR iotSC.[zone] = %i', $this->zone['oz'], ')');
		else
			array_push ($q, ' AND iotSC.[zone] = %i', $this->zone['ndx']);
		array_push ($q, ' ORDER BY iotSC.[rowOrder]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($r['rowType'] === 0)
			{ // sensor
				$sh = new SensorHelper($this->app());
				$sh->setSensor($r['iotSensor']);
				$sensorCode = $sh->badgeCode(1);
				$sc = "<span class='padd5'>".$sensorCode.'</span]>';
				$this->iotSC[] = ['type' => 0, 'code' => $sc];
			}
			elseif ($r['rowType'] === 1)
			{ // control
				$control = new \mac\iot\libs\Control($this->app());
				$control->setControl($r['iotControl']);

				if ($control->controlRecData['iotSetup'])
				{
					$this->iotScenes[$control->controlRecData['iotSetup']]['controls'][] = $control->controlCode();
				}
				else
					$this->iotSC[] = ['type' => 1, 'object' => $control, 'code' => $control->controlCode()];
			}
			elseif ($r['rowType'] === 2)
			{ // setup
				$this->iotSC[] = ['type' => 2];
			}
		}
	}

	function loadScenes()
	{
		// -- setups
		$setups = [];
		$setupsOrders = [];
		$q [] = 'SELECT iotSC.*';
		array_push($q, ' FROM [mac_base_zonesIoTSC] AS iotSC');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND iotSC.rowType = %i', 2);
		if (isset($this->zone['oz']) )
			array_push($q, ' AND (iotSC.[zone] = %i', $this->zone['ndx'], ' OR iotSC.[zone] = %i', $this->zone['oz'], ')');
		else
			array_push($q, ' AND iotSC.[zone] = %i', $this->zone['ndx']);

		array_push($q, ' ORDER BY iotSC.[rowOrder]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$setups[] = $r['iotSetup'];
			$setupsOrders[$r['iotSetup']] = $r['rowOrder'];
		}
		// -- scenes
		if (!count($setups))
			return;

		$q = [];
		array_push($q, 'SELECT scenes.*, setups.fullName AS setupFullName, setups.shortName AS setupShortName');
		array_push($q, ' FROM mac_iot_scenes AS scenes');
		array_push($q, ' LEFT JOIN mac_iot_setups AS setups ON scenes.setup = setups.ndx');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND setup IN %in', $setups);
		array_push($q, ' ORDER BY [scenes].[order]');

		$scenes = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$setupNdx = $r['setup'];
			$sceneNdx = $r['ndx'];
			if (!isset($scenes[$setupNdx]))
			{
				$scenes[$setupNdx] = [
					'type' => 'scene',
					'order' => $setupsOrders[$r['setup']],
					'title' => $r['setupShortName'],
					'paramId' => "set_scene_{$setupNdx}_{$r['ndx']}",
					'enum' => [],
					'controls' => [],
				];
				$activeScene = $this->db()->query('SELECT * FROM [mac_iot_setupsStates] WHERE [setup] = %i', $setupNdx)->fetch();
				if ($activeScene)
					$scenes[$setupNdx]['activeScene'] = $activeScene['activeScene'];
			}

			$btn = [
				'title' => $r['shortName'],
				'data' => [
					'action' => 'inline-action',
					'object-class-id' => 'mac.iot.libs.IotAction',
					'action-param-action-type' => 'set-scene',
					'action-param-setup' => strval($setupNdx),
					'action-param-scene' => strval($r['ndx']),
				],
			];

			$scenes[$setupNdx]['enum'][$r['friendlyId']] = $btn;

			if (!isset($scenes[$setupNdx]['defaultValue']) && $scenes[$setupNdx]['activeScene'] === $sceneNdx)
				$scenes[$setupNdx]['defaultValue'] = $r['friendlyId'];
		}

		$this->iotScenes = $scenes;
	}

	function createEnumParamCode ($p)
	{
		$paramId = $p['paramId'];
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
}
