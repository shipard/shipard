<?php

namespace demo\documents\libs;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';


/**
 * Class Cashreg
 * @package lib\demo\documents\docs
 */
class Cashreg extends \demo\documents\libs\Core
{
	public function init ($taskDef, $taskTypeDef)
	{
		parent::init($taskDef, $taskTypeDef);

		$this->data['rec']['docType'] = 'cashreg';
		$this->data['rec']['cashBox'] = 1;
		$this->data['rec']['currency'] = 'czk';
		$this->data['rec']['paymentMethod'] = 1;
		$this->data['rec']['taxCalc'] = 1;
		$this->data['rec']['roundMethod'] = 1;
		$this->data['rec']['author'] = 1;

		$this->addRows();

		$this->data['rec']['title'] = '';
	}

	protected function setPerson ()
	{
	}

	public function save()
	{
		parent::save();
/*
		$engine = new \lib\demo\DemoDocOutbox($this->app());
		$engine->init(['docNdx' => $this->newNdx]);
		$engine->createOutbox();
		unset($engine);
*/
	}
}
