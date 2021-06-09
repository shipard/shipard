<?php

namespace e10pro\hosting\server;

use \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable, \E10\utils;


/**
 * Class TablePartnersPersons
 * @package e10pro\hosting\server
 */
class TablePartnersPersons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.hosting.server.partnersPersons', 'e10pro_hosting_server_partnersPersons', 'Osoby partnerů');
	}

	public function createHeader ($recData, $options)
	{
		$tablePersons = $this->app()->table ('e10.persons.persons');
		if ($recData['person'])
			$person = $tablePersons->loadItem ($recData['person']);

		$tablePartners = $this->app()->table ('e10pro.hosting.server.partners');
		if ($recData['partner'])
			$partner = $tablePartners->loadItem ($recData['partner']);

		$hdr = parent::createHeader ($recData, $options);

		if ($recData['partner'])
			$hdr ['info'][] = ['class' => 'info', 'value' => [['text' => $partner ['fullName'], 'icon' => $tablePartners->tableIcon($partner)]]];
		if ($recData['person'])
			$hdr ['info'][] = ['class' => 'info', 'value' => [['text' => $person ['fullName'], 'icon' => $tablePersons->tableIcon($person)]]];

		return $hdr;
	}
}


/**
 * Class ViewDetailPartnerPerson
 * @package e10pro\hosting\server
 */
class ViewDetailPartnerPerson extends TableViewDetail
{
}


/**
 * Class FormPartnerPerson
 * @package e10pro\hosting\server
 */
class FormPartnerPerson extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

		$this->addColumnInput ('person');
		$this->addColumnInput ('isAdmin');
		$this->addColumnInput ('isSupport');

		//$this->addColumnInput ('partner');

		$this->closeForm ();
	}
}


/**
 * Class ViewPartnersPersons
 * @package e10pro\hosting\server
 */
class ViewPartnersPersons extends \E10\TableView
{
	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('partner'))
		{
			$this->addAddParam ('partner', $this->queryParam ('partner'));
		}

		$this->setMainQueries();

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['personName'];
		$listItem ['i1'] = ['text' => '#'.$item['personId'], 'class' => 'id'];

		$props = [];
		if ($item['isAdmin'])
			$props[] = ['text' => 'Správce partnera', 'class' => 'label label-default', 'icon' => 'system/actionSettings'];
		if ($item['isSupport'])
			$props[] = ['text' => 'Technická podpora zákazníků', 'class' => 'label label-default', 'icon' => 'system/actionSupport'];
		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q[] = 'SELECT pp.*, persons.fullName AS personName, persons.id AS personId';
		array_push ($q, ' FROM [e10pro_hosting_server_partnersPersons] AS pp');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON pp.person = persons.ndx');

		array_push($q, ' WHERE pp.partner = %i', $this->queryParam ('partner'));

		if ($fts != '')
			array_push ($q, " AND (persons.[fullName] LIKE %s)", '%'.$fts.'%');

		$this->queryMain ($q, '[pp].', ['[persons].[fullName]', '[pp].[ndx]']);

		$this->runQuery ($q);
	}
}

