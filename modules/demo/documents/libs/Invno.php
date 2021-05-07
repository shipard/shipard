<?php

namespace demo\documents\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \e10\utils;


/**
 * Class Invno
 */
class Invno extends \demo\documents\libs\Core
{
	public function init ($taskDef, $taskTypeDef)
	{
		parent::init($taskDef, $taskTypeDef);

		$this->data['rec']['docType'] = 'invno';
		$this->data['rec']['dbCounter'] = 1;
		$this->data['rec']['currency'] = 'czk';
		$this->data['rec']['paymentMethod'] = 0;
		$this->data['rec']['taxCalc'] = 1;
		$this->data['rec']['roundMethod'] = 1;
		$this->data['rec']['author'] = 1;

		$dateDue = utils::today();
		$dateDue->add (new \DateInterval('P14D'));
		$this->data['rec']['dateDue'] = $dateDue;

		$this->addRows();
	}

	public function save()
	{
		parent::save();

		$engine = new \demo\documents\libs\DemoDocOutbox ($this->app());
		$engine->init(['docNdx' => $this->newNdx]);
		$engine->createOutbox();
		unset($engine);
	}
}
