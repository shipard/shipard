<?php

namespace E10Doc\Contracts\Core;

use e10doc\core\libs\E10Utils, \e10\utils, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \e10\DbTable, \Shipard\Viewer\TableViewPanel;
use \e10\base\libs\UtilsBase;

/**
 * Class TableHeads
 * @package E10Doc\Contracts\Core
 */
class TableHeads extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.contracts.core.heads", "e10doc_contracts_heads", "Smlouvy", 1100);
	}

  public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
//		$docType = Application::cfgItem ('e10.docs.types.' . $recData['docType']);
//		return $docType['classId'];

		return 'sale';
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

	public function createHeader ($recData, $options)
	{
		$hdr = $this->createPersonHeaderInfo ($recData['person'], $recData);
		$hdr ['icon'] = 'icon-thumbs-up';
		$docInfo [] = array ('text' => $recData ['contractNumber'] . ' ▪︎ ' .'Smlouva prodejní', 'icon' => 'system/iconFile');
		$hdr ['info'][] = array ('class' => 'title', 'value' => $docInfo);

		$now = time ();
		$itemIsActive = 1;
			if (strtotime(\E10\df($recData['start'])) > $now)
				$itemIsActive = 2;
		if ($recData['end'])
			if (strtotime(\E10\df($recData['end'])) < $now)
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

	public function tableIcon ($recData, $options = NULL)
	{
		$contractKind = $this->app()->cfgItem('e10doc.contracts.kinds.'.$recData['docKind'], NULL);

		if ($contractKind)
			return $contractKind ['icon'];

		return parent::tableIcon ($recData, $options);
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

	public function checkDocumentState (&$recData)
	{
		// -- check document number
		if ($recData['docStateMain'] == 1 || $recData['docStateMain'] == 2)
		{
			if (!isset ($recData['docNumber']) || $recData['docNumber'] === '' || $recData['docNumber'][0] === '!')
				$this->makeDocNumber ($recData);
		}
	}

	public function resetRowItem ($headRecData, &$rowRecData, $itemRecData)
	{
		$rowRecData ['text'] = $itemRecData['fullName'];
		$rowRecData ['unit'] = $itemRecData['defaultUnit'];

		$taxReg = E10Utils::primaryTaxRegCfg($this->app());
		$rowRecData ['priceItem'] = E10Utils::itemPriceSell($this->app(), $taxReg, $headRecData['taxCalc']+1, $itemRecData);
	}

	public function makeDocNumber (&$recData)
	{
		$formula = '';

		$dbCounter = $this->app()->cfgItem ('e10doc.contracts.dbCounters.'.$recData['dbCounter'], FALSE);
		$dbCounterId = ($dbCounter === FALSE) ? '1' : $dbCounter ['docKeyId'];

		if ($dbCounter && isset($dbCounter['docNumberFormula']) && $dbCounter['docNumberFormula'] !== '')
			$formula = trim($dbCounter['docNumberFormula']);

		if ($formula == '')
			$formula = '%I%y%3';

		if (utils::dateIsBlank($recData['start']))
			$recData['start'] = utils::today();

		if (is_string($recData['start']))
		{
			$da = new \DateTime ($recData['start']);
			$year2 = $da->format ('y');
			$year4 = $da->format ('Y');
			$month = $da->format ('m');
		}
		else
		{
			$year2 = $recData['start']->format ('y');
			$year4 = $recData['start']->format ('Y');
		}

		$recData['dbCounterYear'] = $year4;

		// make select code
		$q[] = 'SELECT MAX([dbCounterNdx]) AS maxDbCounterNdx FROM [e10doc_contracts_heads]';
		array_push ($q, ' WHERE [dbCounter] = %i', $recData['dbCounter']);
		if (strpos ($formula, '%y') !== FALSE || strpos ($formula, '%Y') !== FALSE)
			array_push ($q, ' AND [dbCounterYear] = %i', $recData['dbCounterYear']);

		$res = $this->db()->query ($q);
		$r = $res->fetch ();

		$firstNumber = 1;
		$dbCounterNdx = intval($r['maxDbCounterNdx']) + $firstNumber;

		$rep = [
			'%Y' => $year4,
			'%y' => $year2,
			'%I' => $dbCounterId,
			// %C - centre id -
			// %A - authors initials
			// %c - centre id - global numbering
			// %a - authors initials - global numbering
			'%2' => sprintf ('%02d', $dbCounterNdx),
			'%3' => sprintf ('%03d', $dbCounterNdx),
			'%4' => sprintf ('%04d', $dbCounterNdx),
			'%5' => sprintf ('%05d', $dbCounterNdx),
			'%6' => sprintf ('%06d', $dbCounterNdx)
		];
		$docNumber = strtr ($formula, $rep);

		$recData['docNumber'] = $docNumber;
		$recData['dbCounterNdx'] = $dbCounterNdx;

		return $docNumber;
	}

	public function applyContractKind(&$recData)
	{
		$modifiedCols = [];
		$ck = $this->app()->cfgItem('e10doc.contracts.kinds.'.$recData['docKind'], NULL);
		if ($ck)
		{
			$askPeriod = isset($ck['periodOC']) ? $ck['periodOC'] : 1;
			if (!$askPeriod)
			{
				$recData['period'] = $ck['period'];
				$modifiedCols[] = 'period';
			}

			$askInvoicingDay = isset($ck['invoicingDayOC']) ? $ck['invoicingDayOC'] : 1;
			if (!$askInvoicingDay)
			{
				$recData['invoicingDay'] = $ck['invoicingDay'];
				$modifiedCols[] = 'invoicingDay';
			}

			$askDstDocType = isset($ck['dstDocTypeOC']) ? $ck['dstDocTypeOC'] : 1;
			if (!$askDstDocType)
			{
				$recData['dstDocType'] = $ck['dstDocType'];
				$modifiedCols[] = 'dstDocType';
			}

			$askDstDocKind = isset($ck['dstDocKindOC']) ? $ck['dstDocKindOC'] : 1;
			if (!$askDstDocKind)
			{
				$recData['dstDocKind'] = $ck['dstDocKind'];
				$modifiedCols[] = 'dstDocKind';
			}

			$askTaxCalc = isset($ck['taxCalcOC']) ? $ck['taxCalcOC'] : 1;
			if (!$askTaxCalc)
			{
				$recData['taxCalc'] = $ck['taxCalc'];
				$modifiedCols[] = 'taxCalc';
			}

			$askTitle = isset($ck['titleOC']) ? $ck['titleOC'] : 1;
			if (!$askTitle)
			{
				$recData['title'] = $ck['title'];
				$modifiedCols[] = 'title';
			}

			$askCurrency = isset($ck['currencyOC']) ? $ck['currencyOC'] : 1;
			if (!$askCurrency)
			{
				$recData['currency'] = $ck['currency'];
				$modifiedCols[] = 'currency';
			}

			$askPaymentMethod = isset($ck['paymentMethodOC']) ? $ck['paymentMethodOC'] : 1;
			if (!$askPaymentMethod)
			{
				$recData['paymentMethod'] = $ck['paymentMethod'];
				$modifiedCols[] = 'paymentMethod';
			}

			$askMyBankAccount = isset($ck['myBankAccountOC']) ? $ck['myBankAccountOC'] : 1;
			if (!$askMyBankAccount)
			{
				$recData['myBankAccount'] = $ck['myBankAccount'];
				$modifiedCols[] = 'myBankAccount';
			}

			$askDueDays = isset($ck['dueDaysOC']) ? $ck['dueDaysOC'] : 1;
			if (!$askDueDays)
			{
				$recData['dueDays'] = $ck['dueDays'];
				$modifiedCols[] = 'dueDays';
			}

			$askCentre = isset($ck['centreOC']) ? $ck['centreOC'] : 1;
			if (!$askCentre)
			{
				$recData['centre'] = $ck['centre'];
				$modifiedCols[] = 'centre';
			}

			$askProject = isset($ck['wkfProjectOC']) ? $ck['wkfProjectOC'] : 1;
			if (!$askProject)
			{
				$recData['wkfProject'] = $ck['wkfProject'];
				$modifiedCols[] = 'wkfProject';
			}

			$askWorkOrder = isset($ck['workOrderOC']) ? $ck['workOrderOC'] : 1;
			if (!$askWorkOrder)
			{
				$recData['workOrder'] = $ck['workOrder'];
				$modifiedCols[] = 'workOrder';
			}

			$recData['createOffsetValue'] = (isset($ck['createOffsetValue'])) ? $ck['createOffsetValue'] : 0;
			$recData['createOffsetUnit'] = (isset($ck['createOffsetUnit'])) ? $ck['createOffsetUnit'] : 0;

			$recData['dstDocState'] = (isset($ck['dstDocState'])) ? $ck['dstDocState'] : 0;
			$recData['dstDocAutoSend'] = (isset($ck['dstDocAutoSend'])) ? $ck['dstDocAutoSend'] : 0;
		}

		if ($recData['dstDocType'] == '')
			$recData['dstDocType'] = 'invno';

		return $modifiedCols;
	}
}


/**
 * Class ViewHeads
 * @package E10Doc\Contracts\Core
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
		$this->docNumbers = $this->app()->cfgItem('e10doc.contracts.dbCounters', []);
		$this->contractKinds = $this->app()->cfgItem('e10doc.contracts.kinds', []);

		parent::init();

		if (isset ($this->docType))
			$this->addAddParam ('docType', $this->docType);

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'ended', 'title' => 'Ukončené'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);

		// -- bottom tabs
		if (count($this->docNumbers) > 1)
		{
			$active = 1;
			forEach ($this->docNumbers as $dnId => $dn)
			{
				$addParams = ['dbCounter' => $dnId];
				$nbt = ['id' => $dnId, 'title' => ($dn['tn'] !== '') ? $dn['tn'] : $dn['sn'], 'active' => $active, 'addParams' => $addParams];
				$bt [] = $nbt;
				$active = 0;
			}

			//$nbt = ['id' => 'ALL', 'title' => 'Vše', 'active' => 0];
			//$bt [] = $nbt;

			$this->setBottomTabs ($bt);
			$this->btMode = self::btmDocNumbers;
		}
		elseif (count($this->contractKinds) > 1)
		{
			$active = 1;
			forEach ($this->contractKinds as $ckId => $ck)
			{
				$addParams = ['docKind' => $ckId];
				$nbt = ['id' => $ckId, 'title' => ($ck['tn'] !== '') ? $ck['tn'] : $ck['sn'], 'active' => $active, 'addParams' => $addParams];
				$bt [] = $nbt;
				$active = 0;
			}

			$nbt = ['id' => 'ALL', 'title' => 'Vše', 'active' => 0];
			$bt [] = $nbt;

			$this->setBottomTabs ($bt);
			$this->btMode = self::btmDocKinds;

			$activeDbCounter = key($this->docNumbers);
			$this->addAddParam ('dbCounter', $activeDbCounter);
		}

		$panels = [];
		$panels [] = ['id' => 'qry', 'title' => 'Hledání'];
		$this->setPanels($panels);
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
		array_push($q, ' FROM [e10doc_contracts_heads] AS [heads]');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS [persons] ON [heads].[person] = [persons].[ndx]');
		array_push($q, ' WHERE 1');
		//array_push($q, '');
		//array_push($q, '');
		//array_push($q, '');

		// -- docType
		if (isset ($this->docType))
      array_push ($q, ' AND [heads].[docType] = %s', $this->docType);

		if ($bottomTabId !== 'ALL')
		{
			if ($this->btMode === self::btmDocKinds)
				array_push($q, ' AND [heads].[docKind] = %s', $bottomTabId);
			elseif ($this->btMode === self::btmDocNumbers)
				array_push($q, ' AND [heads].[dbCounter] = %s', $bottomTabId);
		}

		// -- fulltext
		if ($fts != '')
		{
 			array_push ($q, ' AND ([persons].[fullName] LIKE %s', '%'.$fts.'%');
 			array_push ($q, ' OR [heads].[contractNumber] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [heads].[title] LIKE %s)', '%'.$fts.'%');
		}

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


		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, ' AND heads.[docStateMain] < %i', 4);
		elseif ($mainQuery == 'ended')
      array_push ($q, ' AND heads.[docStateMain] = %i', 5);
		elseif ($mainQuery == 'trash')
      array_push ($q, ' AND heads.[docStateMain] = %i', 4);

		if ($mainQuery == 'all')
			array_push ($q, ' ORDER BY [heads].[contractNumber] DESC');
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [heads].[contractNumber] DESC');

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
		$listItem ['t1'] = $item['personFullName'];

		$now = time ();
		if ($item['start'])
			if (strtotime($item['start']->format('Ymd')) > $now)
				$listItem['i1'] = ['i' => 'pause', 'text' => ''];
		if ($item['end'])
			if (strtotime($item['end']->format('Ymd')) < $now)
				$listItem['i1'] = ['i' => 'stop', 'text' => ''];
		if ($item['docState'] == 9000 || $item['docState'] == 9800)
			$listItem['i1'] = ['i' => 'stop', 'text' => ''];

		$listItem ['i2'] = $item['contractNumber'];

		$props [] = ['icon' => 'system/iconFile', 'text' => $item['docNumber'], 'class' => ''];

		$props [] = ['icon' => 'system/actionPlay', 'text' => utils::datef ($item['start'], '%D'), 'class' => ''];
		if ($item['end'])
			$props [] = ['icon' => 'system/iconStop', 'text' => utils::datef ($item['end'], '%D'), 'class' => ''];

		$ck = isset($this->contractKinds[$item['docKind']]) ? $this->contractKinds[$item['docKind']] : NULL;
		if ($ck)
		{
			if ($this->btMode === self::btmDocKinds && $bottomTabId === 'ALL')
				$props[] = ['text' => $ck['fn'], 'icon' => $ck['icon'], 'class' => 'label label-default'];
		}

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

		// -- tags
		UtilsBase::addClassificationParamsToPanel($this->table, $panel, $qry);

		$panel->addContent(['type' => 'query', 'query' => $qry]);
	}
}


/**
 * Class ViewDetailHead
 * @package E10Doc\Contracts\Core
 */
class ViewDetailHead extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.contracts.core.dc.ContractSale');
	}
}


/**
 * Class FormHead
 * @package E10Doc\Contracts\Core
 */
class FormHead extends TableForm
{
	var $contractKind = NULL;

	public function renderForm ()
	{
		$useContractKind = count($this->app()->cfgItem('e10doc.contracts.kinds', []));
		$this->contractKind = $this->app()->cfgItem('e10doc.contracts.kinds.'.$this->recData['docKind'], []);

		$askPeriod = isset($this->contractKind['periodOC']) ? $this->contractKind['periodOC'] : 1;
		$askInvoicingDay = isset($this->contractKind['invoicingDayOC']) ? $this->contractKind['invoicingDayOC'] : 1;
		$askDstDocType = isset($this->contractKind['dstDocTypeOC']) ? $this->contractKind['dstDocTypeOC'] : 1;
		$askDstDocKind = isset($this->contractKind['dstDocKindOC']) ? $this->contractKind['dstDocKindOC'] : 1;
		$askTaxCalc = isset($this->contractKind['taxCalcOC']) ? $this->contractKind['taxCalcOC'] : 1;
		$askTitle = isset($this->contractKind['titleOC']) ? $this->contractKind['titleOC'] : 1;
		$askCurrency = isset($this->contractKind['currencyOC']) ? $this->contractKind['currencyOC'] : 1;
		$askPaymentMethod = isset($this->contractKind['paymentMethodOC']) ? $this->contractKind['paymentMethodOC'] : 1;
		$askMyBankAccount = isset($this->contractKind['myBankAccountOC']) ? $this->contractKind['myBankAccountOC'] : 1;
		$askDueDays = isset($this->contractKind['dueDaysOC']) ? $this->contractKind['dueDaysOC'] : 1;
		$askCentre = isset($this->contractKind['centreOC']) ? $this->contractKind['centreOC'] : 1;
		$askProject = isset($this->contractKind['wkfProjectOC']) ? $this->contractKind['wkfProjectOC'] : 1;
		$askWorkOrder = isset($this->contractKind['workOrderOC']) ? $this->contractKind['workOrderOC'] : 1;



		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm (TableForm::ltNone);

			$tabs ['tabs'][] = ['text' => 'Záhlaví', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Poznámka', 'icon' => 'system/formNote'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);

			$this->openTab (TableForm::ltNone);
				$this->layoutOpen (TableForm::ltDocMain);
					$this->layoutOpen (TableForm::ltForm);
						if ($useContractKind)
						{
							$this->addColumnInput('docKind');
							$this->addSeparator(self::coH2);
						}

						$this->layoutOpen (TableForm::ltVertical);
							$this->addColumnInput ('person');
						$this->layoutClose ();
						$this->addSeparator(self::coH2);
						$this->addColumnInput ('contractNumber');
						$this->addColumnInput ('start');
						$this->addColumnInput ('end');

						if ($askDstDocType)
							$this->addColumnInput ('dstDocType');
						if ($askDstDocKind)
							$this->addColumnInput ('dstDocKind');
						if ($askCurrency)
							$this->addColumnInput ('currency');
						if ($askPeriod)
							$this->addColumnInput ('period');
						if ($askInvoicingDay)
							$this->addColumnInput ('invoicingDay');
						if ($askTaxCalc)
							$this->addColumnInput ('taxCalc');
						if ($askPaymentMethod)
							$this->addColumnInput ('paymentMethod');
						if ($askMyBankAccount)
							$this->addColumnInput ('myBankAccount');
						if ($askDueDays)
							$this->addColumnInput ('dueDays');

						if ($askCentre && $this->table->app()->cfgItem ('options.core.useCentres', 0))
							$this->addColumnInput ('centre');
						if ($askProject && $this->table->app()->cfgItem ('options.core.useProjects', 0))
							$this->addColumnInput ('project');
						if ($askWorkOrder)
							$this->addColumnInput ('workOrder');

						$this->addColumnInput ('bookingPlaces');

						$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					$this->layoutClose ();

					$this->layoutOpen (TableForm::ltVertical);
						if ($askTitle)
							$this->addColumnInput ('title');
					$this->layoutClose ();
				$this->layoutClose ();

				$this->layoutOpen (TableForm::ltDocRows);
					$this->addList ('rows');
				$this->layoutClose ();
    	$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addInputMemo ("invNote", NULL, TableForm::coFullSizeY);
			$this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->closeTabs ();

		$this->closeForm ();
	}

	public function checkAfterSave ()
	{
		if ($this->recData['docNumber'] == '')
			$this->recData['docNumber'] = '!'.sprintf ('%09d', $this->recData['ndx']);

		return TRUE;
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.contracts.core.rows' && $srcColumnId === 'item')
		{
			return array ('docType' => 'invno', 'operation' => ['1010001', '1010002', '1099998'], 'docDir' => '2');
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

	public function validNewDocumentState ($newDocState, $saveData)
	{
		if ($newDocState === 9000)
		{
			if (Utils::dateIsBlank($saveData['recData']['end']))
			{
				$this->setColumnState('end', utils::es ('Hodnota'." `".$this->columnLabel($this->table->column ('end'), 0)."` ".'není vyplněna'));
				return FALSE;
			}
		}

		return parent::validNewDocumentState($newDocState, $saveData);
	}
}

