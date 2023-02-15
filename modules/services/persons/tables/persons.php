<?php

namespace services\persons;

use \Shipard\Utils\Utils, \E10\TableView, \E10\TableForm, \E10\DbTable, \e10\TableViewDetail, \Shipard\Utils\Str;
use \Shipard\Viewer\TableViewPanel;

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

		$idsLabels = [['text' => $recData ['oid'], 'class' => 'label label-info']];
		$idsRows = $this->db()->query('SELECT * FROM [services_persons_ids] WHERE [person] = %i', $recData['ndx']);
		foreach ($idsRows as $id)
		{
			$idsLabels[] = ['text' => $id['id'], 'class' => 'label label-default'];
		}

		$idsLabels[] = ['text' => '#'.$recData ['ndx'], 'class' => 'label label-primary pull-right'];
		$idsLabels[] = ['text' => '_'.$recData ['iid'], 'class' => 'label label-primary pull-right'];

		$hdr ['info'][] = [
			'class' => 'info',
			'value' => $idsLabels,
		];
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
	var $personsIds = [];
	var $registers;
	var $vatStates;


	public function init()
	{
		$this->disableIncrementalSearch = TRUE;
		parent::init();
		$this->registers = $this->app()->cfgItem('services.personsRegisters', []);
		$this->vatStates = $this->app()->cfgItem('services.persons.vatPayerStates', []);
		$this->setPanels (TableView::sptQuery);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$listItem ['i1'] = '#'.$item['ndx'];

		$listItem ['t2'] = [['text' => $item['oid'], 'class' => 'label label-info']];
		$vs = $this->vatStates[$item['vatState']];
		if ($item['vatState'])
		{
			$vatLabel = ['text' => $vs['label'], 'class' => 'label label-default', 'icon' => 'tables/e10doc.base.taxRegs'];
			if ($item['vatID'] !== '')
				$vatLabel['suffix'] = $item['vatID'];
			$listItem ['t2'][] = $vatLabel;
		}
		if (!$item['valid'])
			$listItem['class'] = 'e10-warning1';


		$flags = [];
		$flags[] = ['text' => '@'.$item['iid'], 'class' => ''];
		if ($item['newDataAvailable'])
			$flags[] = ['text' => 'Nová data', 'class' => 'label label-warning'];

		if (count($flags))
			$listItem ['i2'] = $flags;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT * FROM (');
		array_push ($q, ' SELECT 2 AS selectPart, persons.* ');
		array_push ($q, ' FROM [services_persons_persons] AS persons');
		array_push ($q, ' WHERE 1');

		// -- special queries
		$qv = $this->queryValues ();

		$groupVAT = isset ($qv['vat']['groupVAT']);
		$isVATPayer = isset ($qv['vat']['isVATPayer']);
		if ($groupVAT)
			array_push($q, ' AND [vatState] = %i', 2);
		if ($isVATPayer)
			array_push($q, ' AND [vatState] IN %in', [1, 2]);

		$cleanedName = isset ($qv['others']['cleanedName']);
		if ($cleanedName)
			array_push($q, ' AND [cleanedName] = %i', 1);

		// -- fulltext
		if ($fts != '')
		{
			$doExtraLIKE = 1;

			$words = preg_split('/[\s-]+/', $fts);
			$cntUsedWords = 0;
			$fullTextQuery = '';
			foreach ($words as $w)
			{
				if (Str::strlen($w) < 3)
					continue;
				if (substr_count($w, '.') > 1)
				{
					$cntUsedWords++;
					continue;
				}
				if ($w[0] === '+')
					continue;
				if ($fullTextQuery !== '')
					$fullTextQuery .= ' ';
				$fullTextQuery .= '+'.$w;
				$cntUsedWords++;
			}

			if ($fullTextQuery !== '')
			{
//				array_push ($q, ' AND (1 ');
				array_push ($q, ' AND MATCH([fullName]) AGAINST (%s IN BOOLEAN MODE)', $fullTextQuery);
				if (count($words) === $cntUsedWords)
					$doExtraLIKE = 0;
//				array_push ($q, ')');
			}
			else
			{
				//if (Str::strlen($fts) > 2)
					array_push($q, ' AND persons.[fullName] LIKE %s', $fts . '%');
					$doExtraLIKE = 0;
			}

			$ascii = TRUE;
			if(preg_match('/[^\x20-\x7f]/', $fts))
				$ascii = FALSE;

			if ($ascii)
			{
				array_push ($q, 'UNION DISTINCT SELECT 1 AS selectPart, persons.* ');
				array_push ($q, ' FROM [services_persons_persons] AS persons');
				array_push ($q, " WHERE EXISTS (SELECT ndx FROM services_persons_ids WHERE persons.ndx = services_persons_ids.person AND [id] = %s)", $fts);
			}

			$spaceParts = explode(' ', $fts);
			if (count($spaceParts) < 5 && $doExtraLIKE)
			{
				array_push ($q, 'UNION DISTINCT SELECT 1 AS selectPart, persons.* ');
				array_push ($q, ' FROM [services_persons_persons] AS persons');
				array_push ($q, ' WHERE persons.[fullName] LIKE %s', $fts . '%');
			}
		}
		array_push ($q, ') AS ALL_PERSONS');

		if ($fts !== '')
			array_push ($q, ' ORDER BY selectPart, valid DESC, fullName');
		else
			array_push ($q, ' ORDER BY selectPart, fullName');
		array_push ($q, $this->sqlLimit());
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$ids = $this->db()->query('SELECT * FROM [services_persons_ids] WHERE [person] IN %in', $this->pks);
		foreach ($ids as $id)
		{
			$this->personsIds[$id['person']][] = $id->toArray();
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->personsIds[$item ['pk']]))
		{
			foreach ($this->personsIds[$item ['pk']] as $id)
			{
				$item ['t2'][] = ['text' => $id['id'], 'class' => 'label label-default'];
			}
		}
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		$chbxVAT = [
			'isVATPayer' => ['title' => 'Plátci DPH', 'id' => 'isVATPayer'],
			'groupVAT' => ['title' => 'Skupinové DPH', 'id' => 'groupVAT'],
		];
		$paramsVAT = new \Shipard\UI\Core\Params ($this->app());
		$paramsVAT->addParam ('checkboxes', 'query.vat', ['items' => $chbxVAT]);
		$qry[] = ['id' => 'vat', 'style' => 'params', 'title' => ['text' => 'DPH', 'icon' => 'tables/e10doc.base.taxRegs'], 'params' => $paramsVAT];

		// others
		$chbxOthers = [
			'cleanedName' => ['title' => 'Začištěné jméno', 'id' => 'cleanedName'],
		];
		$paramsOthers = new \Shipard\UI\Core\Params ($this->app());
		$paramsOthers->addParam ('checkboxes', 'query.others', ['items' => $chbxOthers]);
		$qry[] = ['id' => 'others', 'style' => 'params', 'title' => ['text' => 'Ostatní', 'icon' => 'system/iconCogs'], 'params' => $paramsOthers];

		$panel->addContent(array ('type' => 'query', 'query' => $qry));
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
			$this->addColumnInput ('vatID');
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

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		$toolbar [] = [
				'type' => 'action', 'action' => 'addwizard', 'data-table' => 'services.persons.persons',
				'text' => 'Obnovit z registrů', 'data-class' => 'services.persons.libs.WizardPersonRefresh',
				'icon' => 'system/iconSpinner',
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => 'default',
		];

		return $toolbar;
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

/**
 * Class ViewDetailPersonLog
 */
class ViewDetailPersonLog extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('services.persons.libs.DocumentCardPersonLog');
	}
}
