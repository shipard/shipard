<?php

namespace terminals\vs;


use e10\utils;


/**
 * Class WidgetLive
 * @package terminals\vs
 */
class WidgetLive extends \E10\widgetPane
{
	var $code = '';
	var $gridType;
	var $liveMode = '';

	var $cameras;
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

	protected function composeCode ()
	{
		$this->composeCodeTitle();

		if ($this->gridType === 'live')
		{
			if ($this->liveMode === 'grid')
				$this->composeCodeGrid('live');
			else
				$this->composeCodeSmart('live');
		}
		else
			$this->composeCodeGrid('archive');
	}

	public function composeCodeTitle ()
	{
		$c = '';

		$c .= "<input type='hidden' id='e10-widget-vs-type' value='{$this->gridType}'>";
		$c .= "<input type='hidden' id='e10-widget-vs-mode' value='{$this->liveMode}'>";
		$c .= "<div class='e10-wf-tabs'>";
		$c .= "<ul class='e10-wf-tabs'>";

		if ($this->gridType === 'live')
		{
			$active = ($this->liveMode === 'smart') ? ' active' : '';
			$c .= "<li class='tab e10-widget-trigger{$active}' data-action='load-live-smart'><i class='fa fa-th-large'></i>&nbsp;Živě</li>";
			$active = ($this->liveMode === 'grid') ? ' active' : '';
			$c .= "<li class='tab e10-widget-trigger{$active}' data-action='load-live-grid'><i class='fa fa-th'></i>&nbsp;Živě</li>";
			$c .= "<li class='tab e10-widget-trigger' data-action='load-archive'><i class='fa fa-hdd-o'></i>&nbsp;Archív</li>";
		}
		else
		{
			$c .= "<li class='tab e10-widget-trigger' data-action='load-live-smart'><i class='fa fa-th-large'></i>&nbsp;Živě</li>";
			$c .= "<li class='tab e10-widget-trigger' data-action='load-live-grid'><i class='fa fa-th'></i>&nbsp;Živě</li>";
			$c .= "<li class='tab active e10-widget-trigger' data-action='load-archive'>Archív</li>";

			$c .= "<li>" . $this->createCalendarDayParamCode ([]) . '</li>';
			$c .= "<li>" . $this->createDayHourParamCode([]) . '</li>';

/*
			$c .= "<li><i class='fa fa-play-circle fa-3x'></i></li>";

			$timeCode = $this->archiveNow->format ('H.00:00');
			$c .= "<li id='e10-wodget-vs-timecode'>$timeCode</li>";

			$c .= "<li><i class='fa fa-fast-backward fa-2x'></i></li>";
			$c .= "<li><i class='fa fa-fast-forward fa-2x'></i></li>";
*/

		}
		$c .= '</ul>';
		$c .= '</div>';


		$this->code .= $c;
	}

	public function composeCodeLive ()
	{
		$this->composeCode('live');
	}

	public function composeCodeArchive ()
	{
		$this->composeCode('archive');
	}

	public function composeCodeGrid ($type)
	{
		$c = '';

		$cntCams = count($this->cameras);

		$withImg = 3;
		if ($cntCams === 1)
			$withImg = 12;
		elseif ($cntCams === 2)
			$withImg = 6;
		elseif ($cntCams === 3)
			$withImg = 4;
		elseif ($cntCams === 4)
			$withImg = 6;
		elseif ($cntCams < 8)
			$withImg = 4;

		if ($this->app->mobileMode)
			$withImg = 6;
		if ($this->app->mobileMode && $type === 'archive')
			$withImg = 12;

		$c .= "<div class='e10-gs-row thin'>";
		$colIdx = 0;
		$usedLocalServers = [];
		foreach ($this->cameras as $cam)
		{
			$srvNdx = $cam['localServer'];
			if (!isset($usedLocalServers[$srvNdx]))
				$usedLocalServers[$srvNdx] = $this->servers[$srvNdx];
			$elementClass = '';
			if ($colIdx % (12 / $withImg) === 0)
				$elementClass = ' clear';
			$c .= "<div class='e10-gs-col e10-gs-col{$withImg}{$elementClass}'>";
			$c .= $this->gridImgElement ($type, $cam);
			$c .= '</div>';

			$colIdx++;
		}

		$c .= '</div>';

		$serversStr = json_encode($usedLocalServers);
		$c .= "<script>e10.widgets.vs.init('{$this->widgetId}', {$serversStr});</script>";

		$this->code .= $c;
	}

	public function composeCodeSmart ($type)
	{
		$phUrl = $this->app->urlRoot.'/e10-modules/e10/server/css/ph-image-1920-1080.svg';
		$c = '';
		$cntBreakpoint = ($this->app->mobileMode) ? 4 : 6;


		$c .= "<div class='e10-gs-row thin'>";

			$cam = reset ($this->cameras);
			$c .= "<div class='e10-gs-col e10-gs-col7 e10-wvs-smart-main-box e10-widget-trigger' id='e10-vs-smart-main' data-active-cam='{$cam['ndx']}' data-call-function='e10.widgets.vs.zoomMainPicture'>";
			$c .= "<img id='e10-vs-smart-main-img' style='width: 100%;' src='$phUrl'>";
			$c .= "</div>";

			$i = 0;
			$c .= "<div class='e10-gs-col e10-gs-col5 e10-wvs-smart-primary-box'>";

			$imgWidth = 6;
			$c .= "<div class='e10-gs-row full'>";
			$usedLocalServers = [];
			foreach ($this->cameras as $cam)
			{
				$srvNdx = $cam['localServer'];
				if (!isset($usedLocalServers[$srvNdx]))
					$usedLocalServers[$srvNdx] = $this->servers[$srvNdx];

				$c .= "<div class='e10-gs-col e10-gs-col{$imgWidth}'>";
				$c .= $this->gridImgElement ($type, $cam, 'smart');
				$c .= '</div>';
				$i++;

				if ($i === $cntBreakpoint)
				{
					$c .= '</div>';
					$c .= '</div>';
					$c .= '</div>';
					$c .= "<div class='e10-gs-row thin e10-wvs-smart-secondary-box'>";
					$imgWidth = 2;
				}
			}
			if ($i < $cntBreakpoint)
				$c .= '</div>';

		$c .= '</div>';

		$c .= '</div>';

		$serversStr = json_encode($usedLocalServers);
		$c .= "<script>e10.widgets.vs.init('{$this->widgetId}', {$serversStr});</script>";

		$this->code .= $c;
	}

	protected function gridImgElement ($type, $cam)
	{
		$phUrl = $this->app->urlRoot.'/e10-modules/e10/server/css/ph-image-1920-1080.svg';

		$srv = $this->servers[$cam['localServer']];

		$c = '';

		$c .= "<div class='e10-vs-img'>";
		//$c .= "<span>".utils::es($cam['name']).'</span>';
		if ($type === 'live')
		{
			$class = 'e10-camp';
			$params = '';
			if ($this->liveMode === 'smart')
			{
				$class .= ' e10-widget-trigger';
				$params = " data-call-function='e10.widgets.vs.setMainPicture'";
			}
			elseif ($this->liveMode === 'grid')
			{
				$class .= ' e10-widget-trigger';
				$params = " data-call-function='e10.widgets.vs.zoomPicture'";
			}
			$c .= "<img class='$class' src='$phUrl' id='e10-camp-{$cam['ndx']}' data-camera='{$cam['ndx']}' data-cam-url='{$srv['camerasURL']}' style='width:100%;' $params>";
		}
		else
		{ // video
			$baseFileName = $this->baseVideoFileName ($cam);

			if ($baseFileName)
				$c .= "<div class='e10-camv' id='e10-camv-{$cam['ndx']}' data-camera='{$cam['ndx']}' data-cam-url='{$srv['camerasURL']}' data-bfn='$baseFileName'></div>";
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
		$this->cameras = $this->app->cfgItem('e10.terminals.cameras');
		$this->servers = $this->app->cfgItem('e10.terminals.servers');

		$this->loadArchiveContent();

		$this->gridType = ($this->widgetAction === 'load-archive') ? 'archive' : 'live';
		if ($this->app->testGetParam('e10-widget-vs-day') !== '' && $this->widgetAction === 'undefined')
			$this->gridType = 'archive';

		if ($this->gridType === 'live')
			$this->liveMode = (strlen($this->widgetAction) > 10) ? substr($this->widgetAction, 10) : 'smart';

		$this->composeCode();
		$this->addContent (['type' => 'text', 'subtype' => 'rawhtml', 'text' => $this->code]);
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

	public function XXXsetDefinition ($d)
	{
		$this->definition = ['class' => 'e10-widget-terminal', 'type' => 'terminal'];
	}

	public function XXXfullScreen()
	{
		return 1;
	}

	public function pageType()
	{
		return 'widget';
	}

	function createCalendarDayParamCode ($data)
	{
		$calendar = new \lib\wkf\Calendar($this->app);
		$calendar->init();
		$calendar->today = $this->archiveNow;

		$paramId = 'e10-widget-vs-day';
		$activeTitle = $calendar->today->format ('d').'. '.utils::$monthNamesForDate[intval($calendar->today->format ('m')) - 1];
		$activeValue = $calendar->today->format ('Y-m-d');
		$title = 'Datum';

		$inputClass = '';
		$c = '';
		$c .= "<div class='btn-group e10-param' data-paramid='$paramId' style='color: #111;'>";
		$c .= "<button type='button' class='btn btn-default dropdown-toggle e10-report-param' data-toggle='dropdown'>".
				'<b>'.utils::es ($title).":</b> <span class='v'>".utils::es($activeTitle).'</span>'.
				" <span class='caret'></span>".
				'</button>';
		$c .= "<input name='$paramId' id='$paramId' type='hidden'$inputClass value='$activeValue'>";
		$c .= "<div class='dropdown-menu' role='menu'>";

		$c .= "<div>";

		$calCode = '';
		$enabledDays = [];
		foreach ($this->archiveContentByDate as $dayKey => $dayHours)
		{
			$dayDate = new \DateTime($dayKey);
			$dayDay = intval($dayDate->format ('d'));
			$dayMonth = intval($dayDate->format ('m'));
			$dayYear = intval($dayDate->format ('Y'));
			$enabledDays[$dayYear][$dayMonth][] = $dayDay;
		}

		foreach ($enabledDays as $yearNumber => $yearMonths)
		{
			foreach ($yearMonths as $monthNumber => $monthDays)
			{
				$ed = [$monthNumber => $monthDays];
				$calCode = '<h4 class="text-center padd5" style="text-align: center;">' . utils::$monthNames[$monthNumber - 1] . ' ' . $yearNumber . '</h4>' .
					$calendar->renderMonth(intval($yearNumber), intval($monthNumber), 'small', ['enabledDays' => $ed, 'headerWithDays' => TRUE, 'disableMonthName' => TRUE]) .
					'<br/>' .
					$calCode;
			}
		}

		$c .= $calCode;

		$c .= "</div>";

		$c .= '</div></div> ';

		return $c;
	}

	function createDayHourParamCode ($data)
	{
		$paramId = 'e10-widget-vs-hour';

		$inputClass = '';
		$activeValue = $this->archiveNowHour;
		$activeTitle = str_replace('-', ':', $this->archiveNowHour);
		$title = 'Hodina';

		$c = '';
		$c .= "<div class='btn-group e10-param' data-paramid='$paramId'>";
		$c .= "<button type='button' class='btn btn-default dropdown-toggle e10-report-param' data-toggle='dropdown'>".
				'<b>'.utils::es ($title).":</b> <span class='v'>".utils::es($activeTitle).'</span>'.
				" <span class='caret'></span>".
				'</button>';
		$c .= "<input name='$paramId' id='$paramId' type='hidden'$inputClass value='$activeValue' data-call-function-none='e10.widgets.vs.setHour'>";
		$c .= "<div class='dropdown-menu' role='menu'>";


		$c .= "<div><table class='e10-cal-small'>";

		$cntHourParts = 4;
		$cntHourPartMinutes = 15;

		for ($hr = 0; $hr < 24; $hr++)
		{
			if (!in_array($hr, $this->archiveEnabledHours))
				continue;

			$c .= '<tr>';

			for ($hourPart = 0; $hourPart < $cntHourParts; $hourPart++)
			{
				$firstMinute = $hourPart * $cntHourPartMinutes;
				$t = sprintf('%02d:%02d', $hr, $firstMinute);
				$pid = sprintf('%02d-%02d', $hr, $firstMinute);

				$class = ($pid === $activeValue) ? 'active ' : '';
				$class .= 'e10-param-btn hour';

				$c .= "<td data-value='$pid' data-title='$t' class='$class'><span class='padd5'>" . utils::es($t) . '</span></td>';
			}
			$c .= '</tr>';
		}

		$c .= "</table></div>";
		$c .= '</div></div> ';

		return $c;
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

}
