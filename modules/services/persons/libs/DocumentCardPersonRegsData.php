<?php

namespace services\persons\libs;
use \Shipard\Utils\Utils;


class DocumentCardPersonRegsData extends \Shipard\Base\DocumentCard
{
  var $regTypes = NULL;
  
	function addRegsData()
	{
    $rds = [];

		$q = [];
		array_push($q, 'SELECT * FROM [services_persons_regsData]');
		array_push($q, ' WHERE [person] = %i', $this->recData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$rd = $r->toArray();
      $rd['timeUpdated'] = $r['timeUpdated'];
      $rds[] = $rd;
		}

    foreach ($rds as $rd)
    {
      $rt = $this->regTypes[$rd['regType']];
      $title = [
        ['text' => $rt['name'], 'class' => 'h2'],
        ['text' => Utils::datef($rd['timeUpdated'], '%d, %T'), 'class' => 'e10-off pull-right', 'icon' => 'system/iconImport'],
      ];
		  $this->addContent ('body', ['pane' => 'e10-pane e10-pane-table pageText', 'class' => 'e10-error',
       'type' => 'text', 'subtype' => 'code', 'text' => $rd['srcData'], 'paneTitle' => $title]);
    }
	}

	public function createContentBody ()
	{
		$this->addRegsData();

		/*
		$h = ['#' => '#', 'text' => 'Popis', 'debit' => ' Vyplaceno', 'credit' => ' Přijato', 'balance' => ' Zůstatek'];
		return ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu'], 'header' => $h, 'table' => $list];
		*/
	}


	

	public function createContent ()
	{
    $this->regTypes = $this->app()->cfgItem('services.persons.registers');
		//$this->createContentHeader ();
		$this->createContentBody ();
	}
}
