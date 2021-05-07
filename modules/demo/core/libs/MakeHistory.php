<?php

namespace demo\core\libs;

use \e10\Utility, \e10\utils;


/**
 * Class MakeHistory
 */
class MakeHistory extends Utility
{
	var $maxDays = 10;
	var $dateBegin = '';
	var $todayDate = '';
	var $nowStr;

	function today ($format = '', $app = NULL)
	{
		$today = NULL;
		if ($today === NULL)
			$today = new \DateTime($this->todayDate);
		$today->setTime (0,0);
		if ($format !== '')
			return $today->format($format);
		return $today;
	}

	function now($format = '')
	{
		$now = new \DateTime($this->nowStr);
		if ($format !== '')
			return $now->format($format);
		return $now;
	}

	public function init()
	{
		$maxRec = $this->db()->query('SELECT MAX([date]) AS maxDate FROM [demo_core_tasks]')->fetch();
		if ($maxRec && isset($maxRec['maxDate']))
			$this->dateBegin = $maxRec['maxDate']->format ('Y-m-d');


		$dateBeginParam = $this->app()->arg('date-begin');
		if ($dateBeginParam !== FALSE)
		{
			if ($this->dateBegin !== '')
			{
				echo "! some data exist, --date-begin param was ignored\n";
			}
			else
			{
				try {
					$db = new \DateTime($dateBeginParam);
				}
				catch (\Exception $e)
				{
					echo "! invalid --date-begin value: ";
					echo $e->getMessage()."\n";
					exit(1);
				}

				if ($db)
					$this->dateBegin = $db->format ('Y-m-d');
			}
		}

		if ($this->dateBegin === '')
			$this->dateBegin = '2021-01-05';

		$this->nowStr = $this->dateBegin.' 00:00:00';

		$maxDaysParam = $this->app()->arg('max-days');
		if ($maxDaysParam !== FALSE)
			$this->maxDays = intval($maxDaysParam);


		echo '# begin date is '.$this->dateBegin."\n";
		echo '# max days is '.$this->maxDays."\n";

		$this->checkPeriods();

		utils::$todayClass = $this;
	}

	public function run()
	{
		$start = time();
		$rt = new \DateTime();
		$reallyToday = $rt->format('Y-m-d');

		$date = new \DateTime($this->dateBegin);
		for ($dayCounter = 0; $dayCounter < $this->maxDays; $dayCounter++)
		{
			$this->todayDate = $date->format ('Y-m-d');
			if ($this->todayDate > $reallyToday)
			{
				touch ('.demo');
				echo "### BREAK; future is not supported\n";
				break;
			}

			echo $this->todayDate.": ";

			for ($hour = 0; $hour < 24; $hour++)
			{
				if ($hour)
					echo '.';
				echo sprintf('%02d', $hour);

				$minute = mt_rand(0, 10);
				while(1)
				{
					$second = mt_rand(3, 59);
					$this->nowStr = $this->todayDate.' '.sprintf('%02d:%02d:%02d', $hour, $minute, $second);

					$now = new \DateTime($this->nowStr);
					$generator = new \demo\core\libs\Generator($this->app);
					$generator->setNow($now);
					$generator->run();

					$minute += mt_rand(3, 8);
					if ($minute > 59)
						break;
				}
			}

			echo "\n";

			$now = time();
			$etaSecs = intval ((($now - $start) / ($dayCounter + 1)) * ($this->maxDays - $dayCounter + 1));
			$etaMins = intval(round ($etaSecs / 60)) + 1;
			$etaStr = strval($etaMins);
			$progressInfo = ['start' => $start, 'now' => $now, 'countAll' => $this->maxDays, 'countNow' => $dayCounter, 'eta' => $etaStr];
			file_put_contents(__APP_DIR__ . '/tmp/demoMakeHistoryProgress.json', json_encode($progressInfo));

			$date->add(new \DateInterval('P1D'));
		}
	}

	function checkPeriods ()
	{
		$startYear = intval(substr($this->dateBegin, 0, 4));
		$todayDate = new \DateTime();
		$thisYear = intval($todayDate->format('Y'));

		$tableTaxPeriods = $this->app()->table ('e10doc.base.taxperiods');
		$tableFiscalYears = $this->app()->table ('e10doc.base.fiscalyears');

		$cntCreated = 0;
		for ($year = $startYear; $year <= $thisYear; $year++)
		{
			$cntCreated += $tableTaxPeriods->createPeriod ($year);
			$cntCreated += $tableFiscalYears->createYear ($year);
		}

		if ($cntCreated)
		{
			echo ': updating app config.';
			\E10\updateConfiguration($this->app);
			echo '.';
			$this->app->loadConfig();
			echo '.';
			\E10\updateConfiguration($this->app); // second call
			echo '.';
			$this->app->loadConfig();							// is necessary!
			echo "\n";
		}
	}
}
