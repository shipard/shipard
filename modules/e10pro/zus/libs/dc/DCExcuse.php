<?php

namespace e10pro\zus\libs\dc;
use \Shipard\Utils\Utils;


/**
 * class DCExcuse
 */
class DCExcuse extends \Shipard\Base\DocumentCard
{
  public function addCoreInfo()
  {
    $ee = new \e10pro\zus\libs\ExcuseEngine($this->app());
    $ee->setExcuse($this->recData['ndx']);
    $ee->loadAffectedHours();

    $t = [];

    if ($this->recData['pouzitCasOdDo'])
    {
      $t[] = ['t' => 'Od', 'v' => Utils::datef($this->recData['datumOd'], '%d').', '.$this->recData['casOd'].' - '.$this->recData['casDo']];
      // $t[] = ['t' => 'Do', 'v' => Utils::datef($this->recData['datumDo'], '%d')];
    }
    else
    {
      $t[] = ['t' => 'Od', 'v' => Utils::datef($this->recData['datumOd'], '%d')];
      $t[] = ['t' => 'Do', 'v' => Utils::datef($this->recData['datumDo'], '%d')];
    }

    if ($this->recData['authorUser'])
    {
      $author = $this->app()->loadItem($this->recData['authorUser'], 'e10.users.users');
      $t[] = ['t' => 'Omluvil/a', 'v' => $author['fullName']];
    }

    $duvod = $this->app()->cfgItem('zus.duvodyOmluveni.'.$this->recData['duvod'], NULL);
    if ($duvod)
      $t[] = ['t' => 'Důvod', 'v' => $duvod['fn']];

    $h = ['t' => '', 'v' => ''];

		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'header' => $h, 'table' => $t,
				'params' => ['hideHeader' => 1, 'forceTableClass' => 'properties fullWidth']]);


    if (count($ee->affectedHours))
    {
      $hah = ['day' => 'Den', 'date' => 'Datum', 'time' => 'Čas', 'teacher' => 'Učitel', 'subject' => 'Předmět'];

      $this->addContent ('body', [
        'pane' => 'e10-pane e10-pane-table', 'type' => 'table',
        'header' => $hah, 'table' => $ee->affectedHours,
        'title' => ['text' => 'Omluvené hodiny', 'class' => 'h3'],
        'params' => []
      ]);

    }

    if ($ee->timeTable && count($ee->timeTable))
    {
      $this->addContent([
        'pane' => 'e10-pane e10-pane-table',
        'header' => ['den' => '_Den', 'doba' => '_Čas', 'predmet' => 'Předmět', 'rocnik' => 'Ročník', 'ucitel' => 'Učitel', 'pobocka' => 'Pobočka', 'ucebna' => 'Učebna'],
        'table' => $ee->timeTable, 'title' => 'Rozvrh studenta', 'params' => ['hideHeader' => 1]
      ]);
    }
  }

  public function createContent ()
	{
    $this->addCoreInfo();
	}
}
