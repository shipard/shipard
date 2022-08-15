<?php

namespace e10doc\templates;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm;
use \e10doc\core\libs\E10Utils, \e10\utils, \e10\DbTable, \Shipard\Viewer\TableViewPanel;
use \e10\base\libs\UtilsBase;


/**
 * Class TableHeads
 */
class TableHeads extends DbTable
{
	CONST ttDocGen = 0, ttWOGen = 1, ttTemplateFromExistedDoc = 2;
	CONST asmNone = 0, asmFullSend = 1, asmOutboxSendLater = 2, asmOutboxNotSend = 3;


	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.templates.heads', 'e10doc_templates_heads', 'Šablony dokladů');
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'dstDocKind')
		{
			if ($cfgItem['ndx'] === 0)
				return TRUE;

			if ($form->recData['dstDocType'] !== $cfgItem['docType'])
				return FALSE;

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId !== 'dstDbCounter')
			return parent::columnInfoEnum ($columnId, $valueType, $form);

		$enum = [];
		$dbCounters = $this->app()->cfgItem('e10.docs.dbCounters.'.$form->recData ['dstDocType'], []);
		foreach ($dbCounters as $dbcNdx => $dbc)
		{
			$enum[$dbcNdx] = $dbc['name'];
		}

		return $enum;
	}


	public function createHeader ($recData, $options)
	{
		$hdr = $this->createPersonHeaderInfo ($recData['person'], $recData);
		$hdr ['icon'] = 'icon-thumbs-up';
		//$docInfo [] = array ('text' => $recData ['contractNumber'] . ' ▪︎ ' .'Smlouva prodejní', 'icon' => 'system/iconFile');
		//$hdr ['info'][] = array ('class' => 'title', 'value' => $docInfo);

		$now = time ();
		$itemIsActive = 1;
			if (strtotime(\E10\df($recData['validFrom'])) > $now)
				$itemIsActive = 2;
		if ($recData['validTo'])
			if (strtotime(\E10\df($recData['validTo'])) < $now)
				$itemIsActive = 0;
		if ($recData['docState'] == 9000 || $recData['docState'] == 9800)
				$itemIsActive = 0;
		if ($itemIsActive == 0)
			$hdr ['sum'][] = array ('class' => 'big', 'value' => ['icon' => 'system/actionStop', 'text' => '']);
		if ($itemIsActive == 1)
		{
			$currencyName = $this->app()->cfgItem ('e10.base.currencies.'.$recData['currency'].'.shortcut');
			$hdr ['sum'][] = array ('class' => 'big', 'value' => $currencyName);
			if ($recData['taxCalc'] == '0')
				$hdr ['sum'][] = array ('class' => 'normal', 'value' => 'ceny jsou bez DPH');
			else
				$hdr ['sum'][] = array ('class' => 'normal', 'value' => 'ceny jsou s DPH');
		}
		if ($itemIsActive == 2)
			$hdr ['sum'][] = array ('class' => 'big', 'value' => ['icon' => 'icon-pause', 'text' => '']);

		return $hdr;
	}

	public function createPersonHeaderInfo ($itemPerson, $itemDoc)
	{
		$tablePersons = new \E10\Persons\TablePersons ($this->app());

		if (is_array ($itemPerson))
			$recData = $itemPerson;
		else
			$recData = $tablePersons->loadItem (intval($itemPerson));

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return array();

		$ndx = $recData ['ndx'];

		$icon [] = array ('text' => $recData ['fullName'],
			'icon' => $tablePersons->icon ($recData),
			'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $ndx);
		$hdr ['info'][] = array ('class' => 'info', 'value' => $icon);

		return $hdr;
	}

	public function templateInfo ($recData, &$info)
	{

	}

	protected function checkChangedInput ($changedInput, &$saveData)
	{
		$colNameParts = explode ('.', $changedInput);

		// -- row item reset
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'item')
		{
			if (!isset ($saveData['softChangeInput']))
			{
				$item = $this->loadItem ($saveData['lists']['rows'][$colNameParts[2]]['item'], 'e10_witems_items');
				$this->resetRowItem ($saveData['recData'], $saveData['lists']['rows'][$colNameParts[2]], $item);
			}
			return;
		}
	}

	public function resetRowItem ($headRecData, &$rowRecData, $itemRecData)
	{
		$rowRecData ['text'] = $itemRecData['fullName'];
		$rowRecData ['unit'] = $itemRecData['defaultUnit'];

		$taxReg = E10Utils::primaryTaxRegCfg($this->app());
		$rowRecData ['priceItem'] = E10Utils::itemPriceSell($this->app(), $taxReg, $headRecData['taxCalc']+1, $itemRecData);
	}
}


/**
 * Class ViewHeads
 */
class ViewHeads extends TableView
{
	var $docType = 'sale';

	var $docNumbers;
	var $contractKinds;

	var $classification;

	CONST btmNone = 0, btmDocKinds = 1, btmDocNumbers = 2;
	var $btMode = self::btmNone;


	public function init ()
	{
		parent::init();

//		if (isset ($this->docType))
//			$this->addAddParam ('docType', $this->docType);

		$this->setMainQueries ();


		//$panels = [];
		//$panels [] = ['id' => 'qry', 'title' => 'Hledání'];
		//$this->setPanels($panels);
		$this->setPanels (TableView::sptQuery|TableView::sptReview);
		$this->panels [1]['title'] = 'Generování';
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();
		$bottomTabId = $this->bottomTabId();
		if ($bottomTabId === '')
			$bottomTabId = 'ALL';

		$q = [];
		array_push($q, 'SELECT [heads].*, ');
		array_push($q, ' persons.fullName AS personFullName');
		array_push($q, ' FROM [e10doc_templates_heads] AS [heads]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [heads].[person] = [persons].[ndx]');
		array_push($q, ' WHERE 1');
		//array_push($q, '');
		//array_push($q, '');
		//array_push($q, '');

		// -- docType
		//if (isset ($this->docType))
    //  array_push ($q, ' AND [heads].[docType] = %s', $this->docType);


		// -- fulltext
		if ($fts != '')
		{
 			array_push ($q, ' AND ([persons].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [heads].[title] LIKE %s)', '%'.$fts.'%');
		}

		/*
		$qv = $this->queryValues();
		if (isset($qv['docKinds']))
			array_push ($q, ' AND heads.[docKind] IN %in', array_keys($qv['docKinds']));

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE heads.ndx = recid AND tableId = %s', 'e10doc.contracts.core.heads');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}
		*/

		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, ' AND heads.[docStateMain] < %i', 4);
		elseif ($mainQuery == 'ended')
      array_push ($q, ' AND heads.[docStateMain] = %i', 5);
		elseif ($mainQuery == 'trash')
      array_push ($q, ' AND heads.[docStateMain] = %i', 4);

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [heads].[title] DESC');
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [heads].[title] DESC');

		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);
	}

	public function renderRow ($item)
	{
		$bottomTabId = $this->bottomTabId();

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon($item);
		$listItem ['t1'] = $item['title'];//$item['personFullName'];

		$today = Utils::today();

		$props [] = ['icon' => 'system/actionPlay', 'text' => utils::datef ($item['validFrom'], '%d'), 'class' => ''];
		if ($item['validTo'])
			$props [] = ['icon' => 'system/iconStop', 'text' => utils::datef ($item['validTo'], '%d'), 'class' => ''];


		$listItem ['t2'] = $props;
		$listItem ['t3'] = $item['title'];

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			//$item ['t3'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}
	}


	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = [];

		// -- kinds
		/*
		if (count($this->contractKinds) !== 0)
		{
			$chbxDK = [];
			forEach ($this->contractKinds as $ckId => $ck)
				$chbxDK[$ckId] = ['title' => $ck['fn'], 'id' => $ckId];

			$chbxDK['0'] = ['title' => 'Nezařazeno', 'id' => '0'];

			$paramsDK = new \E10\Params ($panel->table->app());
			$paramsDK->addParam ('checkboxes', 'query.docKinds', ['items' => $chbxDK]);
			$qry[] = ['id' => 'itemGroups', 'style' => 'params', 'title' => 'Druhy smluv', 'params' => $paramsDK];
		}
		*/

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}

	public function createPanelContentReview (TableViewPanel $panel)
	{
		$o = new \e10doc\templates\libs\TemplatesScheduler($this->app());
		$o->checkDirection = 1;
		$o->maxCheckDays = 92;
		$o->run();
		$reviewContent = $o->reviewContent();

    $panel->addContent($reviewContent);
	}
}


/**
 * Class ViewDetailHead
 */
class ViewDetailHead extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.templates.libs.dc.TemplateCore');
	}
}


/**
 * class FormHead
 */
class FormHead extends TableForm
{
	var $contractKind = NULL;

	public function renderForm ()
	{
		$tt = $this->recData['templateType'];


		$askTaxCalc = 1;
		$askCentre = isset($this->contractKind['centreOC']) ? $this->contractKind['centreOC'] : 1;
		$askProject = isset($this->contractKind['wkfProjectOC']) ? $this->contractKind['wkfProjectOC'] : 1;
		$askWorkOrder = isset($this->contractKind['workOrderOC']) ? $this->contractKind['workOrderOC'] : 1;
		if ($tt === TableHeads::ttWOGen)
			$askWorkOrder = 0;

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Řádky', 'icon' => 'system/formRows'];
			$tabs ['tabs'][] = ['text' => 'Poznámka', 'icon' => 'system/formNote'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
				$this->addColumnInput ('title');
				$this->addColumnInput('templateType');

				if ($tt === TableHeads::ttWOGen)
				{
					$this->addSeparator(self::coH4);
					$this->addColumnInput('srcWorkOrderKind');
					$this->addColumnInput('srcWorkOrderDbCounter');
				}
				elseif ($tt === TableHeads::ttTemplateFromExistedDoc)
				{
					$this->addSeparator(self::coH4);
					$this->addColumnInput('srcDocOrigin');
					$this->addColumnInput('srcDocLast');
				}
				$this->addSeparator(self::coH4);

				$this->addColumnInput ('validFrom');
				$this->addColumnInput ('validTo');
				$this->addSeparator(self::coH4);

				$this->addColumnInput ('period');
				$this->addColumnInput ('creatingDay');
				$this->addSeparator(self::coH4);

				if ($tt !== TableHeads::ttTemplateFromExistedDoc)
				{
					$this->addColumnInput ('dstDocType');
					$this->addColumnInput ('dstDocKind');
					$this->addColumnInput ('dstDbCounter');

					if ($tt !== TableHeads::ttWOGen)
					{
						$this->addColumnInput ('person');
						$this->addColumnInput ('currency');
					}

					if ($askTaxCalc)
						$this->addColumnInput ('taxCalc');
					$this->addColumnInput ('paymentMethod');
					$this->addColumnInput ('myBankAccount');
					$this->addColumnInput ('dueDays');
				}

				$this->addColumnInput ('headSymbol1');
				$this->addColumnInput ('headSymbol2');
				$this->addColumnInput ('rowsSymbol1');
				$this->addColumnInput ('rowsSymbol2');

				$this->addColumnInput ('docText');
				$this->addSeparator(self::coH4);

				if ($tt !== TableHeads::ttTemplateFromExistedDoc)
				{
					$useSep = 0;
					if ($askCentre && $this->table->app()->cfgItem ('options.core.useCentres', 0))
					{
						$this->addColumnInput ('centre');
						$useSep++;
					}
					if ($askProject && $this->table->app()->cfgItem ('options.core.useProjects', 0))
					{
						$this->addColumnInput ('project');
						$useSep++;
					}
					if ($askWorkOrder)
					{
						$this->addColumnInput ('workOrder');
						$useSep++;
					}

					if ($useSep)
						$this->addSeparator(self::coH4);
				}

				$this->addColumnInput ('dstDocState');
				$this->addColumnInput ('dstDocAutoSend');

				$this->addSeparator(self::coH4);
				$this->addList ('clsf', '', TableForm::loAddToFormLayout);
    	$this->closeTab ();

			$this->openTab ();
				$this->addList ('rows');
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ("docNote", NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->closeTabs ();

		$this->closeForm ();
	}

	public function checkAfterSave ()
	{
		return parent::checkAfterSave();
		//return TRUE;
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.contracts.core.rows' && $srcColumnId === 'item')
		{
			return array ('docType' => 'invno', 'operation' => ['1010001', '1010002', '1099998'], 'docDir' => '2');
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

	public function comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId = 'default')
	{
		if (/*$srcTableId === 'e10doc.core.heads' &&*/ $srcColumnId === 'srcDocOrigin')
			return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, 'e10doc.invoicesIn.libs.ViewInvoicesIn');
		return parent::comboViewer ($srcTableId, $srcColDef, $srcColumnId, $allRecData, $recData, $viewerId);
	}


}

