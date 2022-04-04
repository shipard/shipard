<?php

namespace services\persons;

use \Shipard\Utils\Utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail, \Shipard\Utils\Str;


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

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['oid']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		$registerInfo = [
			['text' => Utils::dateFromTo($recData['validFrom'], $recData['validTo'], NULL), 'class' => ($recData['valid'] ? 'label label-success' : 'label label-danger')],
			['text' => utils::datef($recData['updated'], '%D, %T'), 'class' => 'label label-default', 'icon' => 'system/iconImport'],
		];
		$hdr ['info'][] = ['class' => 'info', 'value' => $registerInfo];

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
		$this->disableIncrementalSearch = TRUE;
		parent::init();
		$this->registers = $this->app()->cfgItem('services.personsRegisters', []);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['t2'] = $item['oid'];

		if (!$item['valid'])
			$listItem['class'] = 'e10-warning1';

		$flags = [];
		if ($item['newDataAvailable'])
			$flags[] = ['text' => 'Nová data', 'class' => 'label label-default'];

		if (count($flags))	
			$listItem ['i2'] = $flags;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [services_persons_persons]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		/*
		if ($fts != '')
		{
			$ascii = TRUE;
			if(preg_match('/[^\x20-\x7f]/', $fts))
				$ascii = FALSE;

			array_push ($q, ' AND (');
			array_push ($q, ' [fullName] LIKE %s', '%'.$fts.'%');
					
			if ($ascii)
				array_push ($q,' OR [oid] LIKE %s', '%'.$fts.'%');
			
			array_push ($q, ')');
		}
		*/

		if ($fts != '')
		{
			array_push ($q, ' AND (1 ');

			$words = preg_split('/[\s-]+/', $fts);
			$fullTextQuery = '';
			foreach ($words as $w)
			{
				if (Str::strlen($w) < 4)
					continue;
				if ($fullTextQuery !== '')
					$fullTextQuery .= ' ';
				$fullTextQuery .= '+'.$w;
			}

			if ($fullTextQuery !== '')
				array_push ($q, ' AND MATCH([fullName]) AGAINST (%s IN BOOLEAN MODE)', $fullTextQuery);
			else
			{
				if (Str::strlen($fts) > 2)
					array_push($q, ' AND [fullName] LIKE %s', $fts . '%');
			}
			array_push ($q, ')');
			/*
			$ascii = TRUE;
			if(preg_match('/[^\x20-\x7f]/', $fts))
				$ascii = FALSE;

			if ($ascii)
				array_push ($q,' OR [oid] = %s', $fts);
			*/	
		}

		array_push ($q, ' ORDER BY fullName');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}
}


/**
 * Class FormPerson
 */
class FormPerson extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('country');
			$this->addColumnInput ('oid');
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('validFrom');
			$this->addColumnInput ('validTo');
			$this->addColumnInput ('valid');
		$this->closeForm ();
	}
}

/**
 * Class ViewDetailPerson
 */
class ViewDetailPerson extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.persons.libs.DocumentCardPerson');
	}
}

/**
 * Class ViewDetailPersonRegsData
 */
class ViewDetailPersonRegsData extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.persons.libs.DocumentCardPersonRegsData');
	}
}
