<?php

namespace e10pro\canteen\libs;
use \Shipard\Utils\Utils;


/**
 * class InvoicesGeneratorAction
 */
class InvoicesGeneratorAction extends \Shipard\Base\DocumentAction
{
	public function init()
	{
		parent::init();
	}

	public function actionName()
	{
		return 'Vystavit faktury za stravu';
	}

	public function actionParams()
	{
    $year = intval(Utils::today('Y'));
    $month = intval(Utils::today('m'));
    if ($month === 1)
    {
      $year--;
      $month = 12;
    }
    else
      $month--;

		$params = [
			['name' => 'Rok', 'id' => 'calendarYear', 'type' => 'calendarYear', 'defaultValue' => $year],
      ['name' => 'Měsíc', 'id' => 'calendarMonth', 'type' => 'calendarMonth', 'defaultValue' => $month]
		];

		return $params;
	}

	public function createInvoices ()
	{
		$ig = new \e10pro\canteen\libs\InvoicesGenerator($this->app);
		$ig->year = intval($this->params['calendarYear']);
		$ig->month = intval($this->params['calendarMonth']);

		$ig->run();
	}

	public function run ()
	{
    ini_set('memory_limit', '1024M');

    $this->createInvoices();
	}
}
