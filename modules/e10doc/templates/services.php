<?php

namespace e10doc\templates;
use \Shipard\Utils\Utils;


/**
 * Class ModuleServices
 * @package e10doc\contracts\sale
 */
class ModuleServices extends \e10\cli\ModuleServices
{
	public function templatesDocsGenerator ($forceSave = 0)
	{
		$o = new \e10doc\templates\libs\TemplatesScheduler ($this->app);

		$debug = intval($this->app->arg('debug'));
		$o->debug = $debug;

		$resetOutbox = intval($this->app->arg('reset-outbox'));
		$o->resetOutbox = $resetOutbox;

		$today = $this->app->arg('today');
		if ($today !== FALSE)
		{
			$t = utils::createDateTime($today);
			if ($t !== NULL)
				$o->today = $t;
		}

		if ($forceSave)
			$o->save = 1;
		else
		{
			$save = intval($this->app->arg('save'));
			$o->save = $save;
		}

		$o->run ();

		return TRUE;
	}

	public function onCliAction ($actionId)
	{
		switch ($actionId)
		{
			case 'templates-docs-generator': return $this->templatesDocsGenerator();
		}

		parent::onCliAction($actionId);
	}

	public function onCron ($cronType)
	{
		switch ($cronType)
		{
			case 'morning': $this->templatesDocsGenerator(1); break;
		}

		return parent::onCron($cronType);
	}
}
