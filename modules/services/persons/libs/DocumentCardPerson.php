<?php

namespace services\persons\libs;
use \Shipard\Utils\Utils;

/**
 * class DocumentCardPerson
 */
class DocumentCardPerson extends \Shipard\Base\DocumentCard
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
		$regsContent = [];
    foreach ($rds as $rd)
    {
      $rt = $this->regTypes[$rd['regType']];
      $title = [
        ['text' => $rt['name'], 'class' => 'h3', '__icon' => 'system/iconFile'],
        ['text' => Utils::datef($rd['timeUpdated'], '%d, %T'), 'class' => 'e10-off pull-right', 'icon' => 'system/iconImport'],
				['text' => '', 'class' => 'block clear bb1 pb1'],
      ];

			$rc = [
				'type' => 'text', 'subtype' => 'code', 'text' => $rd['srcData'],
				'detailsTitle' => $title,
				'details' => 'padd5', 'pane' => 'pageText'
			];
			$regsContent [] = $rc;
    }

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table',
			'type' => 'content', 'content' => $regsContent,
			'paneTitle' => ['text' => 'Data z registrů', 'class' => 'h1 block __bb1 mb1', 'icon' => 'system/iconFile']
		]);
	}

	function addAddresses()
	{
		$t = [];

		$q = [];
		array_push($q, 'SELECT * FROM [services_persons_address]');
		array_push($q, ' WHERE [person] = %i', $this->recData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = [
				'city' => $r['city'],
				'zipcode' => $r['zipcode'],
				'street' => $r['street'],
				'spec' => $r['specification'],
				'natId' => $r['natId'],
			];

			if (!Utils::dateIsBlank($r['validFrom']))
				$item['validFrom'] = Utils::datef($r['validFrom']);
			if (!Utils::dateIsBlank($r['validTo']))
				$item['validTo'] = Utils::datef($r['validTo']);

			if ($r['natAddressGeoId'])
				$item['geoId'] = ['text' => $r['natAddressGeoId'], 'url' => 'https://vdp.cuzk.cz/vdp/ruian/adresnimista/'.$r['natAddressGeoId']];

			$t[] = $item;
		}

		$h = ['#' => '#', 'city' => 'Město', 'zipcode' => 'PSČ', 'street' => 'Ulice', 'spec' => 'Upřesnění', 'natId' => 'natId', 'geoId' => 'geoId', 'validFrom' => 'Od', 'validTo' => 'Do'];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'header' => $h, 'table' => $t,
			'paneTitle' => ['text' => 'Adresy', 'class' => 'h1', 'icon' => 'tables/e10.base.places'],
		]);
	}

	function addBankAccounts()
	{
		$t = [];

		$q = [];
		array_push($q, 'SELECT * FROM [services_persons_bankAccounts]');
		array_push($q, ' WHERE [person] = %i', $this->recData['ndx']);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$t[] = [
				'bankAccount' => $r['bankAccount'],
				'validFrom' => Utils::datef($r['validFrom'], '%d'),
			];
		}

		$h = ['#' => '#', 'bankAccount' => 'Účet', 'validFrom' => 'Platné od'];
		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'header' => $h, 'table' => $t,
			'paneTitle' => ['text' => 'Bankovní účty', 'class' => 'h1', 'icon' => 'docType/bank'],
		]);
	}

	public function createContentBody ()
	{
		$this->addAddresses();
		$this->addBankAccounts();
		$this->addRegsData();

		$line = [];
		$ve = new \e10\persons\PersonValidator($this->app());
		$ve->clear();
		$ve->country = 'cz';
		$ve->addOID($this->recData['oid']);
		$tools = $ve->onlineTools(NULL);
		if ($tools)
		{
			$line[] = ['text' => '', 'class' => 'break padd5'];
			foreach ($tools as $t)
			{
				$t['class'] = 'btn btn-default btn-sm';
				$t['icon'] = 'system/iconLink';
				$line[] = $t;
			}
		}
		$this->addContent ('body', ['pane' => 'e10-pane e10-pane-table', 'type' => 'line', 'line' => $line]);

		/*
		$h = ['#' => '#', 'text' => 'Popis', 'debit' => ' Vyplaceno', 'credit' => ' Přijato', 'balance' => ' Zůstatek'];
		return ['pane' => 'e10-pane e10-pane-table', 'type' => 'table', 'title' => ['icon' => 'system/iconList', 'text' => 'Řádky dokladu'], 'header' => $h, 'table' => $list];
		*/
	}

	public function createContent ()
	{
		$this->regTypes = $this->app()->cfgItem('services.persons.registers');
		$this->createContentBody ();
	}
}
