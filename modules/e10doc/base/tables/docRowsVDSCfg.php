<?php


namespace e10doc\base;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;



/**
 * class TableDocRowsVDSCfg
 */
class TableDocRowsVDSCfg extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.base.docRowsVDSCfg', 'e10doc_base_docRowsVDSCfg', 'Nastavení VDS na řádcích dokladu');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData ['systemOrder'] = 99;

		if ($recData['docDbCounter'])
			$recData ['systemOrder']--;

		if ($recData['docType'] != 0)
			$recData ['systemOrder']--;

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec($recData);
		if (!isset($recData['docKind']))
			$recData['docKind'] = 0;
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$props = [];
		$t1 = '';
		$this->itemInfo ($recData, $props, $t1);

		$hdr ['info'][] = ['class' => 'info', 'value' => $props];
		$hdr ['info'][] = ['class' => 'title', 'value' => $t1];

		return $hdr;
	}

	public function itemInfo ($recData, &$props, &$title)
	{
		$class = 'label label-default';

		if ($recData['docType'] !== '')
		{
			$docType = $this->app()->cfgItem ('e10.docs.types.'.$recData['docType'], FALSE);
			if ($docType)
				$props [] = ['text' => $docType['fullName'], 'title' => 'Typ dokladu', 'icon' => 'icon-file-o', 'class' => $class];
		}

		if ($recData['docDbCounter'])
		{
			$dbCounter = $this->app()->loadItem ($recData['docDbCounter'], 'e10doc.base.docnumbers');
			if ($dbCounter)
				$props [] = ['text' => $dbCounter['fullName'], 'title' => 'Číselná řada', 'icon' => 'icon-play-circle', 'class' => $class];
		}


		if ($recData['docKind'] != 0)
		{
			$docKind = $this->app()->cfgItem ('e10.docs.kinds.'.$recData['docKind'], FALSE);
			if ($docKind)
				$props [] = ['text' => $docKind['fullName'], 'title' => 'Druh dokladu', 'icon' => 'icon-flag-o', 'class' => $class];
		}

		if (!count($props))
			$props [] = ['text' => 'Bude uplatněno na všech sestavách', 'icon' => 'system/iconCheck', 'class' => $class];

		$enumPlaces = $this->columnInfoEnum('place');
		if (isset($enumPlaces[$recData['place']]))
			$title = $enumPlaces[$recData['place']];
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'docType')
		{
			$enum[''] = 'Vše';
			$allDocsTypes = $this->app()->cfgItem ('e10.docs.types');
			foreach ($allDocsTypes as $docTypeId => $docType)
			{
				$enum[$docTypeId] = $docType['fullName'];
			}

			return $enum;
		}

		return parent::columnInfoEnum ($columnId, $valueType, $form);
	}
}


/**
 * class ViewDocRowsVDSCfg
 */
class ViewDocRowsVDSCfg extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$props = [];
		$t1 = '';
		$this->table->itemInfo ($item, $props, $t1);

		$listItem ['t1'] = $t1;
		$listItem ['t2'] = $props;

		if ($item['note'] !== '')
			$listItem['t3'] = $item['note'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10doc_base_docRowsVDSCfg]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [note] LIKE %s', '%'.$fts.'%', ' OR [text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormDocRowsVDSCfg
 */
class FormDocRowsVDSCfg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('docType');
					$this->addColumnInput ('docKind');
					$this->addColumnInput ('docDbCounter');
          $this->addColumnInput ('witem');
					$this->addSeparator(self::coH2);
          $this->addColumnInput ('vds');
					$this->addColumnInput ('note');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.base.reportsTexts' && $srcColumnId === 'docDbCounter')
		{
			$cp = [];
			if ($recData['docType'] != '')
				$cp['docType'] = $recData['docType'];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}

