<?php

namespace services\persons;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail;


/**
 * Class TablePersons
 * @package services\persons
 */
class TablePersons extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('services.persons.persons', 'services_persons_persons', 'Osoby');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$registers = $this->app()->cfgItem('services.personsRegisters', []);
		$registerInfo = [
				['text' => $registers[$recData['register']]['name'], 'class' => 'label label-info'],
				['text' => utils::datef($recData['updated'], '%D, %T'), 'class' => ''],
		];

		$hdr ['info'][] = ['class' => 'info', 'value' => $registerInfo];

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['id']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewPersons
 * @package services\persons
 */
class ViewPersons extends TableView
{
	var $registers;

	public function init()
	{
		parent::init();
		$this->registers = $this->app()->cfgItem('services.personsRegisters', []);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['id'];
		$listItem ['i2'] = [
				['text' => $this->registers[$item['register']]['name'], 'class' => 'label label-info'],
				['text' => utils::datef($item['updated'], '%D, %T'), 'class' => ''],
		];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [services_persons_persons]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
					' [fullName] LIKE %s', '%'.$fts.'%',
					' OR [id] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY fullName');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}
}


/**
 * Class FormCamera
 * @package terminals\base
 */
class FormPerson extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('id');
			$this->addColumnInput ('fullName');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailPerson
 * @package services\persons
 */
class ViewDetailPerson extends TableViewDetail
{
	public function createDetailContent ()
	{
		$txt = $this->item['result'];
		$this->addContent(['type' => 'text', 'subtype' => 'plain', 'text' => $txt]);
	}
}
