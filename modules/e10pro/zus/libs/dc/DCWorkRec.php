<?php

namespace e10pro\zus\libs\dc;
use \Shipard\Utils\Utils;


/**
 * class DCWorkRec
 */
class DCWorkRec extends \Shipard\Base\DocumentCard
{
  var $date = NULL;
  var $personNdx = 0;

  public function addCoreInfo()
  {
    $ee = new \e10pro\zus\libs\WorkRecsTimetableEngine($this->app());
    $ee->setParams($this->personNdx, $this->date);
    $ee->loadData();

    $h = ['pobockaId' => 'Pobočka', 'zacatek' => 'Začátek', 'konec' => 'Konec'];
    $this->addContent([
      'pane' => 'e10-pane e10-pane-table',
      'header' => $h,
      'table' => $ee->timetableTable, 'title' => 'Rozvrh', 'params' => ['hideHeader' => 1]
    ]);

    $h = ['rec' => 'Záznam', 'timeBegin' => 'Začátek', 'timeEnd' => 'Konec'];
    $this->addContent([
      'pane' => 'e10-pane e10-pane-table',
      'header' => $h,
      'table' => $ee->workRecsTable, 'title' => 'Záznamy',
    ]);
  }

  public function createContent ()
	{
    $parts = explode('_', $this->recData['pk']);
    $this->date = $parts[0];
    $this->personNdx = intval($parts[1]);

    $this->addCoreInfo();
	}
}
