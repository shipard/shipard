<?php

namespace hosting\core\libs;

use \Shipard\Utils\Utils, \Shipard\Base\Utility, \Shipard\Application\Response;


/**
 * Class LoginScreenFeed
 */
class LoginScreenFeed extends Utility
{
	protected $today = NULL;
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
		if ($this->today === NULL)
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

		$response = new Response ($this->app);
		$response->add ('objectType', 'loginScreen');
		$response->add ('object', $this->object);
		return $response;
	}
}
