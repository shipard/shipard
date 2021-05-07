<?php

namespace E10Pro\Digests\Core;


class ModuleServices extends \E10\CLI\ModuleServices
{
	public function createDigests ()
	{
		if ($this->app->cfgItem ('dsMode', 1) !== 0)
			return;

		$digests = $this->app->cfgItem ('e10.digests');
		forEach ($digests as $d)
		{
			$runDigest = $this->app->createObject ($d['class']);
			$runDigest->run ();
		}
	}

	public function onCronMorning ()
	{
		$this->createDigests();
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning': $this->onCronMorning(); break;
		}
		return TRUE;
	}
}
