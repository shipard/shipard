<?php

namespace E10Pro\Hosting\Services;

require_once __APP_DIR__ . '/e10-modules/e10/web/web.php';

use \E10\utils, E10\Utility, E10\Response;


/**
 * Class LoginScreenFeed
 * @package E10Pro\Hosting\Services
 */
class LoginScreenFeed extends Utility
{
	protected $today = FALSE;
	protected $testMode = FALSE;
	protected $todayYear;
	protected $todayMonth;
	protected $todayDay;
	protected $todayDow;

	var $object = [];

	protected function pictureOfTheDay ($createRandom)
	{
		$podDir = __APP_DIR__.'/includes/pod';
		if (!is_dir($podDir))
			mkdir($podDir);

		$podFileName = $podDir.'/'.$this->today->format ('Y-m-d').'.jpeg';
		if (!is_file($podFileName))
		{
			$cmd = "wget https://source.unsplash.com/1920x1080/daily?landscape -O $podFileName";
			shell_exec($cmd);
		}

		$pp = $this->app->urlProtocol.$_SERVER['HTTP_HOST'].$this->app->dsRoot;
		$pp .= '/includes/pod/'.$this->today->format ('Y-m-d').'.jpeg';

		$this->object['backgroundPicture'] = $pp;
	}

	protected function calendarInfo ()
	{
		$q[] = 'SELECT cal.* FROM [e10pro_hosting_services_cal] AS [cal]';

		array_push($q, ' WHERE 1');

		if (!$this->testMode)
			array_push($q, ' AND docStateMain = %i', 2);

		array_push($q, ' AND ([yearFrom] = 0 OR [yearFrom] <= %i)', $this->todayYear);
		array_push($q, ' AND ([yearTo] = 0 OR [yearTo] >= %i)', $this->todayYear);
		array_push($q, ' AND ([month] = 0 OR [month] = %i)', $this->todayMonth);
		array_push($q, ' AND ([day] = 0 OR [day] = %i)', $this->todayDay);

		array_push($q, ' LIMIT 6');

		$eventTypes = $this->app->cfgItem('hosting.services.calendar.eventTypes');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$eventType = $eventTypes[$r['type']];
			$this->object['dayInfo'][$eventType['id']][]['title'] = $r['title'];
		}
	}

	protected function todayInfo ()
	{
		$this->object['dayInfo']['date'] = utils::$dayShortcuts[$this->todayDow] . ' ' . $this->today->format (' j. ').utils::$monthNamesForDate[$this->todayMonth - 1];
	}

	protected function news ()
	{
		$news = '';

		$texy = new \e10\web\E10Texy($this->app);
		$texy->headingModule->top = 3;
		$texy->externalLinks = TRUE;

		$q[] = 'SELECT news.*, ';
		array_push($q, 'attPerexIllustrations.path AS perexIllustrationPath, attPerexIllustrations.fileName AS perexIllustrationFileName');
		array_push($q, ' FROM [e10_web_news] as news');
		array_push($q, ' LEFT JOIN e10_attachments_files AS attPerexIllustrations ON news.perexIllustration = attPerexIllustrations.ndx');

		array_push($q, ' WHERE (([date_from] IS NULL OR [date_from] <= %d) AND ([date_to] IS NULL OR [date_to] >= %d))', $this->today, $this->today);
		array_push($q, ' AND news.[docStateMain] = 2');
		array_push ($q, ' ORDER BY [to_top] DESC, [order], [ndx] DESC', ' LIMIT 0, 6');

		$rows = $this->db()->query($q);
		forEach ($rows as $r)
		{
			$news .= "<div class='item'>";
			$news .= '<h3>'.utils::es($r['title']).'</h3>';

			$perex = $texy->process($r['perex']);
			$news .= $perex;

			if ($r['url'] !== '')
			{
				$news .= "<a href='{$r['url']}' class='btn btn-info pull-right' target='new'><i class='fa fa-external-link'></i> ".utils::es('Přečíst celý článek').'</a><br><br>';
			}

			$news .= '</div>';
		}

		$this->object['news'] = $news;
	}

	public function init ()
	{
		if ($this->app->testGetParam('date') !== '')
		{
			$date = \DateTime::createFromFormat('Y-m-d', $this->app->testGetParam('date'));
			if ($date && $date->format('Y-m-d') === $this->app->testGetParam('date'))
			{
				$this->today = $date;
				$this->testMode = TRUE;
			}
		}
		if ($this->today === FALSE)
			$this->today = utils::today();
		$this->todayYear = intval($this->today->format('Y'));
		$this->todayMonth = intval($this->today->format('m'));
		$this->todayDay = intval($this->today->format('d'));
		$this->todayDow = intval($this->today->format('N')) - 1;
	}

	public function run ()
	{
		$this->object['dayInfo'] = [];

		$this->pictureOfTheDay(TRUE);
		$this->calendarInfo();
		$this->todayInfo();
		$this->news();

		$response = new Response ($this->app);
		$response->add ('objectType', 'loginScreen');
		$response->add ("object", $this->object);
		return $response;
	}
}
