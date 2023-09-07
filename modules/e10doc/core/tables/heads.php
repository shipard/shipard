<?php

namespace E10Doc\Core;

require_once __SHPD_MODULES_DIR__ . 'e10doc/core/core.php';

use \E10\Application, \E10\utils, \E10\FormReport, \Shipard\Form\FormSidebar, \Shipard\Viewer\TableViewPanel;
use E10\ContentRenderer;
use \E10\TableViewDetail;
use \E10\DbTable;
use \E10\TableView;
use \Shipard\Form\TableForm;
use \e10doc\core\libs\DocsModes;
use \Shipard\Utils\World;
use \e10doc\core\libs\E10Utils;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Str;


CONST docDir_None = 0, docDir_In = 1, docDir_Out = 2;
CONST bottomTabs_None = 0, bottomTabs_DbCounters = 1, bottomTabs_Warehouses = 2;


/**
 * Hlavičky Dokladů
 *
 */

class TableHeads extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10doc.core.heads", "e10doc_core_heads", "Doklady", 1078);
	}

	public function accountingDocument ($recData)
	{
		if ($this->app()->model()->table ('e10doc.debs.journal') === FALSE)
			return FALSE;

		$fiscalYear = $this->app()->cfgItem ('e10doc.acc.periods.'.$recData ['fiscalYear'], FALSE);
		if ($fiscalYear && $fiscalYear['method'] === 'none')
			return FALSE;

		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData ['docType'], FALSE);

		if (isset($docType['stockAccMethod']) && $fiscalYear['stockAccMethod'] !== $docType['stockAccMethod'])
			return FALSE;

		return utils::param ($docType, 'acc', 0);
	}

	public function balanceDocument ($recData)
	{
		if ($this->app()->model()->table ('e10doc.balance.journal') === FALSE)
			return FALSE;

		$balances = $this->app()->cfgItem ('e10.balance', FALSE);
		if ($balances !== FALSE)
		{
			forEach ($balances as $b)
			{
				if (in_array($recData['docType'], $b['docTypes']))
					return TRUE;
			}
		}

		return FALSE;
	}

	public function inventoryDocument ($recData)
	{
		if ($this->app()->model()->table ('e10doc.inventory.journal') === FALSE)
			return FALSE;

		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData ['docType'], FALSE);
		if (isset ($docType['invDirection']))
			return TRUE;
		if ($recData ['docType'] === 'mnf')
			return TRUE;
		if ($recData ['docType'] === 'cash' || $recData ['docType'] === 'invni')
			return TRUE;

		return FALSE;
	}

	public function cfgItem ($recData, $key, $defaultValue = false)
	{
		return $this->app()->cfgItem ($key, $defaultValue);
	}

	public function cfgItemTaxPayer ($recData)
	{
		$vatPeriods = $this->app()->cfgItem ('e10doc.vatPeriods', FALSE);
		if ($vatPeriods === FALSE)
			return 0;
		$dateTax = utils::createDateTime ($recData ['dateTax']);
		if (!$dateTax || utils::dateIsBlank($dateTax))
			$dateTax = utils::today();

		forEach ($vatPeriods as $itm)
		{
			if ($itm['vatReg'] != $recData['vatReg'])
				continue;
			$dateFrom = utils::createDateTime ($itm ['begin']);
			$dateTo = utils::createDateTime ($itm ['end']);

			if (($dateFrom) && ($dateFrom->format('Ymd') > $dateTax->format('Ymd')))
				continue;
			if (($dateTo) && ($dateTo->format('Ymd') < $dateTax->format('Ymd')))
				continue;

			return 1;
		}

		return 0;
	}

	public function documentOpen ($docNdx, $options = 0)
	{
		$f = $this->getTableForm ('edit', $docNdx);

		if ($f->recData['docStateMain'] <= 1)
			return;

		$f->recData['docStateMain'] = 0;
		$f->recData['docState'] = 8000;

		$f->checkAfterSave();
		$this->dbUpdateRec ($f->recData);
		$this->checkAfterSave2 ($f->recData);
	}

	public function documentClose ($ndx)
	{
	}

	public function documentCard ($recData, $objectType)
	{
		$o = NULL;
		$docType = $this->app()->cfgItem ('e10.docs.types.'.$recData['docType'], FALSE);

		if ($docType && isset($docType['documentCard']))
			$o = $this->app()->createObject($docType['documentCard']);
		else
		 $o = $this->app()->createObject('e10doc.core.dc.Detail');

		if ($o)
			$o->dstObjectType = $objectType;

		return $o;
	}

	public function checkAccessToDocument ($recData)
	{
		if (PHP_SAPI === 'cli')
			return 2;

		if ($this->app()->hasRole ('all'))
			return 2;

		$allRoles = $this->app()->cfgItem ('e10.persons.roles');
		$userRoles = $this->app()->user()->data ('roles');

		$accessLevel = 0;

		forEach ($userRoles as $roleId)
		{
			$r = $allRoles[$roleId];

			if (!isset ($r['documents']) || !isset ($r['documents'][$this->tableId]))
				continue;

			foreach ($r['documents'][$this->tableId] as $rights)
			{
				$found = 1;
				foreach ($rights as $columnId => $columnValue)
				{
					if ($columnId[0] === '_')
						continue;
					if (!isset($recData[$columnId]))
					{
						$found = 0;
						continue;
					}
					$recDataColValue = $recData[$columnId];
					if (is_array($columnValue))
					{
						if (!in_array($recDataColValue, $columnValue))
						{
							$found = 0;
							continue;
						}
					}
					else
					if ($recDataColValue != $columnValue)
					{
						$found = 0;
						continue;
					}
				}

				if ($found && $rights['_access'] > $accessLevel)
					$accessLevel = $rights['_access'];
				if ($accessLevel === 2)
					return 2;
			}
		}

		if ($this->app()->hasRole ('audit') && $accessLevel < 1)
			$accessLevel = 1;

		return $accessLevel;
	}

	public function getDiaryInfo($recData)
	{
		/** @var \e10doc\helpers\TableWkfSectionsRelations $tableWkfSectionsRelations */
		$tableWkfSectionsRelations = $this->app()->table ('e10doc.helpers.wkfSectionsRelations');

		$ds = [];
		$tableWkfSectionsRelations->documentSections($recData, $ds);
		$info = [];

		if (count($ds))
			$info['sectionNdx'] = $ds[0];

		return $info;
	}


	public function rolesQuery (&$q)
	{
		if ($this->app()->hasRole ('all'))
			return TRUE;

		$allRoles = $this->app()->cfgItem('e10.persons.roles');
		$userRoles = $this->app()->user()->data ('roles');

		$columnsValues = [];

		forEach ($userRoles as $roleId)
		{
			$r = $allRoles[$roleId];

			if (!isset($r['documents']))
				continue;
			if (!isset($r['documents']['e10doc.core.heads']))
				continue;

			foreach ($r['documents']['e10doc.core.heads'] as $tableDoc)
			{
				$qryId = '';
				forEach ($tableDoc as $colId => $colValue)
				{
					if ($colId[0] === '_')
						continue;
					$qryId .= $colId.'-';
				}

				if (!isset($columnsValues[$qryId]))
					$columnsValues[$qryId] = [];

				forEach ($tableDoc as $colId => $colValue)
				{
					if ($colId[0] === '_')
						continue;

					if (!isset($columnsValues[$qryId][$colId]))
						$columnsValues[$qryId][$colId] = [];

					if (is_array($colValue))
					{
						foreach ($colValue as $ocv)
							if (!in_array($ocv, $columnsValues[$qryId][$colId]))
								$columnsValues[$qryId][$colId][] = $ocv;
					}
					else
					{
						if (!in_array($colValue, $columnsValues[$qryId][$colId]))
							$columnsValues[$qryId][$colId][] = $colValue;
					}
				}
			}
		}

		if (!count($columnsValues))
			return FALSE;

		array_push ($q, ' AND (');

		$idx = 0;
		foreach ($columnsValues as $cq)
		{
			if ($idx)
				array_push ($q, ' OR ');
			array_push ($q, '(');
			$idx2 = 0;
			foreach ($cq as $colId => $colValue)
			{
				if ($idx2)
					array_push ($q, ' AND ');
				if (count($colValue) > 1)
					array_push ($q, "[$colId] IN %in", $colValue);
				else
					array_push ($q, "[$colId] = %s", $colValue[0]);
				$idx2++;
			}
			array_push ($q, ')');
			$idx++;
		}
		array_push ($q, ')');

		return TRUE;
	}

	public function checkAfterSave2 (&$recData)
	{
		if ($this->app()->model()->table ('e10doc.inventory.journal') !== FALSE)
			$this->doInventory ($recData);

		if ($this->app()->model()->table ('e10doc.balance.journal') !== FALSE)
			$this->doBalance ($recData);

		if ($this->app()->model()->table ('e10doc.debs.journal') !== FALSE)
			$this->doAccounting ($recData);

		if ($this->app()->model()->table ('e10pro.reports.waste_cz.returnRows') !== FALSE)
			$this->doWaste ($recData);

		$this->doTaxReports($recData);
		$this->doRos($recData);
		$this->doInbox($recData);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset($recData['vatReg']) || !$recData['vatReg'])
		{
			$vatRegs = $this->app()->cfgItem('e10doc.base.taxRegs', NULL);
			if ($vatRegs)
				$recData['vatReg'] = key($vatRegs);
			else
				$recData['vatReg'] = 0;
		}
		$tax = $this->cfgItemTaxPayer ($recData);
		$recData ['taxPayer'] = $tax;

    // check undefined values...
    if ($recData ['homeCurrency'] == '')
      $recData ['homeCurrency'] = utils::homeCurrency($this->app(), $recData['dateAccounting']);
		if ($recData ['currency'] == '')
			$recData ['currency'] = $recData ['homeCurrency'];
    if ($recData ['exchangeRate'] == 0)
      $recData ['exchangeRate'] = 1;

		// -- test home vs doc currency
		if ($recData ['currency'] === $recData ['homeCurrency'])
			$recData ['exchangeRate'] = 1;

		// activating
		if (!isset ($recData['activateCnt']))
			$recData['activateCnt'] = 0;
		if ($recData['activateCnt'] == 0)
		{
			$recData['activateTimeFirst'] = utils::now();
			$recData['activateTimeLast'] = utils::now();
			$recData['activateDateFirst'] = utils::today();
		}

		// -- cash
		if ($recData['docType'] === 'cash' && $recData ['paymentMethod'] != 2)
		{
			$recData ['paymentMethod'] = 1; //always cash
			if (isset($recData['collectingDoc']) && $recData['collectingDoc'])
				$recData ['taxMethod'] = 2;
		}

		// paymentMethod vs cashBox
		$paymentMethod = $this->app()->cfgItem ('e10.docs.paymentMethods.' . $recData['paymentMethod'], 0);
		if ($paymentMethod ['cash'])
		{
			if (!isset ($recData['cashBox']) || $recData['cashBox'] == 0)
			{
				$cashBoxes = $this->app()->cfgItem ('e10doc.cashBoxes', array());
				$recData['cashBox'] = key ($cashBoxes);
			}
		}

		// -- roundMethod - automatic mode on sale
		$automaticRound = isset($recData['automaticRound']) ? intval($recData['automaticRound']) : 0;
		if ($automaticRound && ($recData['docType'] === 'invno' || $recData['docType'] === 'cashreg' || $recData['docType'] === 'invpo' || $recData['docType'] === 'cash'))
		{
			switch ($recData['paymentMethod'])
			{
				case 1: // cash
				case 3: // cash on delivery
				case 5: // cash doc
				case 9: // cheque
				case 10: // postal money order
									$recData['roundMethod'] = 1;
									break;
				default:
									$recData['roundMethod'] = 0;
									break;
			}
		}

		// -- taxMethod
		$docType = $this->app()->cfgItem ('e10.docs.types.'.$recData['docType'], FALSE);
		if ($docType && $docType['useTax'] === 0) // if no tax,
			$recData ['taxMethod'] = 2;	// sum from lines

		// -- docKind & activity
		$recData['activity'] = '';
		if ($recData['docKind'] != 0)
		{
			$docKind = $this->app()->cfgItem ('e10.docs.kinds.'.$recData['docKind'], FALSE);
			if ($docKind && $docKind['activity'])
				$recData['activity'] = $docKind['activity'];
		}

		// -- taxManual
		if ($recData['taxPayer'] && $recData['taxCalc'] != 0)
		{
			if ($recData['docType'] !== 'invni' && ($recData['docType'] === 'cash' && $recData['cashBoxDir'] != 2))
				$recData['taxManual'] = 0;
		}
		else
			$recData['taxManual'] = 0;

		$manualVatPeriod = $this->app()->cfgItem ('e10.docs.types.'.$recData['docType'].'.activities.'.$recData['activity'], FALSE);
		if (!$tax || ($manualVatPeriod === FALSE && $recData['taxCalc'] === 0))
		{ // u neplátce nebo u nedaňového dokladu 'není' DUZP
			$recData ['dateTax'] = $recData ['dateAccounting'];
		}
		else
		if ($manualVatPeriod !== FALSE)
		{ // pokud to ovšem není manuální volba období (přiznání DPH)
			$vatPeriod = $this->app()->cfgItem ('e10doc.vatPeriods.'.$recData['taxPeriod'], FALSE);
			if ($vatPeriod)
				$recData ['dateTax'] = $vatPeriod['end'];
		}

		if ($recData['docType'] === 'invno' || $recData ['docType'] === 'cashreg')
			$recData ['dateTaxDuty'] = $recData ['dateTax'];

		// -- personBalance / payment terminal / cash on delivery
		$recData ['personBalance'] = $recData ['person'];
		if ($recData['paymentMethod'] == 2)
		{ // payment terminal
			if ($recData['docType'] === 'invno' || $recData ['docType'] === 'cashreg' || ($recData ['docType'] === 'cash' && $recData['cashBoxDir'] == 1))
			{
				$cashBox = $this->app()->cfgItem ('e10doc.cashBoxes.'.$recData['cashBox'], FALSE);
				if ($cashBox && isset($cashBox['pt']))
				{
					if (!isset($recData['payTerminal']) || !$recData['payTerminal'] || !in_array($recData['payTerminal'], $cashBox['pt']))
						$recData['payTerminal'] = $cashBox['pt'][0];
				}
				$payTerminal = $this->app()->cfgItem ('e10.terminals.payTerminals.'.$recData['payTerminal'], FALSE);
				if ($payTerminal && $payTerminal['pb'])
					$recData['personBalance'] = $payTerminal['pb'];
			}
		}
		elseif ($recData['paymentMethod'] == 3)
		{ // cash on delivery
			if ($recData['docType'] === 'invno' || $recData ['docType'] === 'cashreg' || ($recData ['docType'] === 'cash' && $recData['cashBoxDir'] == 1))
			{
				$transport = $this->app()->cfgItem ('e10doc.transports.'.$recData['transport'], FALSE);
				if ($transport && $transport['pb'])
					$recData['personBalance'] = $transport['pb'];
			}
		}

		// -- vatReg & taxCountry
		if ($recData['vatReg'])
		{
			$vatReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$recData['vatReg'], NULL);
			if ($vatReg && $vatReg['payerKind'] === 0)
			{ // regular payer - not OSS
				$recData['taxCountry'] = $vatReg['taxCountry'];
			}
			else
			{
				$recData['taxType'] = 0;
			}
		}
		else
		{
			$recData['taxCountry'] = '';
		}

		$this->checkColumnsSettings($recData);

		parent::checkBeforeSave ($recData, $ownerData);
  }

	protected function checkChangedInput ($changedInput, &$saveData)
	{
		$colNameParts = explode ('.', $changedInput);

		if ($changedInput === 'person')
		{
			$saveData['recData']['deliveryAddress'] = 0;
			$saveData['recData']['bankAccount'] = '';
			$saveData['recData']['personVATIN'] = '';

			$this->resetPersonType ($saveData['recData']);
			return;
		}

		if ($changedInput === 'transport')
		{
			$transportCfg = $this->app()->cfgItem('e10doc.transports.'.$saveData['recData']['transport'], NULL);
			if ($transportCfg)
			{
				$saveData['recData']['transportVLP'] = $transportCfg['vehicleLP'];
				$saveData['recData']['transportPersonDriver'] = $transportCfg['vehicleDriver'];
			}
			else
			{
				$saveData['recData']['transportVLP'] = '';
				$saveData['recData']['transportPersonDriver'] = 0;
			}
			return;
		}

		// -- row item reset
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'item')
		{
			if (isset ($saveData['softChangeInput']))
			{
				$saveData['lists']['rows'][$colNameParts[2]]['itemType'] = '';
				$saveData['lists']['rows'][$colNameParts[2]]['_fixTaxCode'] = 1;
			}
			else
			{
				$item = $this->loadItem ($saveData['lists']['rows'][$colNameParts[2]]['item'], 'e10_witems_items');
				$docType = $this->app()->cfgItem ('e10.docs.types.' . $saveData ['recData']['docType']);
				$this->resetRowItem ($saveData['recData'], $saveData['lists']['rows'][$colNameParts[2]], $item, $docType);
			}
			return;
		}

		// -- row quantity change
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'quantity')
		{
			$saveData['lists']['rows'][$colNameParts[2]]['itemIsSet'] = 2;
			return;
		}
		// -- row price change
		if (count ($colNameParts) === 4 && $colNameParts[1] === 'rows' && $colNameParts[3] === 'priceItem')
		{
			$saveData['lists']['rows'][$colNameParts[2]]['itemIsSet'] = 2;
			return;
		}
		// -- currency
		if ($changedInput === 'currency')
		{
			if (isset($saveData['recData']['dateAccounting']) && !utils::dateIsBlank($saveData['recData']['dateAccounting']))
			{
				$er = e10utils::exchangeRate($this->app(), $saveData['recData']['dateAccounting'], $saveData['recData']['homeCurrency'], $saveData['recData']['currency']);
				if ($er !== 0)
				{
					$saveData['recData']['exchangeRate'] = $er;
					$saveData['recData']['dateExchRate'] = $saveData['recData']['dateAccounting'];
				}
			}
			return;
		}
	}

	public function checkColumnsSettings (&$recData)
	{
		if (isset($recData['activity']) && $recData['activity'] != '')
		{
			$activity = $this->app()->cfgItem ('e10.docs.types.'.$recData['docType'].'.activities.'.$recData['activity'], FALSE);
			if ($activity)
				$this->setColumns ($recData, $activity);
		}
	}

  public function checkDocumentState (&$recData)
	{
		// -- activating
		if (!isset ($recData['activateCnt']))
			$recData['activateCnt'] = 0;
		if ($recData['activateCnt'] == 0)
		{
			$recData['activateTimeFirst'] = utils::now();
			$recData['activateTimeLast'] = utils::now();
			$recData['activateDateFirst'] = utils::today();

			$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData ['docType']);
			$resetDates = isset ($docType ['resetDatesWhenDone']) ? $docType ['resetDatesWhenDone'] : 0;
			if ($resetDates)
			{
				$recData ['dateIssue'] = utils::today();
				$recData ['dateAccounting'] = utils::today();
				$recData ['dateTax'] = utils::today();
				$recData ['dateDue'] = utils::today();
				$recData ['dateTaxDuty'] = NULL;
			}
		}

		switch ($recData['docStateMain'])
		{
			case	2:
							if ($recData['activateCnt'] != 0)
								$recData['activateTimeLast'] = utils::now();
							$recData['activateCnt']++;
							break;
		}

    $this->findAndSetFiscalPeriod ($recData);
    $this->findAndSetTaxPeriod ($recData);
		$this->resetPersonType ($recData);

		// -- check document number
		if ($recData['docStateMain'] == 1 || $recData['docStateMain'] == 2)
		{
			if (!isset ($recData['docNumber']) || $recData['docNumber'] === '' || $recData['docNumber'][0] === '!')
				$recData['docNumber'] = $this->makeDocNumber ($recData);
		}

		if (($recData['docStateMain'] == 1 || $recData['docStateMain'] == 2))
		{
			if ($recData ['docType'] === 'invno' || $recData ['docType'] === 'invpo' ||
					$recData ['docType'] === 'purchase' || $recData ['docType'] === 'cashreg' ||
					$recData ['docType'] === 'stockin' || $recData ['docType'] === 'stockout' ||
					($recData ['docType'] === 'cash' && ($recData ['paymentMethod'] !== 1)))
			{
				if (!isset($recData ['symbol1']) || $recData ['symbol1'] === '')
					$recData ['symbol1'] = $recData ['docNumber'];
			}
			if ($recData ['docType'] === 'cash' && ($recData ['paymentMethod'] !== 1))
			{
				if (!isset($recData ['dateDue']))
					$recData ['dateDue'] = $recData ['dateAccounting'];
			}
			if ($recData ['docType'] === 'invni' || $recData ['docType'] === 'invno')
			{
				if (!isset($recData ['docId']) || $recData ['docId'] === '')
					$recData ['docId'] = $recData ['symbol1'];
			}
			else
			{
				if ($recData ['docType'] === 'cash' && $recData['cashBoxDir'] == 1)
				{
					if (!isset($recData ['docId']) || $recData ['docId'] === '')
						$recData ['docId'] = $recData ['symbol1'];
				}
				else
				{
					if ($recData ['docType'] !== 'cash' && $recData['cashBoxDir'] != 2)
						$recData ['docId'] = $recData ['docNumber'];
				}
			}
			if (!isset($recData ['dateTaxDuty']) || utils::dateIsBlank($recData ['dateTaxDuty']))
				$recData ['dateTaxDuty'] = $recData ['dateTax'];
		}

		$docStates = $this->documentStates ($recData);
		$ds = $docStates['states'][$recData['docState']];
		if (isset ($ds['enableScan']) && $recData['ndx'])
		{
			if ($ds['enableScan'])
				DocsModes::on ($this->app(), DocsModes::dmScanToDocument, 'e10doc.core.heads', $recData['ndx'], '', '');
			else
				DocsModes::off ($this->app(), DocsModes::dmScanToDocument, 'e10doc.core.heads', $recData['ndx']);
		}


		if (in_array($recData['docType'], ['bank', 'purchase', 'cashreg']))
			$this->app()->cache->invalidate('e10doc.core.heads', $recData['docType']);
		if ($recData['totalCash'] != 0.0)
			$this->app()->cache->invalidate('e10doc.core.heads', 'cash');
		$this->app()->cache->invalidate('e10doc.core.heads', 'ALL');
	}

	public function createNewDoc($recData)
	{
		$nrd = [];
		$this->checkNewRec($nrd);

		if (isset($nrd['ddfId']) && isset($nrd['ddfNdx']))
		{
			/** @var \e10\base\TableDocDataFiles $tableDocDataFiles */
			$tableDocDataFiles = $this->app()->table('e10.base.docDataFiles');
			/** @var \lib\docDataFiles\DocDataFile $ddfObject */
			$ddfObject = $tableDocDataFiles->ddfObject(NULL, $nrd['ddfNdx']);
			if ($ddfObject)
			{
				$ddfObject->createDocument($nrd);
				return $ddfObject->docRecData;
			}
		}
		return parent::createNewDoc($recData);
	}

  public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (isset($recData['fromIssueNdx']))
			$this->checkInboxDocument($recData['fromIssueNdx'], $recData);

		if (!isset($recData ['dateIssue']))
			$recData ['dateIssue'] = utils::today();
		if (!isset($recData ['dateAccounting']))
			$recData ['dateAccounting'] = $recData ['dateIssue'];
		if (!isset($recData ['dateTax']))
			$recData ['dateTax'] = $recData ['dateIssue'];

		if (!isset($recData ['collectingDoc']))
			$recData['collectingDoc'] = 0;

		if (!isset($recData ['title']))
			$recData ['title'] = '';

		$recData ['taxPeriod'] = 0;
		$recData ['fiscalYear'] = 0;
		$recData ['fiscalMonth'] = 0;
		if (!isset($recData ['taxType']))
			$recData ['taxType'] = 0;
		if (!isset($recData ['taxRoundMethod']))
			$recData ['taxRoundMethod'] = 0;
		$recData ['taxPercentDateType'] = 0;

		if (!isset ($recData ['homeCurrency']))
			$recData ['homeCurrency'] = utils::homeCurrency($this->app(), $recData['dateAccounting']);
		if (!isset($recData ['currency']) || $recData ['currency'] === '')
			$recData ['currency'] = $recData ['homeCurrency'];

		if (!isset($recData ['exchangeRate']))
    	$recData ['exchangeRate'] = 1;

		if (!isset($recData ['author']) && is_object($this->app()->user))
			$recData ['author'] = $this->app()->user()->data ('id');

		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData ['docType'], FALSE);

		if (!isset($recData['vatReg']) || !$recData['vatReg'])
		{
			$vatRegs = $this->app()->cfgItem('e10doc.base.taxRegs', NULL);
			if ($vatRegs)
			{
				$recData['vatReg'] = key($vatRegs);

				$vatReg = $vatRegs[$recData['vatReg']];
				if ($vatReg && $vatReg['payerKind'] === 0)
				{ // regular payer - not OSS
					$recData['taxCountry'] = $vatReg['taxCountry'];
				}
			}
			else
				$recData['vatReg'] = 0;
		}

		$recData ['taxPayer'] = $this->cfgItemTaxPayer ($recData);

		if (!isset($recData ['taxMethod']))
    	$recData ['taxMethod'] = 1;

		if (!isset($recData ['taxCalc']))
		{
			$recData ['taxCalc'] = 0;
			if ($docType['useTax'])
			{
				$recData ['taxCalc'] = 1;
			}
		}

		if (!isset ($recData ['person']))
			$recData ['person'] = 0;

		$this->resetPersonType ($recData);

		if (!isset ($recData['paymentMethod']))
		{
			if ($recData['docType'] === 'cash')
				$recData ['paymentMethod'] = 1;
			else
				$recData['paymentMethod'] = 0;
		}
		if (!isset($recData['collectingDoc']))
			$recData['collectingDoc'] = 0;

		$recData ['owner'] = intval($this->app()->cfgItem ('options.core.ownerPerson', 0));

		if ($docType)
		{
			if (!isset ($recData ['myBankAccount']) || $recData ['myBankAccount'] === 0)
			{
				if ($docType['tradeDir'] === 1)
				{
					$recData ['myBankAccount'] = intval($this->app()->cfgItem('options.e10doc-sale.myBankAccount', 0));
					if ($recData ['myBankAccount'] === 0)
					{
						$ba = $this->app()->cfgItem('e10doc.bankAccounts', []);
						$recData ['myBankAccount'] = intval(key($ba));
					}
				}
				else
				if ($recData['docType'] === 'purchase')
				{
					$recData ['myBankAccount'] = intval($this->app()->cfgItem('options.e10doc-buy.myBankAccountPurchases', 0));
					if ($recData ['myBankAccount'] === 0)
					{
						$ba = $this->app()->cfgItem('e10doc.bankAccounts', []);
						$recData ['myBankAccount'] = intval(key($ba));
					}
				}
			}
		}

		if (!isset($recData['docKind']))
			$recData['docKind'] = 0;
		if (isset ($recData['dbCounter']) && $recData['dbCounter'] !== 0 && $recData['docKind'] == 0)
		{
			$dbCounter = $this->app()->cfgItem ('e10.docs.dbCounters.'.$recData['docType'].'.'.$recData['dbCounter'], FALSE);
			$useDocKinds = utils::param ($dbCounter, 'useDocKinds', 0);
			if ($useDocKinds !== 0)
				$recData['docKind'] = $dbCounter['docKind'];
			else
				$recData['docKind'] = 0;
		}

    // cashBox & warehouse
		if (!isset($recData ['cashBox']))
			$recData ['cashBox'] = 0;
		if (!isset($recData ['warehouse']))
			$recData ['warehouse'] = 0;
		if ($recData ['docType'] === 'invno' || $recData ['docType'] === 'cashreg' || $recData ['docType'] === 'purchase')
		{
			if (!isset($recData ['cashBox']) && isset ($this->app()->workplace['cashBox']))
				$recData ['cashBox'] = $this->app()->workplace['cashBox'];
			if (!isset($this->recData ['centre']) && isset ($this->app()->workplace['centre']))
				$recData ['centre'] = $this->app()->workplace['centre'];

			if (!isset ($recData['warehouse']) || $recData['warehouse'] === 0)
			{
				$cashBox = $this->app()->cfgItem ('e10doc.cashBoxes.'.$recData['cashBox'], FALSE);
				if ($cashBox && $recData ['docType'] === 'cashreg' && $cashBox['warehouseCashreg'] !== 0)
					$recData['warehouse'] = $cashBox['warehouseCashreg'];
				else
				if ($cashBox && $recData ['docType'] === 'purchase' && $cashBox['warehousePurchase'] !== 0)
					$recData['warehouse'] = $cashBox['warehousePurchase'];

				if (!isset ($recData['warehouse']) || $recData['warehouse'] === 0)
				{
					$warehouses = $this->app()->cfgItem('e10doc.warehouses', array());
					if (count($warehouses))
						$recData['warehouse'] = key($warehouses);
				}
			}
		}

		// paymentMethod
		if ($recData ['docType'] === 'cashreg' || $recData ['docType'] === 'cash')
			$this->recData ['paymentMethod'] = 1;
		else
			$this->recData ['paymentMethod'] = 0;

		// -- owner office
		if (!isset($recData['ownerOffice']) || !$recData['ownerOffice'])
		{
			if ($recData['warehouse'])
			{
				$whCfg =  $this->app()->cfgItem('e10doc.warehouses.'.$recData['warehouse'], NULL);
				if ($whCfg && isset($whCfg['ownerOffice']) && $whCfg['ownerOffice'])
					$recData['ownerOffice'] = $whCfg['ownerOffice'];
			}
		}

		$this->checkColumnsSettings($recData);
	}

	function checkInboxDocument($issueNdx, &$recData)
	{
		// -- document title
		if (isset($recData['fromIssueSubject']))
		{
			if (!isset($recData['title']) || $recData['title'] === '')
				$recData['title'] = $recData['fromIssueSubject'];
		}

		/** @var $issueNdx \wkf\core\TableIssues */
		$tableIssues = $this->app()->table('wkf.core.issues');

		$srcIssueRecData = $tableIssues->loadItem($issueNdx);
		if (!$srcIssueRecData)
			return;

		$recData['paymentMethod'] = $srcIssueRecData['docPaymentMethod'];
		if ($srcIssueRecData['docCurrency'])
		{
			$ccfg = World::currency($this->app(), $srcIssueRecData['docCurrency']);
			if ($ccfg)
				$recData['currency'] = $ccfg['i'];
		}

		if ($srcIssueRecData['docId'] !== '')
			$recData['docId'] = Str::upToLen($srcIssueRecData['docId'], 40);
		if ($srcIssueRecData['docSymbol1'] !== '')
			$recData['symbol1'] = Str::upToLen($srcIssueRecData['docSymbol1'], 20);
		if ($srcIssueRecData['docSymbol2'] !== '')
			$recData['symbol2'] = Str::upToLen($srcIssueRecData['docSymbol2'], 20);

		if (!utils::dateIsBlank($srcIssueRecData['docDateIssue']))
			$recData['dateIssue'] = $srcIssueRecData['docDateIssue'];
		if (!utils::dateIsBlank($srcIssueRecData['docDateDue']))
			$recData['dateDue'] = $srcIssueRecData['docDateDue'];
		if (!utils::dateIsBlank($srcIssueRecData['docDateAccounting']))
			$recData['dateAccounting'] = $srcIssueRecData['docDateAccounting'];
		if (!utils::dateIsBlank($srcIssueRecData['docDateTax']))
			$recData['dateTax'] = $srcIssueRecData['docDateTax'];
		if (!utils::dateIsBlank($srcIssueRecData['docDateTaxDuty']))
			$recData['dateTaxDuty'] = $srcIssueRecData['docDateTaxDuty'];

		if (!utils::dateIsBlank($srcIssueRecData['dateIncoming']))
		{
			if (!isset($recData['dateIssue']) || utils::dateIsBlank($recData['dateIssue']))
				$recData['dateIssue'] = $srcIssueRecData['dateIncoming'];
			if (!isset($recData['dateIssue']) || utils::dateIsBlank($recData['dateAccounting']))
				$recData['dateAccounting'] = $srcIssueRecData['dateIncoming'];
		}

		if ($srcIssueRecData['docCentre'])
			$recData['centre'] = $srcIssueRecData['docCentre'];

		if ($srcIssueRecData['docProject'])
			$recData['wkfProject'] = $srcIssueRecData['docProject'];

		if ($srcIssueRecData['workOrder'])
			$recData['workOrder'] = $srcIssueRecData['workOrder'];

		if ($srcIssueRecData['docWarehouse'])
			$recData['warehouse'] = $srcIssueRecData['docWarehouse'];

		if ($srcIssueRecData['docProperty'])
			$recData['property'] = $srcIssueRecData['docProperty'];

		if (isset($srcIssueRecData['subject']) && $srcIssueRecData['subject'] !== '')
		{
			if (!isset($recData['title']) || $recData['title'] === '')
				$recData['title'] = $srcIssueRecData['subject'];
		}

		// -- person
		if (!isset($recData['person']) || !$recData['person'])
		{
			$persons = $this->app()->db()->query('SELECT dstRecId FROM [e10_base_doclinks] WHERE srcRecId = %i', $issueNdx,
				' AND srcTableId = %s', 'wkf.core.issues', ' AND dstTableId = %s', 'e10.persons.persons',
				' AND linkId = %s', 'wkf-issues-from')->fetch();
			if ($persons)
				$recData['person'] = $persons['dstRecId'];
		}
	}

	public function checkSaveData (&$saveData, &$saveResult)
	{
		$saveResult ['test123'] = 'QWERTY';

		if (!isset ($saveData['saveOptions']))
			return;

		$saveOptions = $saveData['saveOptions'];

		if (isset ($saveOptions['appendRowList']) && $saveOptions['appendRowList'] === 'rows')
		{
			$docType = $this->app()->cfgItem ('e10.docs.types.' . $saveData ['recData']['docType']);
			$groupNewLine = isset ($docType ['groupNewLine']) ? $docType ['groupNewLine'] : 0;
			$witem = array ();
			$operation = 0;
			if (isset ($saveOptions['appendRowItemPK']))
				$witem = $this->loadItem ($saveOptions['appendRowItemPK'], 'e10_witems_items');
			else
			if (isset ($saveOptions['appendRowItemBarcode']))
			{
				$sql = 'SELECT * FROM [e10_base_properties] props LEFT JOIN e10_witems_items items ON props.recid = items.ndx where [tableid] = %s AND property = %s AND valueString = %s AND items.docStateMain != 4';
				$witemEan = $this->db()->query ($sql, 'e10.witems.items', 'ean', $saveOptions['appendRowItemBarcode'])->fetch ();
				if ($witemEan)
				{
					$witem = $this->loadItem($witemEan['recid'], 'e10_witems_items');
					if ($saveData ['recData']['docType'] === 'invni')
					{
						if ($witem['itemKind'] == 1)
							$operation = 1010102; // Nákup zásob
						elseif ($witem['itemKind'] == 0)
							$operation = 1010199; // Nákup ostatní
					}
					elseif ($saveData ['recData']['docType'] === 'invno')
					{
						if ($witem['itemKind'] == 1)
							$operation = 1010002; // Prodej zásob
						elseif ($witem['itemKind'] == 0)
							$operation = 1010001; // Prodej služeb
					}
				}
			}

			$modRowNdx = 0;
			if (isset ($witem['ndx']))
			{
				$appendNeeded = true;
				// item exist?
				if ($groupNewLine == 1)
				{ // group all
					forEach ($saveData ['lists']['rows'] as &$er)
					{
						if ($er['item'] == $witem['ndx'])
						{
							$er['quantity'] += 1;
							$appendNeeded = false;
							break;
						}
						$modRowNdx++;
					}
				}
				else
				if ($groupNewLine == 2)
				{ // group last only
					$lli = count ($saveData ['lists']['rows']) - 1;
					if ($saveData ['lists']['rows'][$lli]['item'] == $witem['ndx'])
					{
						$saveData ['lists']['rows'][$lli]['quantity'] += 1;
						$appendNeeded = false;
						$modRowNdx = $lli;
					}
				}

				// no, add to list
				if ($appendNeeded)
				{
					$newRow = array ();
					$newRow ['item'] = $witem['ndx'];

					$newRow ['quantity'] = 1;
					if ($saveData ['recData']['docType'] === 'purchase')
					{
						if ($saveData ['recData']['weightIn'] != 0 && $saveData ['recData']['weightOut'])
						{
							$needWeight = $saveData ['recData']['weightIn'] - $saveData ['recData']['weightOut'];
							$needWeight = $needWeight - $saveData ['recData']['weightNet'];
							if ($needWeight > 1)
								$newRow ['quantity'] = $needWeight;
						}
					}
					if ($operation)
						$newRow ['operation'] = $operation;

					$this->resetRowItem ($saveData ['recData'], $newRow, $witem, $docType);

					$cntLines = count($saveData ['lists']['rows']);
					if ($cntLines !== 0)
					{
						$lastRow = $saveData ['lists']['rows'][$cntLines-1];
						$newRow ['rowOrder'] = $lastRow ['rowOrder'] + 100;
					}
					else
						$newRow ['rowOrder'] = ($cntLines + 1) * 100;

					$modRowNdx = count ($saveData ['lists']['rows']);
					$saveData ['lists']['rows'][] = $newRow;
				}
				$saveResult ['modifiedRow'] = $modRowNdx;
				$saveResult ['modifiedList'] = 'rows';
			}
			else
			{
				if (isset($saveOptions['appendBlankRow']))
				{
					$tableRows = new \E10Doc\Core\TableRows ($this->app());
					$newRow = ['quantity' => 1];

					$tableRows->checkBeforeSave ($newRow, $saveData ['recData']);

					// -- set special columns from last row: operation
					$cntLines = count($saveData ['lists']['rows']);
					if ($cntLines !== 0)
					{
						$lastRow = $saveData ['lists']['rows'][$cntLines-1];
						$newRow ['operation'] = $lastRow ['operation'];
						if (isset($saveOptions['rowOrder']))
							$newRow ['rowOrder'] = intval($saveOptions['rowOrder']);
						if (!isset($newRow ['rowOrder']) || !$newRow ['rowOrder'])
							$newRow ['rowOrder'] = $lastRow ['rowOrder'] + 100;
					}
					else
						$newRow ['rowOrder'] = ($cntLines + 1) * 100;
					$saveData ['lists']['rows'][] = $newRow;
					if (isset($saveOptions['rowOrder']))
						$saveResult ['modifiedRow'] = intval($saveOptions['rowNumber']);
					else
						$saveResult ['modifiedRow'] = count($saveData ['lists']['rows']) - 1;
					$saveResult ['modifiedList'] = 'rows';
				}
			}
			return;
		}

		parent::checkSaveData ($saveData, $saveResult);
	}

	function copyDocumentRecord ($srcRecData, $ownerRecord = NULL)
	{
		$recData = parent::copyDocumentRecord ($srcRecData, $ownerRecord);

		$recData ['author'] = $this->app()->user()->data ('id');
		$recData ['docNumber'] = '';
		$recData ['docId'] = '';
		$recData ['taxPeriod'] = 0;
		$recData ['fiscalYear'] = 0;
		$recData ['fiscalMonth'] = 0;
		$recData ['taxManual'] = 0;
		unset ($recData ['activateTimeFirst'], $recData ['activateDateFirst'], $recData ['activateTimeLast']);

		$recData ['dateIssue'] = utils::today();
		$recData ['dateAccounting'] = utils::today();
		$recData ['dateTax'] = utils::today();
		$recData ['dateTaxDuty'] = NULL;

		if ($srcRecData ['symbol1'] == $srcRecData ['docNumber'])
		{
			$recData ['symbol1'] = '';
			$recData ['symbol2'] = '';
		}

		$recData ['contract'] = 0;

		$recData ['rosReg'] = 0;
		$recData ['rosState'] = 0;
		$recData ['rosRecord'] = 0;

		return $recData;
	}

	public function doAccounting (&$recData)
	{
		if (isset($recData['impNdx']) && $recData['impNdx'])
		{
			if ($recData['docState'] == 4100)
				$this->db()->query ('DELETE FROM [e10doc_debs_journal] WHERE [document] = %i', $recData['ndx']);

			return;
		}

		$recData ['docStateAcc'] = 0;

		if (!$this->accountingDocument($recData))
			return;

		$tableAccountingJournal = new \E10Doc\Debs\TableJournal($this->app());
		$tableAccountingJournal->doIt ($recData);
	}

	public function doBalance (&$recData)
	{
		if (!$this->balanceDocument($recData))
			return;

		$tableBalanceJournal = new \E10Doc\Balance\TableJournal($this->app());
		$tableBalanceJournal->doIt ($recData);
	}

	public function doInbox (&$recData)
	{
		if ($recData ['docState'] != 4000)
			return;

		$q[] = 'SELECT * FROM [e10_base_doclinks] AS [links]';
		array_push($q, ' LEFT JOIN [wkf_core_issues] AS [issues] ON [links].dstRecId = [issues].ndx');
		array_push($q, ' WHERE [links].linkId = %s', 'e10docs-inbox', ' AND srcTableId = %s', 'e10doc.core.heads', ' AND srcRecId = %i', $recData['ndx']);
		array_push($q, ' AND [issues].docState != 4000');

		$inboxRows = $this->db()->query($q);

		/** @var \wkf\core\TableIssues $tableIssues */
		$tableIssues = $this->app()->table('wkf.core.issues');
		foreach ($inboxRows as $inboxLink)
		{
			$msgRec = $tableIssues->loadItem($inboxLink['dstRecId']);
			$msgRec['docState'] = 4000;
			$msgRec['docStateMain'] = 2;
			$msgRec['dateTouch'] = new \DateTime();
			$tableIssues->dbUpdateRec($msgRec);
			$tableIssues->docsLog($inboxLink['dstRecId']);
		}
	}

	public function doInventory (&$recData)
	{
		if ($recData['docState'] == 4000)
		{
			$e = new \e10doc\core\libs\DocCheckSetsItems($this->app());
			$e->init();
			$e->setDocNdx($recData['ndx']);
			$e->checkDocument(1);
		}

		if (!$this->inventoryDocument($recData))
			return;

		$tableInventoryJournal = new \E10Doc\Inventory\TableJournal($this->app());
		$tableInventoryJournal->doIt ($recData);
	}

	public function doTaxReports (&$recData)
	{
		if ($recData['docStateMain'] !== 2)
			return;

		$vatPeriods = $this->app()->cfgItem ('e10doc.vatPeriods', FALSE);
		if ($vatPeriods === FALSE)
			return;

		$taxReports = $this->app()->cfgItem('e10doc.taxes.reportTypes', NULL);
		if (!$taxReports)
			return;

		$vatReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$recData['vatReg'], NULL);
		if (!$vatReg)
			return;

		foreach ($taxReports as $tr)
		{
			if (!isset($tr['docTypes']) || !in_array($recData['docType'], $tr['docTypes']))
				continue;
			if (isset($tr['enabledCfgItem']) && !$this->app()->cfgItem ($tr['enabledCfgItem'], 0))
				continue;

			$trEngine = $this->app()->createObject($tr['engine']);
			$trEngine->init();
			$trEngine->doDocument ($recData);
		}
	}

	public function doRos (&$recData)
	{
		if ($recData['docStateMain'] !== 2)
			return;
		$rosRegs = $this->app()->cfgItem('terminals.ros.regs', NULL);
		if (!$rosRegs || count($rosRegs) < 2)
			return;
		$rosTypes = $this->app()->cfgItem('terminals.ros.types', NULL);
		if (!$rosTypes)
			return;
		$doRos = FALSE;

		if (isset($recData['rosReg']) && $recData['rosReg'])
		{
			$doRos = TRUE;
			$rosRegNdx = $recData['rosReg'];
			$rosReg = $rosRegs[$recData['rosReg']];
			$rosType = $rosTypes[$rosReg['rosType']];
		}
		else
		{
			$dateAccounting = $recData['dateAccounting']->format ('Y-m-d');
			foreach ($rosRegs as $rosReg)
			{
				if (!$rosReg['ndx'])
					continue;
				$rosType = $rosTypes[$rosReg['rosType']];

				if (isset($rosReg['validFrom']) && $rosReg['validFrom'] > $dateAccounting)
					continue;
				if (isset($rosReg['validTo']) && $rosReg['validTo'] < $dateAccounting)
					continue;

				if (!isset($rosType['docTypes']) || !in_array($recData['docType'], $rosType['docTypes']))
					continue;
				if (!isset($rosType['paymentMethods']) || !in_array($recData['paymentMethod'], $rosType['paymentMethods']))
					continue;
				if ($recData['docType'] === 'cash' && $recData['cashBoxDir'] !== 1)
					continue;

				$rosRegNdx = $rosReg['ndx'];
				$doRos = TRUE;
				break;
			}
		}

		if (!$doRos)
			return;

		$rosEngine = $this->app()->createObject($rosType['engine']);
		$rosEngine->doDocument ($rosRegNdx, $recData);
	}

	public function doWaste (&$recData)
	{
		if ($recData['docType'] !== 'purchase' && $recData['docType'] !== 'invno')
			return;

		$wre = new \e10pro\reports\waste_cz\libs\WasteReturnEngine($this->app);
		$wre->year = intval(Utils::createDateTime($recData['dateAccounting'])->format('Y'));
		$wre->resetDocument($recData['ndx']);
	}

	public function documentStates ($recData)
	{
		$states = $this->app()->model()->tableProperty ($this, 'states');
		$docTypeStates = $this->app()->cfgItem ('e10doc.core.heads.docStates.'.$recData['docType'], FALSE);

		if ($docTypeStates === FALSE)
			$docTypeStates = $this->app()->cfgItem ($states ['statesCfg']);

		$states ['states'] = $docTypeStates;
		return $states;
	}

	public function getDocumentLockState ($recData, $form = NULL)
	{
		$lock = parent::getDocumentLockState ($recData, $form);
		if ($lock !== FALSE)
			return $lock;

		if (isset ($recData['fiscalYear']) && $recData['fiscalYear'])
		{
			$fiscalYear = $this->loadItem($recData['fiscalYear'], 'e10doc_base_fiscalyears');
			if ($fiscalYear ['docStateMain'] >= 4)
			{
				return array ('mainTitle' => 'Doklad je uzamčen', 'subTitle' => 'Fiskální období '.$fiscalYear['fullName'].' je uzavřeno');
			}
		}

		if (isset($recData['taxPeriod']) && $recData['taxPeriod'] && $recData['taxPayer'] && $recData['taxCalc'] != 0)
		{
			$taxPeriod = $this->loadItem($recData['taxPeriod'], 'e10doc_base_taxperiods');
			if ($taxPeriod ['docStateMain'] >= 4)
			{
				return array ('mainTitle' => 'Doklad je uzamčen', 'subTitle' => 'Období DPH '.$taxPeriod['fullName'].' je uzavřeno');
			}
		}

		return FALSE;
	}

	public function getRecordInfo ($recData, $options = 0)
	{
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType']);
		$title = $recData['title'];
		$info = array ('title' => $title, 'docType' => $recData['docType'], 'docTypeName' => $docType['shortName'],
									 'docID' => $recData['docNumber'], 'money' => $recData['toPay'], 'currency' => $recData['currency']);

		$info ['persons']['to'][] = $recData['person'];
		$info ['persons']['from'][] = $recData['owner'];

		if (isset($docType['icon']))
			$info['icon'] = $docType['icon'];

		$dbCounter = $this->app()->cfgItem ('e10.docs.dbCounters.'.$recData['docType'].'.'.$recData['dbCounter'], FALSE);
		if ($dbCounter && isset($dbCounter['emailSender']))
		{
			if ($dbCounter['emailSender'] == 1)
			{ // author
				$tablePersons = $this->app()->table('e10.persons.persons');
				$author = $tablePersons->loadPersonInfo($recData['author']);
				if ($author && isset($author['email']))
				{
					$info['emailFromAddress'] = $author['email'];
					$info['emailFromName'] = $author['recData']['fullName'];
				}
			}
			elseif ($dbCounter['emailSender'] == 2)
			{ // email
				$info['emailFromAddress'] = $dbCounter['emailFromAddress'];
				$info['emailFromName'] = $dbCounter['emailFromName'];
			}
		}

		return $info;
	}

	public function taxCode ($dirTax, $headRecData, $taxRate)
	{
		return E10Utils::taxCodeForDocRow($this->app(), $headRecData, $dirTax, $taxRate);
  }

	public function tableIcon ($recData, $options = NULL)
	{
		return $this->app()->cfgItem ('e10.docs.types.' . $recData['docType'].'.icon', 'tables/e10doc.core.heads');
	}


	public function createPersonHeaderInfo ($itemPerson, $itemDoc)
	{
		$tablePersons = new \E10\Persons\TablePersons ($this->app());

		if (is_array ($itemPerson))
			$recData = $itemPerson;
		else
			$recData = $tablePersons->loadItem ($itemPerson);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return array();

		$ndx = $recData ['ndx'];


		if ($itemDoc['docType'] === 'bank')
		{
			$bankAccount = $this->app()->cfgItem ('e10doc.bankAccounts.'.$itemDoc['myBankAccount']);
			$icon [] = array ('text' => $bankAccount ['shortName'].' | '.$bankAccount ['bankAccount'],
												'icon' => 'x-bank',
												'docAction' => 'edit', 'table' => 'e10doc.base.bankaccounts', 'pk'=> $itemDoc['myBankAccount']);
		}

		$icon [] = array ('text' => $recData ['fullName'],
											'icon' => $tablePersons->icon ($recData),
											'docAction' => 'edit', 'table' => 'e10.persons.persons', 'pk'=> $ndx);
		$hdr ['info'][] = array ('class' => 'info', 'value' => $icon);

		return $hdr;
	}

	public function createHeader ($recData, $options)
	{
		$item = $recData;
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType']);
		$headerStyle = isset ($docType ['headerStyle']) ? $docType ['headerStyle'] : 'taxes';

		$hdr = $this->createPersonHeaderInfo ($recData['person'], $recData);
		$hdr ['icon'] = $this->tableIcon ($recData);
		if (isset($recData ['docNumber']))
			$docInfo [] = ['text' => $recData ['docNumber'] . ' ▪︎ ' . $docType ['shortName'], 'icon' => 'system/iconFile', 'class' => ''];
		else
			$docInfo [] = ['text' => '???' . ' ▪︎ ' . $docType ['shortName'], 'icon' => 'system/iconFile', 'class' => ''];

		if (isset($recData['symbol1']) && $recData['symbol1'] !== '')
		{
			$labels = [];
			$this->testDuplicity($recData, $labels);
			if (count($labels))
			{
				$docInfo[] = $labels;
			}
		}

		$hdr ['info'][] = array ('class' => 'title', 'value' => $docInfo);

		if (isset ($item ['ndx']))
		{
			$currencyName = $this->app()->cfgItem ('e10.base.currencies.'.$recData['currency'].'.shortcut');

			if ($headerStyle == 'taxes')
			{
				if ($recData['taxPayer'])
				{
					$hdr ['sum'][] = array ('class' => 'big', 'value' => '' . \E10\nf ($item ['sumBase'], 2), 'prefix' => $currencyName);
					if ($recData['taxCalc'] !== 0)
						$hdr ['sum'][] = ['class' => 'normal',
							'value' => [
								['text' => \E10\nf ($recData['sumTax'], 2), 'prefix' => '+ dph'],
								['text' => \E10\nf ($recData['toPay'], 2), 'prefix' => (($recData['rounding'] != 0.0)? ' ≐' : ' =')]
							]
						];
					else
						$hdr ['sum'][] = array ('class' => 'normal', 'value' => '', 'prefix' => 'nedaňový doklad');
				}
				else
					$hdr ['sum'][] = array ('class' => 'big', 'value' => \E10\nf ($recData['toPay'], 2), 'prefix' => $currencyName);
			}
			else
			if ($headerStyle == 'toPay')
			{
				if ($item ['taxPayer'])
				{
					$hdr ['sum'][] = array ('class' => 'big', 'value' => '' . \E10\nf ($recData['toPay'], 2), 'prefix' => $currencyName);
					if ($recData['taxCalc'] !== 0)
						$hdr ['sum'][] = array ('class' => 'normal', 'value' => \E10\nf ($recData['sumBase'], 2), 'prefix' => 'bez DPH');
				}
				else
					$hdr ['sum'][] = array ('class' => 'big', 'value' => \E10\nf ($recData['toPay'], 2), 'prefix' => $currencyName);
			}
			if ($headerStyle == 'toPay' && $recData['docType'] === 'purchase')
			{
				$wght = utils::nf ($recData['weightNet'], 2);
				if ($recData['weightIn'] != 0 && $recData['weightOut'] != 0)
				{
					$ww = $item ['weightIn'] - $item ['weightOut'];
					$miss =  $ww - $item ['weightNet'];
					if ($miss != 0)
						$wght .= '; má být '.utils::nf ($ww, 2).', zbývá ' . utils::nf ($miss, 2);
				}
				$hdr ['sum'][] = ['class' => 'normal', 'value' => $wght, 'suffix' => 'kg'];
			}
			else
			if ($headerStyle === 'mnf')
			{
				if (isset ($options['lists']) && $options['lists']['rows'][0]['unit'] === 'kg')
				{
					$wght = $options['lists']['rows'][0]['quantity'];
					$hdr ['sum'][] = ['class' => 'big', 'value' => utils::nf ($wght, 2), 'suffix' => 'kg'];
					$used = $recData['weightNet'] - $wght;
					$miss =  $wght - $used;
					if ($miss != 0)
					{
						$wght = 'chybí ' . utils::nf ($miss, 2);
						$hdr ['sum'][] = ['class' => 'normal', 'value' => $wght, 'suffix' => 'kg'];
					}
				}
				else
				{
					$hdr ['sum'][] = array ('class' => 'big', 'value' => utils::nf ($item ['weightGross'], 2), 'suffix' => 'kg');
				}
			}
			else
			if ($headerStyle === 'cmnbkp')
			{
				if ($item ['credit'] === $item ['debit'])
				{
					$hdr ['sum'][] = array ('class' => 'big', 'value' => '' . utils::nf ($recData['credit'], 2), 'prefix' => $currencyName);
				}
				else
				{
					$hdr ['sum'][] = ['prefix' => $currencyName, 'value' => [
						['class' => 'normal e10-error', 'text' => utils::nf ($recData['debit'], 2), 'prefix' => ' MD'],
						['class' => 'normal e10-error', 'text' => utils::nf ($recData['credit'], 2), 'prefix' => '≠ DAL'],
					]];
					$hdr ['sum'][] = ['class' => 'normal e10-error', 'value' => utils::nf ($recData['debit'] - $recData['credit'], 2), 'prefix' => 'rozdíl:'];
				}
			}
			else
			if ($headerStyle === 'bank')
			{
				$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-plus-square', 'text' => utils::nf ($recData['credit'], 2)], 'prefix' => $currencyName];
				$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-minus-square', 'text' => utils::nf ($recData['debit'], 2)]];
			}
			else
			if ($headerStyle === 'bankorder')
			{
				$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-minus-square', 'text' => utils::nf ($recData['debit'], 2), 'prefix' => $currencyName]];
				if ($recData['credit'])
					$hdr ['sum'][] = ['class' => 'normal', 'value' => ['icon' => 'icon-plus-square', 'text' => utils::nf ($recData['credit'], 2)]];
			}
		}
		else
		{
			$hdr ['info'][] = ['class' => 'title', 'value' => 'Nový doklad'];
		}

		return $hdr;
	}

	public function testDuplicity($recData, &$flags)
	{
		if (!isset($recData['docType']) || $recData['docType'] !== 'invni')
			return;

		$fy = $recData['fiscalYear'];
		if (!$fy)
		{
			$fmRec = $this->db()->query('SELECT [ndx] as [fm], [fiscalYear] as [fy] FROM [e10doc_base_fiscalmonths] ',
				'WHERE [start] <= %d', $recData ['dateAccounting'], ' AND [end] >= %d', $recData ['dateAccounting'],
				' AND [fiscalType] = %i', 0)->fetch();
			if ($fmRec)
				$fy = $fmRec['fy'];
		}

		$q = [];
		array_push($q, 'SELECT ndx, docNumber, docType ');
		array_push($q, ' FROM [e10doc_core_heads]');
		array_push($q, ' WHERE [symbol1] = %s', $recData['symbol1']);
		array_push($q, ' AND [symbol2] = %s', $recData['symbol2']);
		array_push($q, ' AND [person] = %i', $recData['person']);
		array_push($q, ' AND [docType] = %s', $recData['docType']);
		array_push($q, ' AND [ndx] != %i', $recData['ndx']);
		array_push($q, ' AND [fiscalYear] = %i', $fy);
		array_push($q, ' AND [docState] != %i', 9800);
		array_push($q, ' LIMIT 2');

		$rows = $this->db()->query($q);
		foreach ($rows as $row)
		{
			if (!count($flags))
				$flags[] = ['text' => 'Možná duplicita: ', 'class' => 'label label-danger mr1', 'icon' => 'system/iconWarning'];

			$docType = $this->app()->cfgItem ('e10.docs.types.' . $row['docType']);
			$flags[] = [
				'text' => $row['docNumber'], 'class' => 'label label-default mr1', 'icon' => $docType['icon'],
				'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $row['ndx']
			];
		}
	}

  public function findAndSetFiscalPeriod (&$recData)
	{
		$fpt = 0;
		if ($recData ['initState'] && $recData['docType'] !== 'cash')
			$fpt = 1;
		if ($recData ['activity'] === 'ocpBalInSt' || $recData ['activity'] === 'ocpOpen')
			$fpt = 1;
		else if ($recData ['activity'] === 'ocpClose')
			$fpt = 2;

		$q = [];
		array_push($q, 'SELECT [months].[ndx] AS [fm], [months].[fiscalYear] AS [fy] ');
		array_push($q, ' FROM [e10doc_base_fiscalmonths] AS [months]');
		array_push($q, ' LEFT JOIN [e10doc_base_fiscalyears] AS [years] ON [months].[fiscalYear] = [years].[ndx]');
		array_push($q, ' WHERE [months].[start] <= %d', $recData ['dateAccounting'], ' AND [months].[end] >= %d', $recData ['dateAccounting']);
		array_push($q, ' AND [months].[fiscalType] = %i', $fpt);
		array_push($q, ' AND [years].[docState] != %i', 9800);
		$r = $this->db()->query ($q)->fetch();

		$recData ['fiscalYear'] = intval($r ['fy']);
		$recData ['fiscalMonth'] = intval($r ['fm']);

		$newHC = $this->app()->cfgItem ('e10doc.acc.periods.'.$recData ['fiscalYear'].'.currency', 'czk');
		$recData ['homeCurrency'] = $newHC;
  }

	public function findAndSetTaxPeriod (&$recData)
	{
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType']);
		if ($recData ['taxPayer'] && ($recData['docStateMain'] == 1 || $recData['docStateMain'] == 2) &&
				(isset($docType['taxDocument']) && $docType['taxDocument'] == 1))
		{
			// -- tax period
			$q = "SELECT [ndx] FROM [e10doc_base_taxperiods] WHERE [start] <= %d AND [end] >= %d AND [docState] != 9800";

			$res = $this->db()->query ($q, $recData ['dateTax'], $recData ['dateTax'])->fetch ();
			if ($res)
				$recData ['taxPeriod'] = $res ['ndx'];

			// -- VATIN
			if (!isset($recData ['personVATIN']) || $recData ['personVATIN'] === '')
			{
				$q = "SELECT valueString FROM e10_base_properties WHERE tableid = %s AND property = %s AND [group] = %s AND recid = %i";
				$res2 = $this->db()->query($q, 'e10.persons.persons', 'taxid', 'ids', $recData ['person'])->fetch();
				if ($res2)
					$recData ['personVATIN'] = $res2 ['valueString'];
			}
		}
	}

	public function formId ($recData, $ownerRecData = NULL, $operation = 'edit')
	{
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType']);
		return $docType['classId'];
	}

  public function icon ($recData)
	{
    $docType = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType']);
    return $docType ['icon'];
  }

	public function makeDocNumber (&$recData)
	{
    $docNumber = '';
    $docType = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType']);
    if ($recData ['docStateMain'] == 0)
		{ // TODO: this block is unused
      $docNumber = '!'.sprintf ('%09d', $recData['ndx']);
    }
    else
    {
      $formula = $this->app()->cfgItem ('e10.options.docNumbers.' . $recData['docType'], '');
			if ($formula == '')
				$formula = $this->app()->cfgItem ('e10.docs.types.' . $recData['docType'].'.docNumber', '');
      if ($formula == '')
        $formula = '%D%r%C%4';


      $q = "SELECT [mark] FROM [e10doc_base_fiscalyears] WHERE [ndx] = %i";
      $res = $this->db()->query ($q, $recData['fiscalYear']);
      $r2 = $res->fetch ();

			if (is_string($recData['dateAccounting']))
			{
				$da = new \DateTime ($recData['dateAccounting']);
				$year2 = $da->format ('y');
				$year4 = $da->format ('Y');
				$month = $da->format ('m');
			}
			else
			{
				$year2 = $recData['dateAccounting']->format ('y');
				$year4 = $recData['dateAccounting']->format ('Y');
				$month = $recData['dateAccounting']->format ('m');
			}

			$mba = $this->app()->cfgItem ('e10doc.bankAccounts.'.$recData['myBankAccount'], FALSE);

			// make select code
      $q = "SELECT COUNT(*) as cnt FROM [e10doc_core_heads] WHERE [docType] = %s AND [dbCounter] = %i AND [docNumber] NOT LIKE 'k%' AND [docNumber] NOT LIKE '!%' AND [docNumber] <> %s ";
			if (($recData['cashBox'] != 0) && (strpos ($formula, '%B') !== false))
				$q .= " AND [cashBox] = {$recData['cashBox']}";
			if (($recData['warehouse'] != 0) && (strpos ($formula, '%W') !== false))
				$q .= " AND [warehouse] = {$recData['warehouse']}";
			if (strpos ($formula, '%y') !== false || strpos ($formula, '%Y') !== false)
				$q .= " AND YEAR([dateAccounting]) = $year4";
			if (strpos ($formula, '%M') !== false)
				$q .= " AND MONTH([dateAccounting]) = $month";
			if (strpos ($formula, '%r') !== false)
				$q .= " AND [fiscalYear] = {$recData['fiscalYear']}";
			if (($recData['myBankAccount']) && (strpos ($formula, '%A') !== false))
				$q .= " AND [myBankAccount] = {$recData['myBankAccount']}";

			$res = $this->db()->query ($q, $recData['docType'], $recData['dbCounter'], '');
      $r = $res->fetch ();

			$dbCounter = $this->app()->cfgItem ('e10.docs.dbCounters.'.$recData['docType'].'.'.$recData['dbCounter'], FALSE);
			$dbCntrId = ($dbCounter === FALSE) ? "1" : $dbCounter ['docKeyId'];

			$firstNumber = 1;
			if ($dbCounter && isset($dbCounter['firstNumberSet']) && $dbCounter['firstNumberFiscalPeriod'] === $recData['fiscalYear'])
				$firstNumber = $dbCounter['firstNumber'];

			$cnt = intval($r['cnt']) + $firstNumber;

      $q = "SELECT [id] FROM [e10doc_base_cashboxes] WHERE [ndx] = %i";
      $cashBox = $this->db()->query ($q, $recData['cashBox'])->fetch ();
      $q = "SELECT [id] FROM [e10doc_base_warehouses] WHERE [ndx] = %i";
      $warehouse = $this->db()->query ($q, $recData['warehouse'])->fetch ();

      $rep = array ('%D' => $docType ['docIdCode'], '%r' => $r2['mark'], '%Y' => $year4, '%y' => $year2, '%M' => $month,
										'%C' => $dbCntrId,
										'%B' => isset($cashBox ['id']) ? $cashBox ['id'] : 'PX',
										'%A' => $mba ? $mba ['id'] : '',
										'%W' => isset($warehouse ['id']) ? $warehouse ['id'] : 'SX',
										'%2' => sprintf ('%02d', $cnt), '%3' => sprintf ('%03d', $cnt), '%4' => sprintf ('%04d', $cnt), '%5' => sprintf ('%05d', $cnt));
      $docNumber = strtr ($formula, $rep);
			if (isset ($docType['symbol1']))
				$recData['symbol1'] = strtr ($docType['symbol1'], $rep);
    } // switch ($recData ['docStateMain'])

		////sprintf ('%s%02d%d%04d', $docType ['docIdCode'], $year, $dbCntrId, $cnt);

		return $docNumber;
	}

	public function loadEmails ($persons)
	{
		if (!count($persons))
			return '';

		$sql = 'SELECT valueString FROM [e10_base_properties] where [tableid] = %s AND [recid] IN %in AND [property] = %s AND [group] = %s ORDER BY ndx';
		$emailsRows = $this->db()->query ($sql, 'e10.persons.persons', $persons, 'email', 'contacts')->fetchPairs ();
		return implode (', ', $emailsRows);
	}

	public function loadDocRowItemsCodes(array $headRecData, $personType, array $rowRecData, ?array $personGroups, array &$rowDestData, &$destData)
	{
		$codesKinds = $this->app()->cfgItem('e10.witems.codesKinds', []);
		if (!$personGroups)
			$personGroups = E10Utils::personGroups($this->app(), $headRecData['person']);

		$addressColumn = 'otherAddress1';
		$addressNdx = intval($headRecData[$addressColumn] ?? 0);
		$addressLabels = [];
		if ($addressNdx)
		{
			$labelsRows = $this->db()->query ('SELECT * FROM [e10_base_clsf] WHERE [tableid] = %s', 'e10.persons.personsContacts',
																				' AND [recid] = %i', $addressNdx);
			forEach ($labelsRows as $lr)
			{
				$addressLabels[] = $lr['clsfItem'];
			}
		}

		$rowDir = E10Utils::docRowDir ($this->app(), $rowRecData, $headRecData);

		$q = [];
		array_push ($q, 'SELECT [codes].*, [nomencItems].fullName AS nomencName');
		array_push ($q, ' FROM [e10_witems_itemCodes] AS [codes]');
		array_push ($q, ' LEFT JOIN  [e10_base_nomencItems] AS [nomencItems] ON [codes].[itemCodeNomenc] = [nomencItems].[ndx]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [codes].[item] = %i', $rowRecData['item']);
		array_push ($q, ' AND ([codes].[codeDir] = %i', $rowDir, ' OR [codes].[codeDir] = %i)', 0);
		array_push ($q, ' AND ([codes].[person] = %i', $headRecData['person'], ' OR [codes].[person] = %i)', 0);

		if ($personType == 1) // human
			array_push ($q, ' AND ([codes].[personType] = %i', 2, ' OR [codes].[personType] = %i)', 0);
		elseif ($personType == 2) // company
			array_push ($q, ' AND ([codes].[personType] = %i', 1, ' OR [codes].[personType] = %i)', 0);

		if (count($addressLabels))
		{
			array_push ($q, ' AND ([codes].[addressLabel] IN %in', $addressLabels, ' OR [codes].[addressLabel] = %i)', 0);
		}
		else
			array_push ($q, ' AND ([codes].[addressLabel] = %i)', 0);

		array_push ($q, ' AND (');
		if (count($personGroups))
			array_push ($q, ' [codes].[personsGroup] IN %in', $personGroups, ' OR ');
		array_push ($q, ' [codes].[personsGroup] = %i', 0);
		array_push ($q, ')');

		array_push ($q, ' ORDER BY [codes].systemOrder');

		$codes = [];
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$ckNdx = $r['codeKind'];
			$ck = $codesKinds[$ckNdx];

			if (isset($codes[$ckNdx]))
				continue;

			if (!isset($destData ['itemCodesHeader'][$ckNdx]))
			{
				$destData ['itemCodesHeader'][$ckNdx] = $ck;
			}

			$irc = $r->toArray();
			$irc['itemCodeName'] = $ck['fn'];
			if ($r['nomencName'])
				$irc['itemCodeTitle'] = $r['nomencName'];

			$codes[$ckNdx] = $irc;
		}
		$rowDestData ['rowItemCodesData'] = $codes;
	}

	public function printAfterConfirm (&$printCfg, &$recData, $docState, $saveData = NULL)
	{
		if (isset($docState['printAfterConfirm']) && is_array($docState['printAfterConfirm']))
		{
			$this->printAfterConfirm2 ($printCfg, $recData, $docState, $saveData);
			return;
		}

		$formReport = FALSE;
		$openCashdrawer = FALSE;

		$this->checkPrepayment ($recData);

		if (isset ($docState['openCashdrawer']) && isset ($this->app()->workplace['cashdrawer']))
			$openCashdrawer = $this->app()->workplace['cashdrawer'];

		if ($recData['docType'] === 'cashreg')
			$formReport = $this->getReportData ('e10doc.cashRegister.libs.BillReport', $recData ['ndx']);
		else
		if ($recData['docType'] === 'cash')
		{
			if (isset ($this->app()->workplace['printers']) && $this->app()->workplace['printers']['pos'])
				$formReport = $this->getReportData('e10doc.cash.libs.CashReportPos', $recData ['ndx']);
			else
				$formReport = $this->getReportData('e10doc.cash.libs.CashReport', $recData ['ndx']);
		}
		else
		if ($recData['docType'] === 'purchase')
			$formReport = $this->getReportData ('e10doc.purchase.libs.PurchaseReport', $recData ['ndx']);

		$printer = '';
		if ($formReport !== FALSE)
		{
			if (isset ($this->app()->workplace['printers']))
			{
				if ($formReport->reportMode === FormReport::rmPOS)
				{
					$printer = $this->app()->workplace['printers']['pos'];
					if ($printer)
						$printCfg['printerNdx'] = $printer;
				}
				else
					$printer = $this->app()->workplace['printers']['default'];
			}
			$formReport->setCfg ($printCfg);

			$formReport->openCashdrawer = $openCashdrawer;
			$formReport->renderReport ();
			$formReport->createReport ();
			$formReport->printReport ($printCfg, 1, $printer);
		}
	}

	public function printAfterConfirm2 (&$printCfg, &$recData, $docState, $saveData)
	{
		$this->checkPrepayment($recData);

		/** @var \e10\persons\TablePersons */
		$tablePersons = $this->app()->table('e10.persons.persons');

		foreach ($docState['printAfterConfirm'] as $r)
		{
			if (isset($r['ask']) && !isset($saveData['extra']['confirm']))
				continue;

			$doPrint = isset ($r['print']) ? $r['print'] : 0;
			if (isset ($saveData['extra']['confirm'][$r['id']]))
				$doPrint = intval ($saveData['extra']['confirm'][$r['id']]['print']);

			$doEmail = isset ($r['email']) ? $r['email'] : 0;
			if (isset ($saveData['extra']['confirm'][$r['id']]))
				$doEmail = intval ($saveData['extra']['confirm'][$r['id']]['email']);

			if (!$doPrint && !$doEmail)
				continue;

			$formReportTable = $this;
			$formReportRecData = $recData;
			$formReportNdx = $recData ['ndx'];

			if (isset ($r['referenceColumn']))
			{
				$colDef = $this->column($r['referenceColumn']);
				if (!$colDef)
					continue;
				$formReportTable = $this->app()->table ($colDef ['reference']);
				$formReportNdx = $recData [$r['referenceColumn']];
				$formReportRecData = $formReportTable->loadItem ($formReportNdx);
			}

			$formReport = $formReportTable->getReportData($r['class'], $formReportNdx);
			if ($formReport === FALSE)
				continue;

			$printer = '';
			if (isset ($this->app()->workplace['printers']))
			{
				if ($formReport->reportMode === FormReport::rmPOS)
				{
					$printer = $this->app()->workplace['printers']['pos'];
					if ($printer)
						$printCfg['printerNdx'] = $printer;
				}
				else
					$printer = $this->app()->workplace['printers']['default'];
			}

			$formReport->setCfg ($printCfg);
			$formReport->renderReport();
			$formReport->createReport();

			if ($doPrint)
			{
				$copies = (isset($r['copies'])) ? $r['copies'] : 1;
				$formReport->printReport($printCfg, $copies, $printer);
			}

			if ($doPrint || $doEmail)
			{
				$documentInfo = $formReportTable->getRecordInfo($formReportRecData);
				$emails = '';
				if (isset($documentInfo['persons']['to']))
				{
					$emails = $tablePersons->loadEmailsForReport($documentInfo['persons']['to'], $r['class']);
				}
				//if ($emails !== '')
				{
					$msgSubject = $formReport->createReportPart('emailSubject');
					$msgBody = $formReport->createReportPart('emailBody');

					$msg = new \Shipard\Report\MailMessage($this->app());

					$msg->setFrom($this->app()->cfgItem('options.core.ownerFullName'), $this->app()->cfgItem('options.core.ownerEmail'));
					$msg->setTo($emails);
					$msg->setSubject($msgSubject);
					$msg->setBody($msgBody);
					$msg->setDocument($formReportTable->tableId(), $formReportNdx, $formReport);

					$attachmentFileName = utils::safeChars($formReport->createReportPart('fileName'));
					if ($attachmentFileName === '')
						$attachmentFileName = 'priloha';

					$msg->addAttachment($formReport->fullFileName, $attachmentFileName . '.pdf', 'application/pdf');

					if ($doEmail && $emails !== '')
						$msg->sendMail();

					if ($doPrint)
						$msg->reportPrinted = TRUE;

					if ($formReport->reportMode === FormReport::rmDefault)
						$msg->saveToOutbox();
				}
			}
		}
	}

	public function addFormPrintAfterConfirm (\Shipard\Form\TableForm $form)
	{
		$docStates = $this->documentStates ($form->recData);
		if (!$docStates)
			return;
		$stateColumn = $docStates ['stateColumn'];
		$currentDocState = $docStates['states'][$form->recData[$stateColumn]];
		if (!isset($currentDocState['printAfterConfirmState']))
			return;
		$confirmDocState = $docStates['states'][$currentDocState['printAfterConfirmState']];
		foreach ($confirmDocState['printAfterConfirm'] as $report)
		{
			if (isset($report['ask']) && $report['ask'])
			{
				$doPrint = $report['print'];
				$doEmail = $report['email'];

				/* TODO: delete?
				if ($report['id'] === 'custreg')
				{
					if ($form->recData['personType'] == 2)
					{ // company
						$doPrint = 0;
						$doEmail = 0;
					}
					else
					{ // human
						$q[] = 'SELECT * FROM [e10pro_wkf_messages] WHERE ';
						array_push($q, ' [tableid] = %s', 'e10.persons.persons', ' AND [recid] = %i', $form->recData['person'],
								' AND [type] = %s', 'outbox', ' AND docKind = %s', 'person.custreg',
								' LIMIT 1');
						$exist = $this->db()->query($q)->fetch();
						if ($exist)
						{
							$doPrint = 0;
							$doEmail = 0;
						}
					}
				}*/

				if ($report['id'] === 'purchase')
				{
					if ($this->app()->workplace['printers']['pos'])
						$doPrint = 0;
					if ($form->recData['paymentMethod'] === 1)
						$doPrint = 1;
				}
				if ($report['id'] === 'purchasepos')
				{
					if (!$this->app()->workplace['printers']['pos'])
						$doPrint = 0;
					if ($form->recData['paymentMethod'] === 1)
						$doPrint = 0;
				}

				$form->openRow('text-right');
					$form->addStatic(['icon' => 'system/iconFile', 'text' => $report['title']], TableForm::coH2);
					if (isset ($report['print']))
						$form->addCheckbox('extra.confirm.' . $report['id'] . '.print', 'Tisk', '1', 0, $doPrint);
					if (isset ($report['email']))
						$form->addCheckbox('extra.confirm.' . $report['id'] . '.email', 'E-mail', '1', 0, $doEmail);
				$form->closeRow();
			}
		}
	}

	public function checkPrepayment (&$recData)
	{
		if ($recData['prepayment'] == 0)
			return;
		if ($recData['docType'] !== 'purchase')
			return;

		$newDoc = new \E10Doc\Core\CreateDocumentUtility ($this->app());
		$newDoc->createDocumentHead('cash');
		$newDoc->docHead['person'] = $recData['person'];
		$newDoc->docHead['cashBoxDir'] = 2;
		$newDoc->docHead['taxCalc'] = 0;
		$newDoc->docHead['cashBox'] = $recData['cashBox'];

		$newRow = $newDoc->createDocumentRow(NULL);
		$newRow['symbol1'] = $recData['docNumber'];
		$newRow['priceItem'] = $recData['prepayment'];
		if ($this->recData ['paymentMethod'] === 6)
		{
			$newRow['operation'] = 1040002; // likvidace (sběrného) výkupu
			$newRow['text'] = 'Částečná úhrada výkupu č. '.$recData['docNumber'];
		}
		else
		{
			$newRow['operation'] = 1010103; // poskytnutá záloha
			$newRow['text'] = 'Zálohová úhrada výkupu č. '.$recData['docNumber'];
		}

		$newDoc->addDocumentRow ($newRow);
		$newDoc->docHead['title'] = $newRow['text'];

		$newDoc->saveDocument();

		$recData['prepayment'] = 0.0;
		$this->db()->query ("UPDATE [e10doc_core_heads] SET prepayment = 0 WHERE ndx = %i", $recData['ndx']);
	}

	public function columnInfoEnum ($columnId, $valueType = 'cfgText', TableForm $form = NULL)
	{
		if ($columnId === 'taxCountry' && $form)
		{
			$taxReg = $this->app()->cfgItem('e10doc.base.taxRegs.'.$form->recData['vatReg'], NULL);
			if ($taxReg)
				return E10Utils::taxCountries ($this->app(), $taxReg['taxArea']);
			return [];
		}
		return parent::columnInfoEnum ($columnId, $valueType = 'cfgText', $form);
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if (!$form)
			return TRUE;

		if ($columnId === 'docKind')
		{
			if ($cfgItem['ndx'] === 0)
				return TRUE;

			if ($form->recData['docType'] !== $cfgItem['docType'])
				return FALSE;

			$dbCounter = $this->app()->cfgItem ('e10.docs.dbCounters.'.$form->recData['docType'].'.'.$form->recData['dbCounter'], FALSE);
			if ($dbCounter && $dbCounter['activitiesGroup'] != '')
			{
				if (substr($cfgItem['activity'], 0, strlen ($dbCounter['activitiesGroup'])) !== $dbCounter['activitiesGroup'])
					return FALSE;
			}

			return TRUE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}

	public function summaryVAT ($item, $wide = FALSE)
	{
		$cfgTaxCodes = E10Utils::docTaxCodes($this->app(), $item);

		$fc = intval(($item ['currency'] !== $item ['homeCurrency']));

		$currencyName = $this->app()->cfgItem ('e10.base.currencies.'.$item ['currency'].'.shortcut');
		$homeCurrencyName = $this->app()->cfgItem ('e10.base.currencies.'.$item ['homeCurrency'].'.shortcut');

		$q = "SELECT * FROM [e10doc_core_taxes] WHERE [document] = %i ORDER BY [taxPercents] DESC, [taxCode]";
		$rows = $this->db()->query($q, $item ['ndx']);

		$totalTaxBase = 0.0;
		$totalTax = 0.0;
		$totalTotal = 0.0;
		$totalTaxBaseHc = 0.0;
		$totalTaxHc = 0.0;
		$totalTotalHc = 0.0;
		$dataTaxes = array();

		forEach ($rows as $r)
		{
			$taxCode = $cfgTaxCodes [$r['taxCode']];
			$doSumTax = utils::param($taxCode, 'sumTax', 1);
			$doSumBase = utils::param($taxCode, 'sumBase', 1);
			$doSumTotal = utils::param($taxCode, 'sumTotal', 1);

			$cellClasses = [];

			if ($doSumBase)
			{
				$totalTaxBase += $r['sumBase'];
				$totalTaxBaseHc += $r['sumBaseHc'];
			}
			else
				$cellClasses['base'] = 'e10-off';

			if ($doSumTotal)
			{
				$totalTotal += $r['sumTotal'];
				$totalTotalHc += $r['sumTotalHc'];
			}
			else
				$cellClasses['total'] = 'e10-off';

			if ($doSumTax)
			{
				$totalTax += $r['sumTax'];
				$totalTaxHc += $r['sumTaxHc'];
			}
			else
			{
				$cellClasses['percents'] = 'e10-off';
				$cellClasses['tax'] = 'e10-off';
			}


			if ($fc)
			{
				if ($wide)
				{

				}
				else
				{
					$dataTaxes [] = [
						'title' => $taxCode['fullName'], 'percents' => strval($r['taxPercents']),
						'curr' => $currencyName,
						'base' => $r['sumBase'], 'tax' => $r['sumTax'], 'total' => $r['sumTotal'],
						'_options' => ['rowSpan' => ['#' => 1 + $fc, 'title' => 1 + $fc, 'percents' => 1 + $fc], 'cellClasses' => $cellClasses]
					];
					if ($fc)
					{
						$dataTaxes [] = [
							'curr' => $homeCurrencyName,
							'base' => $r['sumBaseHc'], 'tax' => $r['sumTaxHc'], 'total' => $r['sumTotalHc'],
							'_options' => ['cellClasses' => $cellClasses]
						];
					}
				}
			}
			else
			{
				$dataTaxes [] = [
					'title' => $taxCode['fullName'], 'percents' => utils::nf($r['taxPercents']),
					'base' => $r['sumBaseHc'], 'tax' => $r['sumTaxHc'], 'total' => $r['sumTotalHc'],
					'_options' => ['cellClasses' => $cellClasses]
				];
			}
		}

		// -- total
		if (count($rows, 0) > 1)
		{
			if ($fc)
			{
				if ($wide)
				{
				}
				else
				{
					$dataTaxes ['total'] = [
						'title' => 'Celkem'." (1 $currencyName = {$item['exchangeRate']} $homeCurrencyName)",
						'curr' => $currencyName, 'base' => $totalTaxBase, 'tax' => $totalTax, 'total' => $totalTotal,
						'_options' => ['class' => 'sum', 'rowSpan' => ['title' => 2, 'percents' => 2], 'colSpan' => ['title' => 2]]
					];

					$dataTaxes ['totalHc'] = [
						'curr' => $homeCurrencyName,
						'base' => $totalTaxBaseHc, 'tax' => $totalTaxHc, 'total' => $totalTotalHc,
						'_options' => ['class' => 'sum']
					];
				}
			}
			else
			{
				$dataTaxes ['total'] = array ('title' => 'Celkem',
					'base' => $totalTaxBase, 'tax' => $totalTax, 'total' => $totalTotal);
				$dataTaxes ['total']['_options']['class'] = 'sum';
			}
		}

		if ($fc /*|| $tc*/)
			$h = array ('#' => '#', 'title' => 'Sazba', 'percents' => ' %', 'curr' => 'Měna', 'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem');
		else
			$h = array ('#' => '#', 'title' => 'Sazba', 'percents' => ' %', 'base' => ' Základ', 'tax' => ' Daň', 'total' => ' Celkem');

		return ['type' => 'table', 'header' => $h, 'table' => array_values($dataTaxes)];
	}

	public function resetPersonType (&$recData)
	{
		if (!isset($recData['person']) || $recData['person'] == 0)
			$recData['personType'] = 0;
		else
		{
			$pt = $this->app()->db()->query ('SELECT personType FROM e10_persons_persons WHERE ndx = %i', $recData['person'])->fetch();
			if ($pt)
				$recData['personType'] = $pt['personType'];
			else
				$recData['personType'] = 0;
		}
	}

	public function resetRowItem ($headRecData, &$rowRecData, $itemRecData, $docType)
	{
		$rowRecData ['itemType'] = '';
		$rowRecData ['rowVds'] = 0;
		if (!$itemRecData)
		{
			return;
		}
		$rowRecData ['text'] = $itemRecData['fullName'];
		$rowRecData ['taxRate'] = $itemRecData['vatRate'];
		$rowRecData ['unit'] = $itemRecData['defaultUnit'];

		$this->resetRowItem_Vds($headRecData, $rowRecData, $itemRecData, $docType);

		switch ($docType ['tradeDir'])
		{
			case 1: // sale
						$rowRecData ['taxCode'] = $this->taxCode (1, $headRecData, $rowRecData ['taxRate']);
						$rowRecData ['priceItem'] = $this->resetRowItem_PriceSell($headRecData, $rowRecData, $itemRecData, $docType);
						break;
			case 2: // buy
						$rowRecData ['priceItem'] = $itemRecData ['priceBuy'];
						$rowRecData ['taxCode'] = $this->taxCode (0, $headRecData, $rowRecData ['taxRate']);
						break;
			case 3:
						if ($headRecData['cashBoxDir'] == 1)
						{
							$rowRecData ['taxCode'] = $this->taxCode (1, $headRecData, $rowRecData ['taxRate']);
							$rowRecData ['priceItem'] = $this->resetRowItem_PriceSell($headRecData, $rowRecData, $itemRecData, $docType);
						}
						else
						if ($headRecData['cashBoxDir'] == 2)
						{
							$rowRecData ['priceItem'] = $itemRecData ['priceBuy'];
							$rowRecData ['taxCode'] = $this->taxCode (0, $headRecData, $rowRecData ['taxRate']);
						}
						break;
			case 5: // stock in
						$rowRecData ['priceItem'] = $this->rowItemHistoryPrice ($headRecData, $rowRecData, $itemRecData, $docType);
						$rowRecData ['taxCode'] = $this->taxCode (0, $headRecData, $rowRecData ['taxRate']);
						break;
		}
	}

	function resetRowItem_PriceSell($headRecData, $rowRecData, $itemRecData, $docType)
	{
		if ($headRecData['taxCalc'] == 1)
		{ // ze základu
			if ($itemRecData['priceSellBase'] != 0.0)
				return $itemRecData['priceSellBase'];
			if ($itemRecData['priceSellTotal'] != 0.0)
			{
				error_log("__2__");
				$taxDate = e10utils::headsTaxDate ($headRecData);
				$taxPercents = e10utils::taxPercent ($this->app(), $rowRecData['taxCode'], $taxDate);
				$price = $itemRecData['priceSellTotal'];
				$k = round (($taxPercents / ($taxPercents + 100)), 4);
				$tax = utils::round (($price * $k), 2, 0);
				$base = round (($price - $tax), 2);
				return $base;
			}
		}
		elseif ($headRecData['taxCalc'] == 2 || $headRecData['taxCalc'] == 3)
		{ // ze ceny celkem
			if ($itemRecData['priceSellTotal'] != 0.0)
				return $itemRecData['priceSellTotal'];
			if ($itemRecData['priceSellBase'] != 0.0)
			{
				$taxDate = e10utils::headsTaxDate ($headRecData);
				$taxPercents = e10utils::taxPercent ($this->app(), $rowRecData['taxCode'], $taxDate);
				$price = $itemRecData['priceSellBase'];
				$total = utils::round (($price * ((100 + $taxPercents) / 100)), 2, 0);
				return $total;
			}
		}

		// -- nedaňový doklad
		if ($itemRecData['priceSellTotal'] != 0.0)
			return $itemRecData['priceSellTotal'];
		if ($itemRecData['priceSellBase'] != 0.0)
			return $itemRecData['priceSellBase'];
		if ($itemRecData ['priceSell'] != 0.0)
			return $itemRecData ['priceSell'];

		return 0.0;
	}

	function resetRowItem_Vds($headRecData, &$rowRecData, $itemRecData, $docType)
	{
		$q = [];
		array_push ($q, 'SELECT * FROM [e10doc_base_docRowsVDSCfg] WHERE 1');
		array_push ($q, ' AND [docStateMain] IN %in', [0, 2]);
		array_push ($q, ' AND ([docType] = %s', $headRecData ['docType'], ' OR [docType] = %s)', '');
		array_push ($q, ' AND ([docKind] = %i', $headRecData ['docKind'], ' OR [docKind] = 0)');
		array_push ($q, ' AND ([docDbCounter] = %i', $headRecData ['dbCounter'], ' OR [docDbCounter] = 0)');
		array_push ($q, ' AND ([witem] = %i', $itemRecData ['ndx'], ' OR [witem] = 0)');
		array_push ($q, ' ORDER BY [systemOrder], [order], [ndx]');

		$vds = $this->db()->query($q)->fetch();
		if ($vds)
			$rowRecData ['rowVds'] = $vds['vds'];
	}

	public function rowItemHistoryPrice ($headRecData, &$rowRecData, $itemRecData, $docType)
	{
		$q = 'SELECT [rows].priceItem as price FROM e10doc_core_rows as [rows], e10doc_core_heads as heads '.
				 'where [rows].document = heads.ndx AND heads.docType = %s AND heads.person = %i AND heads.docState = 4000 AND [rows].item = %i '.
			   'ORDER BY heads.dateAccounting DESC LIMIT 1';

		$priceRow = $this->db()->query ($q, $headRecData['docType'], $headRecData['person'], $rowRecData['item'])->fetch();
		if ($priceRow)
			return $priceRow['price'];
		return 0.0;
	}

	public function warehouses ()
	{
		$whs = $this->app()->cfgItem ('e10doc.warehouses', FALSE);
		if ($whs === FALSE || count($whs) === 0)
			return FALSE;

		return TRUE;
	}

	public function loadAccounting ($recData)
	{
		$fiscalYear = $this->app()->cfgItem ('e10doc.acc.periods.'.$recData ['fiscalYear'], FALSE);

		if ($fiscalYear && $fiscalYear['method'] === 'debs')
			return $this->loadAccounting_debs ($recData);

		if ($fiscalYear && $fiscalYear['method'] === 'sebs')
			return $this->loadAccounting_sebs ($recData);

		return FALSE;
	}

	public function loadAccounting_debs ($recData)
	{
		$accRingColorClasses = [20 => 'e10-bg-t8', 40 => 'e10-bg-t6'];

		$useWorkOrders = intval($this->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0));
		$useProperty = ($this->app()->model()->table ('e10pro.property.property') !== FALSE);
		$a = [];

		$q [] = 'SELECT journal.*';

		array_push ($q, ', projects.shortName as projectId');

		if ($useWorkOrders)
			array_push ($q, ', workOrders.docNumber as woDocNumber, workOrders.title as woTitle ');

		if ($useProperty)
			array_push ($q, ', property.propertyId as propertyId, property.fullName as propertyName ');

		array_push ($q, 'FROM [e10doc_debs_journal] AS journal ');

		array_push ($q, 'LEFT JOIN wkf_base_projects AS projects ON journal.project = projects.ndx ');

		if ($useWorkOrders)
			array_push ($q, 'LEFT JOIN e10mnf_core_workOrders AS workOrders ON journal.workOrder = workOrders.ndx ');

		if ($useProperty)
			array_push ($q, 'LEFT JOIN e10pro_property_property AS property ON journal.property = property.ndx ');

		array_push ($q, 'WHERE [document] = %i ', $recData ['ndx']);
		array_push ($q, 'ORDER BY [accRing], [ndx]');

		$centres = 0;
		$projects = 0;
		$workOrders = 0;
		$property = 0;
		$rowNumber = 1;
		$totalDr = 0;
		$totalCr = 0;

		$rows = $this->db()->query ($q)->fetchAll();

		if (count ($rows) == 0)
			return FALSE;

		$total = array ('_options' => ['class' => 'sum']);
		forEach ($rows as $r)
		{
			$newItem = $r->toArray();

			$totalCr += $newItem ['moneyCr'];
			$totalDr += $newItem ['moneyDr'];

			if ($newItem ['moneyDr'] === 0.0)
				unset ($newItem ['moneyDr']);
			if ($newItem ['moneyCr'] === 0.0)
				unset ($newItem ['moneyCr']);

			if ($r['centre'])
			{
				$newItem ['centreID'] = $this->app()->cfgItem ('e10doc.centres.'.$r['centre'].'.id', '');
				$centres = 1;
			}
			if ($r['projectId'])
			{
				$newItem ['projectId'] = $r['projectId'];
				$projects = 1;
			}
			if ($useWorkOrders && isset($r['workOrder']) && $r['workOrder'])
			{
				$newItem ['workOrder'] = [
					'text' => $r['woDocNumber'], 'title' => $r['woTitle'],'docAction' => 'edit',
					'table' => 'e10mnf.core.workOrders', 'pk' => $r['workOrder']
				];
				$workOrders = 1;
			}
			else
				$newItem ['workOrder'] = '';

			if ($useProperty && isset($r['property']) && $r['property'])
			{
				$newItem ['property'] = [
					'text' => $r['propertyId'], 'title' => $r['propertyName'],'docAction' => 'edit',
					'table' => 'e10pro.property.property', 'pk' => $r['property']
				];
				$property = 1;
			}
			else
				$newItem ['property'] = '';

			if (substr($r['accountId'], -3) === '999')
			{
				$newItem['_options']['class'] = 'e10-error';
				$a['accNotes'][] = array ('row' => $rowNumber, 'note' => 'Na účty s analytikou 999 by se nemělo účtovat; patrně jde o chybně dohledaný účet.');
			}

			$accRing = $r['accRing'];
			if (isset($accRingColorClasses[$accRing]))
				$newItem['_options']['cellClasses']['#'] = $accRingColorClasses[$accRing];

			$a['accRows'][] = $newItem;
			if (isset($newItem['moneyCr']))
			{
				if (!isset ($total['moneyCr']))
					$total['moneyCr'] = 0.0;
				$total['moneyCr'] += $newItem['moneyCr'];
			}
			if (isset($newItem['moneyDr']))
			{
				if (!isset ($total['moneyDr']))
					$total['moneyDr'] = 0.0;
				$total['moneyDr'] += $newItem['moneyDr'];
			}
			$rowNumber++;
		}
		if (count ($rows) > 1)
			$a['accRows'][] = $total;

		if (round ($totalDr - $totalCr, 2) != 0)
			$a['accNotes'][] = array ('row' => '', 'note' => 'Strany MD a DAL se nerovnají; rozdíl je '.utils::nf ($totalCr-$totalDr, 2));

		$a['accRowsHeader'] = [
			'#' => '#', 'accountId' => ' Účet', 'moneyDr' => ' MD', 'moneyCr' => ' DAL',
			'centreID' => ' Stř.', 'workOrder' => 'Zakázka', 'projectId' => 'Projekt', 'property' => 'Majetek',
			'symbol1' => 'VS', 'symbol2' => 'SS', 'text' => 'Text'
		];

		if (!$centres)
			unset ($a['accRowsHeader']['centreID']);
		if (!$workOrders)
			unset ($a['accRowsHeader']['workOrder']);
		if (!$property)
			unset ($a['accRowsHeader']['property']);
		if (!$projects)
			unset ($a['accRowsHeader']['projectId']);

		if (isset($a['accNotes']) && count($a['accNotes']))
			$a['accNotesHeader'] = ['row' => ' Řádek', 'note' => 'Problém'];

		return $a;
	}

	public function loadAccounting_sebs ($recData)
	{
		$a = [];

		$q [] = 'SELECT journal.*, projects.id as projectId, accounts.fullName as accountName FROM [e10doc_debs_journal] AS journal ';
		array_push ($q, 'LEFT JOIN e10pro_wkf_projects AS projects ON journal.project = projects.ndx ');
		array_push ($q, 'LEFT JOIN e10doc_debs_accounts AS accounts ON (journal.accountId = accounts.id) ');
		array_push ($q, 'WHERE [document] = %i ', $recData ['ndx']);
		array_push ($q, 'ORDER BY [ndx]');

		$centres = 0;
		$projects = 0;
		$rowNumber = 1;
		$totalDr = 0;
		$totalCr = 0;

		$rows = $this->db()->query ($q)->fetchAll();

		if (count ($rows) == 0)
			return FALSE;

		$total = array ('_options' => ['class' => 'sum']);
		forEach ($rows as $r)
		{
			$newItem = $r->toArray();

			if ($newItem['cashBookId'] == 0)
			{
				$newItem['_options'] = ['cellClasses' => ['moneyDr' => 'e10-off', 'moneyCr' => 'e10-off']];
			}

			if ($newItem['accountName'])
				$newItem['text'] = [['text' => $newItem['accountName'], 'class' => 'block e10-small'], ['text' => $newItem['text']]];

			$totalCr += $newItem ['moneyCr'];
			$totalDr += $newItem ['moneyDr'];

			if ($newItem ['moneyDr'] === 0.0)
				unset ($newItem ['moneyDr']);
			if ($newItem ['moneyCr'] === 0.0)
				unset ($newItem ['moneyCr']);

			if ($r['centre'])
			{
				$newItem ['centreID'] = $this->app()->cfgItem ('e10doc.centres.'.$r['centre'].'.id', '');
				$centres = 1;
			}
			if ($r['projectId'])
			{
				$newItem ['projectId'] = $r['projectId'];
				$projects = 1;
			}

			if (substr($r['accountId'], -3) === '999')
			{
				$newItem['_options']['class'] = 'e10-error';
				$a['accNotes'][] = array ('row' => $rowNumber, 'note' => 'Na účty s analytikou 999 by se nemělo účtovat; patrně jde o chybně dohledaný účet.');
			}

			$a['accRows'][] = $newItem;
			if (isset($newItem['moneyCr']))
			{
				if (!isset ($total['moneyCr']))
					$total['moneyCr'] = 0.0;
				$total['moneyCr'] += $newItem['moneyCr'];
			}
			if (isset($newItem['moneyDr']))
			{
				if (!isset ($total['moneyDr']))
					$total['moneyDr'] = 0.0;
				$total['moneyDr'] += $newItem['moneyDr'];
			}
			$rowNumber++;
		}
		if (count ($rows) > 1)
			$a['accRows'][] = $total;

		if (round ($totalDr - $totalCr, 2) != 0)
			$a['accNotes'][] = array ('row' => '', 'note' => 'Strany MD a DAL se nerovnají; rozdíl je '.utils::nf ($totalCr-$totalDr, 2));

		$a['accRowsHeader'] = ['#' => '#', 'accountId' => ' Účet', 'moneyDr' => ' Na vrub', 'moneyCr' => ' Ve prospěch',
			'centreID' => ' Stř.', 'projectId' => 'Projekt', 'symbol1' => 'VS', 'symbol2' => 'SS', 'text' => 'Text'];

		if (!$centres)
			unset ($a['accRowsHeader']['centreID']);
		if (!$projects)
			unset ($a['accRowsHeader']['projectId']);

		if (isset($a['accNotes']) && count($a['accNotes']))
			$a['accNotesHeader'] = ['row' => ' Řádek', 'note' => 'Problém'];

		return $a;
	}

	public function addFormPersonAddress ($form)
	{ // TODO: remove
		$personNdx = $form->recData['person'];

		$q = 'SELECT * FROM [e10_persons_address] WHERE tableid = %s AND recid = %i LIMIT 1';
		$a = $this->db()->query ($q, 'e10.persons.persons', $personNdx)->fetch();

		// -- email
		$chkSum = md5 ($a['street'].$a['city'].$a['zipcode']);
		$form->openRow();
			$form->addInput ("extra.personaddress.$personNdx-{$a['ndx']}-$chkSum.street", 'Ulice', TableForm::INPUT_STYLE_STRING, TableForm::coColW12, 60, ['value' => $a['street']]);
		$form->closeRow();
		$form->openRow();
			$form->addInput ("extra.personaddress.$personNdx-{$a['ndx']}-$chkSum.city", 'Obec', TableForm::INPUT_STYLE_STRING, TableForm::coColW8, 60, ['value' => $a['city']]);
			$form->addInput ("extra.personaddress.$personNdx-{$a['ndx']}-$chkSum.zipcode", 'PSČ', TableForm::INPUT_STYLE_STRING, TableForm::coColW4, 60, ['value' => $a['zipcode']]);
		$form->closeRow();
	}

	public function addFormPersonProperties ($form)
	{
		$properties = \E10\Base\getProperties ($this->app(), 'e10.persons.persons', 'contacts', $form->recData['person']);
		$personNdx = $form->recData['person'];
		$blankPP = ['value' => '', 'note' => '', 'ndx' => 0];

		// -- email
		$pp = isset($properties['email']) ? $properties['email'][0] : $blankPP;
		$form->openRow();
			$chksum = md5 ($pp['value'].$pp['note']);
			$form->addInput ("extra.personproperties.contacts-email-$personNdx-{$pp['ndx']}-$chksum.value", '✉', TableForm::INPUT_STYLE_STRING, TableForm::coColW10, 60, ['value' => $pp['value']]);
			$form->addInput ("extra.personproperties.contacts-email-$personNdx-{$pp['ndx']}-$chksum.note", '✎', TableForm::INPUT_STYLE_STRING, TableForm::coColW2, 60, ['value' => $pp['note']]);
		$form->closeRow();

		// -- phone
		$pp = isset($properties['phone']) ? $properties['phone'][0] : $blankPP;
		$form->openRow();
			$chksum = md5 ($pp['value'].$pp['note']);
			$form->addInput ("extra.personproperties.contacts-phone-$personNdx-{$pp['ndx']}-$chksum.value", '✆', TableForm::INPUT_STYLE_STRING, TableForm::coColW10, 60, ['value' => $pp['value']]);
			$form->addInput ("extra.personproperties.contacts-phone-$personNdx-{$pp['ndx']}-$chksum.note", '✎', TableForm::INPUT_STYLE_STRING, TableForm::coColW2, 60, ['value' => $pp['note']]);
		$form->closeRow();

		// -- bankaccount
		$pp = isset($properties['bankaccount']) ? $properties['bankaccount'][0] : $blankPP;
		$form->openRow();
			$chksum = md5 ($pp['value'].$pp['note']);
			$form->addInput ("extra.personproperties.payments-bankaccount-$personNdx-{$pp['ndx']}-$chksum.value", 'ČÚ', TableForm::INPUT_STYLE_STRING, TableForm::coColW10, 60, ['value' => $pp['value']]);
			$form->addInput ("extra.personproperties.payments-bankaccount-$personNdx-{$pp['ndx']}-$chksum.note", '✎', TableForm::INPUT_STYLE_STRING, TableForm::coColW2, 60, ['value' => $pp['note']]);
		$form->closeRow();

		// -- idcn
		$pp = isset($properties['idcn']) ? $properties['idcn'][0] : $blankPP;
		$form->openRow();
		$chksum = md5 ($pp['value']);
			$form->addInput ("extra.personproperties.ids-idcn-$personNdx-{$pp['ndx']}-$chksum.value", 'OP', TableForm::INPUT_STYLE_STRING, TableForm::coColW12, 60, ['value' => $pp['value']]);
		$form->closeRow();

		// -- birthdate
		$pp = isset($properties['birthdate']) ? $properties['birthdate'][0] : $blankPP;
		$form->openRow();
		$chksum = md5 ($pp['value']);
			$form->addInput ("extra.personproperties.ids-birthdate-$personNdx-{$pp['ndx']}-$chksum.value", 'DN', TableForm::INPUT_STYLE_DATE, TableForm::coColW12, 60, ['value' => $pp['value']]);
		$form->closeRow();
	}

	public function saveExtraData (&$recData, $extraData)
	{
		parent::saveExtraData($recData, $extraData);
		/*
		if (isset ($extraData['personproperties']))
		{
			foreach ($extraData['personproperties'] as $id => $prop)
			{ // contacts-email-1111-38342-md5_old_value
				$propertyParts = explode ('-', $id);
				$personNdx = intval ($propertyParts[2]);
				$propertyNdx = intval ($propertyParts[3]);
				$chkSumOld = $propertyParts[4];
				if ($propertyParts[0] === 'contacts')
					$chkSumNew = md5 ($prop['value'].$prop['note']);
				else
					$chkSumNew = md5 ($prop['value']);
				if ($chkSumOld !== $chkSumNew)
				{
					if ($propertyNdx === 0)
					{
						if ($prop['value'] !== '' || $prop['note'] !== '')
						{
							$p = [
								'group' => $propertyParts[0], 'property' => $propertyParts[1], 'tableid' => 'e10.persons.persons',
								'recid' => $personNdx, 'valueString' => $prop['value'], 'note' => $prop['note']
							];

							if ($propertyParts[1] === 'birthdate')
							{
								if ($prop['value'] !== '0000-00-00') {
									$p['valueDate'] = $prop['value'];
									$p['valueNum'] = utils::createDateTime($prop['value'])->format('U');
								} else
									$p['valueString'] = '';
							}
							$this->db()->query('INSERT INTO [e10_base_properties] ', $p);
						}
					}
					else
					{
						$p = ['valueString' => $prop['value'], 'note' => isset($prop['note']) ? $prop['note'] : ''];
						if ($propertyParts[1] === 'birthdate')
						{
							if ($prop['value'] !== '0000-00-00')
							{
								$p['valueDate'] = $prop['value'];
								$p['valueNum'] = utils::createDateTime($prop['value'])->format('U');
							}
							else
								$p['valueString'] = '';
						}
						if ($p['valueString'] !== '' || $p['note'] !== '')
							$this->db()->query('UPDATE [e10_base_properties] SET ', $p, ' WHERE [ndx] = %i', $propertyNdx);
						else
							$this->db()->query('DELETE FROM [e10_base_properties] WHERE [ndx] = %i', $propertyNdx);
					}
				}
				// -- nastavení čísla účtu - časem se to musí vyřešit lépe
				if ($recData['docType'] === 'purchase' && $recData['docStateMain'] != 0 && $propertyParts[1] === 'bankaccount' && $prop['value'] !== $recData['bankAccount'])
				{
					$this->db()->query ('UPDATE [e10doc_core_heads] SET [bankAccount] = %s WHERE ndx = %i', $prop['value'], $recData['ndx']);
					$recData['bankAccount'] = $prop['value'];
				}
			}
		}
		*/
		/*
		if (isset ($extraData['personaddress']))
		{ // 1884-333223-85006de6f8a6598c7fa9f4e9545c5f87
			$id = key($extraData['personaddress']);
			$a = $extraData['personaddress'][$id];
			$propertyParts = explode ('-', $id);
			//$personNdx = intval ($propertyParts[0]);
			$addressNdx = intval ($propertyParts[1]);
			$chkSumOld = $propertyParts[2];

			$chkSumNew = md5 ($a['street'].$a['city'].$a['zipcode']);
			if ($chkSumOld !== $chkSumNew)
			{
				if ($addressNdx !== 0)
				{
					$this->db()->query ('UPDATE [e10_persons_address] SET ', $a, ' WHERE ndx = ', $addressNdx);
				}
			}
		}
		*/
	}

	public function docAdditionsOur ($head, $row, $sendReportNdx = 0)
	{
		$list = [];
		$allAdditionsTypes = $this->app()->cfgItem ('e10doc.additionsTypes');

		$itemType = NULL;
		$itemTypeNdx = 0;
		$itemTypeId = ($row && isset($row['itemType'])) ? $row['itemType'] : '';
		if ($itemTypeId !== '')
			$itemType = $this->app()->cfgItem ('e10.witems.types.'.$itemTypeId, NULL);
		if ($itemType)
			$itemTypeNdx = $itemType['ndx'];

		$warehouseNdx = (isset($head['warehouse'])) ? $head['warehouse'] : 0;

		$date = (isset($head['dateAccounting'])) ? $head['dateAccounting'] : NULL;

		$q = [];
		array_push($q, 'SELECT * FROM e10doc_base_additions AS a');

		array_push($q, 'WHERE 1');
		array_push($q, ' AND docState != %i', 9800);

		if (!utils::dateIsBlank($date))
		{
			array_push($q, ' AND ([validFrom] IS NULL OR [validFrom] <= %d)', $date);
			array_push($q, ' AND ([validTo] IS NULL OR [validTo] >= %d)', $date);
		}


		if ($itemTypeNdx)
		{
			array_push($q,
				' AND EXISTS (SELECT ndx FROM e10_base_doclinks AS l WHERE linkId = %s', 'e10doc-base-additions-itemTypes',
				' AND srcTableId = %s', 'e10doc.base.additions', ' AND dstRecId = %i', $itemTypeNdx,
				' AND l.srcRecId = a.ndx)'
				);
		}

		if ($warehouseNdx)
		{
			array_push($q,
				' AND EXISTS (SELECT ndx FROM e10_base_doclinks AS l WHERE linkId = %s', 'e10doc-base-additions-whs',
				' AND srcTableId = %s', 'e10doc.base.additions', ' AND dstRecId = %i', $warehouseNdx,
				' AND l.srcRecId = a.ndx)'
			);
		}

		array_push($q, 'ORDER BY [rowMark], [ndx]');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$type = $allAdditionsTypes[$r['additionType']];

			if ($sendReportNdx)
			{
				$sendReports = UtilsBase::linkedSendReports($this->app(), 'e10doc.base.additions', $r['ndx']);
				if (count($sendReports))
				{
					if (!isset($sendReports[$sendReportNdx]))
						continue;
				}
			}

			$rowMark = $r['rowMark'];

			$item = [
				'ndx' => $r['ndx'], 'mark' => $rowMark, 'identifier' => $r['identifier'],
				'labelPrefix' => $type['labelPrefix'], 'id' => $type['id'],
			];
			$list[] = $item;
		}

		if (count($list))
			return $list;

		return FALSE;
	}
}

/**
 * Pohled na běžné doklady
 *
 */

class ViewHeads extends TableView
{
	var $docType;
	var $docTypes;
	var $icon = '';
	var $currencies;
	var $warehouses;
	var $paymentMethods;
	var $bottomTabsStyle = bottomTabs_None;
	var $today;
	var $disabledActivitiesGroups;
	var $classification;

	var $showWorkOrders = FALSE;
	var $workOrders = [];
	var $tableWorkOrders = NULL;

	var $accounting = TRUE;
	var $useMoreTaxRegs = 0;
	var $taxRegs = NULL;

	var $rosClasses = [0 => 'label-default', 1 => 'label-success', 2 => 'label-warning', 3 => 'label-danger'];

	public function init ()
	{
		parent::init();

		if ($this->app()->model()->table ('e10doc.debs.journal') === FALSE)
			$this->accounting = FALSE;

		$this->docTypes = $this->app()->cfgItem ('e10.docs.types');
		if (isset ($this->docType))
		{
			$this->addAddParam ('docType', $this->docType);
			$docType = $this->docTypes [$this->docType];
			$this->icon = $docType['icon'];
		}

		$this->currencies = $this->table->app()->cfgItem ('e10.base.currencies');
		$this->today = date('ymd');
		$this->paymentMethods = $this->table->app()->cfgItem ('e10.docs.paymentMethods');
		$this->useMoreTaxRegs = intval($this->table->app()->cfgItem ('e10doc.base.tax.flags.moreRegs', 0));
		$this->taxRegs = $this->table->app()->cfgItem ('e10doc.base.taxRegs');

		$this->createMainQueries ();
		$this->createBottomTabs ();

		$panels = [];
		$panels [] = ['id' => 'qry', 'title' => 'Hledání'];
		$this->addPanels($panels);
		if (isset ($this->viewerDefinition['panels']))
			$panels = array_merge($panels, $this->viewerDefinition['panels']);

		$this->setPanels($panels);
	}

	function addPanels(&$panels)
	{
	}

	public function createMainQueries ()
	{
		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'all', 'title' => 'Vše'];
		$mq [] = ['id' => 'cancel', 'title' => 'Storna'];
		$mq [] = ['id' => 'trash', 'title' => 'Koš'];
		$this->setMainQueries ($mq);
	}

	public function createBottomTabs ()
	{
		// -- dbCounters
		$dbCounters = $this->table->app()->cfgItem ('e10.docs.dbCounters.' . $this->docType, FALSE);
		if ($dbCounters !== FALSE)
		{
			$activeDbCounter = key($dbCounters);
			if (count ($dbCounters) > 1)
			{
				forEach ($dbCounters as $cid => $c)
				{
					if (isset ($this->disabledActivitiesGroups) && in_array($c['activitiesGroup'], $this->disabledActivitiesGroups))
						continue;
					$addParams = array ('dbCounter' => intval($cid));
					$title = (isset($c['tabName']) && $c['tabName'] !== '') ? $c['tabName'] : $c['shortName'];
					$nbt = array ('id' => $cid, 'title' => $title,
												'active' => ($activeDbCounter === $cid),
												'addParams' => $addParams);
					$bt [] = $nbt;
				}
				$this->setBottomTabs ($bt);
			}
			else
				$this->addAddParam ('dbCounter', $activeDbCounter);
			$this->bottomTabsStyle = bottomTabs_DbCounters;
			return;
		}

		// -- warehouses
		if ($this->docType === 'stockin' || $this->docType === 'stockout' || $this->docType === 'stockinst')
		{
			$this->warehouses = $this->table->app()->cfgItem ('e10doc.warehouses', array());

			forEach ($this->warehouses as $whId => $wh)
				$bt [] = array ('id' => $whId, 'title' => $wh['shortName'], 'active' => 0,
												'addParams' => array ('warehouse' => $whId));
			$bt [] = array ('id' => '', 'title' => 'Vše', 'active' => 0);
			$bt [0]['active'] = 1;
			$this->setBottomTabs ($bt);
			$this->bottomTabsStyle = bottomTabs_Warehouses;
			return;
		}
	}

	public function createPanelContent (TableViewPanel $panel)
	{
		$p = utils::searchArray($this->panels, 'id', $panel->panelId);

		if (isset ($p['type']) && $p['type'] === 'viewer')
		{
			$params = (isset($p['params'])) ? $p['params'] : [];
			$panel->addContentViewer ($p['table'], $p['class'], $params);
			return;
		}

		parent::createPanelContent ($panel);
	}

	public function createPanelContentQry (TableViewPanel $panel)
	{
		$qry = array();
		$paramsDates = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$periodFlags = array('enableAll', 'quarters', 'halfs', 'years');
		if ($this->docType === 'cmnbkp')
			$periodFlags[] = 'openclose';
		$paramsDates->addParam ('fiscalPeriod', 'query.period.fiscal',array('flags' => $periodFlags));

		if (isset($this->docType))
		{
			$docType = $this->docTypes [$this->docType];
			if (isset($docType['taxDocument']))
				$paramsDates->addParam ('vatPeriod', 'query.period.vat', array('flags' => array('enableAll')));
		}
		$paramsDates->addParam ('date', 'query.dateAccounting.from', array('title' => 'Datum od'));
		$paramsDates->addParam ('date', 'query.dateAccounting.to', array('title' => 'Datum do'));

		$paramsDates->detectValues();




		$paramsRows = new \e10doc\core\libs\GlobalParams ($panel->table->app());
		$paramsRows->addParam ('string', 'query.rows.text', ['title' => 'Text řádku']);
		$paramsRows->addParam ('float', 'query.rows.amount', ['title' => 'Částka']);
		$paramsRows->addParam ('float', 'query.rows.amountDiff', ['title' => '+/-']);

		$qry[] = array ('id' => 'paramDates', 'style' => 'params', 'title' => 'Období', 'params' => $paramsDates);
		$qry[] = array ('id' => 'paramRows', 'style' => 'params', 'title' => 'Hledat v řádcích', 'params' => $paramsRows);

		// -- tags
		$clsf = \E10\Base\classificationParams ($this->table);
		foreach ($clsf as $cg)
		{
			$params = new \E10\Params ($panel->table->app());
			$params->addParam ('checkboxes', 'query.clsf.'.$cg['id'], ['items' => $cg['items']]);
			$qry[] = array ('style' => 'params', 'title' => $cg['name'], 'params' => $params);
		}

		$panel->addContent(array ('type' => 'query', 'query' => $qry));
	}


	public function qryCommon (array &$q)
	{
		if (isset ($this->docType))
      array_push ($q, " AND heads.[docType] = %s", $this->docType);

		if ($this->bottomTabsStyle === bottomTabs_DbCounters)
		{
			$dbCounter = intval($this->bottomTabId ());
			if ($dbCounter)
				array_push ($q, " AND heads.[dbCounter] = %i", $dbCounter);
		}
		else
		if ($this->bottomTabsStyle === bottomTabs_Warehouses)
		{
			$wh = intval ($this->bottomTabId ());
			if ($wh !== 0)
				array_push ($q, " AND heads.[warehouse] = %i", $wh);
		}

		$this->qryPanel($q);
	}

	public function qryFulltext (array &$q)
	{
		// -- fulltext
		$fts = $this->fullTextSearch ();

		$ascii = TRUE;
		if(preg_match('/[^\x20-\x7f]/', $fts))
			$ascii = FALSE;

		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' persons.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR heads.[title] LIKE %s', '%'.$fts.'%');

			if (isset ($this->docType) && ($this->docType === 'invno' || $this->docType === 'invni' || $this->docType === 'purchase'))
				array_push ($q, ' OR heads.[symbol1] LIKE %s', $fts.'%');

			if ($ascii)
				array_push ($q, ' OR heads.[docNumber] LIKE %s', $fts.'%');

			$this->qryFulltextSub ($fts, $q);

			array_push ($q, ')');
		}
	}

	protected function qryFulltextSub ($fts, array &$q)
	{
	}

	public function qryMain (array &$q)
	{
		$mainQuery = $this->mainQueryId ();

		if ($mainQuery == 'active' || $mainQuery == '')
			array_push ($q, " AND heads.[docStateMain] < 4");

		if ($mainQuery == 'cancel')
			array_push ($q, " AND heads.[docStateMain] = 2 AND heads.[docState] = 4100");

		if ($mainQuery == 'trash')
			array_push ($q, " AND heads.[docStateMain] = 4");

		$this->qryOrder($q, $mainQuery);
	}

	public function qryOrder (array &$q, $mainQueryId)
	{
		if ($mainQueryId === 'all')
			array_push ($q, ' ORDER BY [docNumber] DESC' . $this->sqlLimit());
		else
			array_push ($q, ' ORDER BY heads.[docStateMain], [docNumber] DESC' . $this->sqlLimit());
	}

	public function qryPanel (array &$q)
	{
		$qv = $this->queryValues();

		e10utils::datePeriodQuery('dateAccounting', $q, $qv);

		if (isset ($qv['period']['fiscal']))
			e10utils::fiscalPeriodQuery($q, $qv['period']['fiscal']);
		if (isset ($qv['period']['vat']))
			e10utils::vatPeriodQuery($q, $qv['period']['vat']);

		$rowsQuery = 0;
		if (isset($qv['rows']['text']) && $qv['rows']['text'] != '')
			$rowsQuery = 1;
		if (isset ($qv['rows']['amount']) && $qv['rows']['amount'] != '')
			$rowsQuery = 1;

		if ($rowsQuery)
		{
			array_push($q, ' AND EXISTS (SELECT ndx FROM e10doc_core_rows as [rows] WHERE heads.ndx = [rows].document');

			if (isset($qv['rows']['text']) && $qv['rows']['text'] != '')
			{
				array_push($q, ' AND [rows].[text] LIKE %s', '%'.$qv['rows']['text'].'%');
			}

			e10utils::amountQuery ($q, '[rows].[priceAll]', $qv['rows']['amount'], $qv['rows']['amountDiff']);

			array_push($q, ' )');
		}

		if (isset($qv['clsf']))
		{
			array_push ($q, ' AND EXISTS (SELECT ndx FROM e10_base_clsf WHERE heads.ndx = recid AND tableId = %s', 'e10doc.core.heads');
			foreach ($qv['clsf'] as $grpId => $grpItems)
				array_push ($q, ' AND ([group] = %s', $grpId, ' AND [clsfItem] IN %in', array_keys($grpItems), ')');
			array_push ($q, ')');
		}
	}

	function globalDetailEnabled($detailId, $detailCfg)
	{
		if ($detailId === 'z_workflow')
			return TRUE;

		return parent::globalDetailEnabled($detailId, $detailCfg);
	}

	public function selectRows ()
	{
		$q [] = 'SELECT';
		array_push($q, ' heads.[ndx] as ndx, [docNumber], [title], [sumPrice], [sumBase], [sumTotal], [toPay], [cashBoxDir], [dateIssue], [dateAccounting], [person],');
		array_push($q, ' heads.[docType] as docType, heads.[docState] as docState, heads.[docStateMain] as docStateMain, symbol1, heads.weightGross as weightGross,');
		array_push($q, ' heads.[taxPayer] as taxPayer, heads.[taxCalc] as taxCalc, heads.currency as currency, heads.homeCurrency as homeCurrency,');

		if ($this->accounting)
			array_push($q, ' heads.docStateAcc as docStateAcc,');

		array_push($q, ' persons.fullName as personFullName, heads.[paymentMethod],');
		array_push($q, ' heads.[rosReg] as rosReg, heads.[rosState] as rosState,');
		array_push($q, ' heads.[vatReg], heads.[taxCountry]');
		array_push($q, ' FROM [e10doc_core_heads] AS heads');
		array_push($q, ' LEFT JOIN [e10_persons_persons] AS persons ON heads.person = persons.ndx');
		array_push($q, ' WHERE 1');

		$this->qryCommon ($q);
		$this->qryFulltext ($q);
		$this->qryMain($q);
		$this->runQuery ($q);
	}

	function decorateRow (&$item)
	{
		if (!isset($item ['pk']))
			return;

		if (isset ($this->classification [$item ['pk']]))
		{
			if (isset($item ['t3']) && $item ['t3'] !== '')
				$item ['t3'] = [['text' => $item ['t3'], 'class' => '']];
			else
				$item ['t3'] = [];
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t3'] = array_merge ($item ['t3'], $clsfGroup);
		}

		if (isset ($this->workOrders[$item ['pk']]))
		{
			$inv = [];
			$totalCnt = count($this->workOrders[$item ['pk']]);
			$plus = NULL;
			$plusCnt = 0;
			$max = 2;
			$cnt = 0;
			foreach ($this->workOrders[$item ['pk']] as $docNumber => $wo)
			{
				$cnt++;
				if ($cnt <= $max || (!$plusCnt && ($totalCnt - $cnt) == 0))
				{
					$docNumber = ['text' => $docNumber, 'title' => $wo['title'], 'class' => 'tag tag-small '.$wo['docStateClass'], 'icon' => 'tables/e10mnf.core.workOrders'];
					if (isset($wo['refId1']))
						$docNumber['suffix'] = $wo['refId1'];
					$inv[] = $docNumber;
				}
				else
				{
					if ($plus === NULL)
						$plus = ['class' => 'tag tag-small tag-info', 'icon' => 'tables/e10mnf.core.workOrders', 'amount' => 0.0];
					$plus['amount'] += $wo['amount'];
					$plusCnt++;
				}
			}
			if ($plus)
			{
				$plus['text'] = '+ '.$plusCnt.' dalších';
				$inv[] = $plus;
			}
			$item['t2'] = array_merge($item['t2'], $inv);
		}
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = \E10\Base\loadClassification ($this->table->app(), $this->table->tableId(), $this->pks, 'label label-info pull-right');

		if ($this->showWorkOrders)
		{
			$this->tableWorkOrders = $this->app()->table ('e10mnf.core.workOrders');

			$q[] = 'SELECT [rows].document, [rows].workOrder, [rows].priceAll, wo.docNumber, wo.title AS woTitle, woCustomers.fullName as woCustomerName,';
			array_push($q, ' wo.docState as woDocState, wo.docStateMain as woDocStateMain, wo.intTitle as woIntTitle, wo.refId1 as woRefId1');
			array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
			array_push($q, ' LEFT JOIN [e10mnf_core_workOrders] AS wo ON [rows].workOrder = wo.ndx');
			array_push($q, ' LEFT JOIN [e10_persons_persons] AS woCustomers ON wo.customer = woCustomers.ndx');
			array_push($q, ' WHERE [rows].document IN %in', $this->pks, ' AND [rows].workOrder != 0');
			$rows = $this->db()->query($q);
			foreach ($rows as $r)
			{
				$docNdx = $r['document'];
				$woNumber = $r['docNumber'];
				if (isset($this->workOrders[$docNdx][$woNumber]))
					$this->workOrders[$docNdx][$woNumber]['amount'] += $r['priceAll'];
				else
				{
					$this->workOrders[$docNdx][$woNumber]['amount'] = $r['priceAll'];
					$t = '';
					if ($r['woCustomerName'])
						$t = '👤 '.$r['woCustomerName'];
					if ($r['woTitle'] && $r['woTitle'] !== '')
					{
						if ($t !== '')
							$t .= "\n ✎ ";
						$t .= $r['woTitle'];
					}
					if ($r['woIntTitle'])
					{
						if ($t !== '')
							$t .= "\n ✎ ";
						$t .= $r['woIntTitle'];
					}
					if ($t !== '')
						$this->workOrders[$docNdx][$woNumber]['title'] = $t;

					if ($r['woRefId1'] !== '')
						$this->workOrders[$docNdx][$woNumber]['refId1'] = $r['woRefId1'];

					$woItem = ['docState' => $r['woDocState'], 'docStateMain' => $r['woDocStateMain']];
					$woDocState = $this->tableWorkOrders->getDocumentState ($woItem);
					$woDocStateClass = $this->tableWorkOrders->getDocumentStateInfo ($woDocState['states'], $woItem, 'styleClass');
					$this->workOrders[$docNdx][$woNumber]['docStateClass'] = $woDocStateClass;
				}
			}
		}
	}

	public function renderRow ($item)
	{
		$docType = $this->docTypes [$item ['docType']];
		$headerStyle = utils::param ($docType, 'headerStyle', 'tb');
		$showInfo2 = utils::param ($docType, 'showInfo2', 'toPay');
		$showDate = utils::param ($docType, 'showDate', 'd');

		$listItem ['pk'] = $item ['ndx'];
		if ($this->icon === '')
			$listItem ['icon'] = $docType['icon'];
		else
			$listItem ['icon'] = $this->icon;

		$listItem ['t1'] = $item['personFullName'];

		if ($headerStyle === 'toPay')
			$i1 = ['text' => \E10\nf ($item['toPay'], 2)];
		else
		{
			if ($item['taxPayer'])
				$i1 = ['text' => \E10\nf ($item['sumBase'], 2)];
			else
				$i1 = ['icon' => $this->paymentMethods[$item['paymentMethod']]['icon'], 'text' => \E10\nf ($item['sumBase'], 2)];
		}

		if ($item ['currency'] != $item ['homeCurrency'])
			$i1 ['prefix'] = $this->currencies[$item ['currency']]['shortcut'];
		$listItem ['i1'] = $i1;

		if ($showInfo2 === 'toPay')
		{
			if ($item ['taxPayer'])
				$listItem ['i2'] = ['icon' => $this->paymentMethods[$item['paymentMethod']]['icon'], 'text' => \E10\nf ($item['toPay'], 2)];
		}
		else
		if ($showInfo2 === 'tb')
		{
			if ($item ['taxPayer'])
			{
				if ($item ['taxCalc'])
					$listItem ['i2'] = 'bez DPH: ' . utils::nf ($item['sumBase'], 2);
			}
		}
		else
		if ($showInfo2 === 'wg')
		{
			$listItem ['i2'] = \E10\nf ($item['weightGross'], 2) . ' kg';
		}

		$listItem ['t3'] = $item ['title'];

		$docNumber = ['icon' => 'system/iconFile', 'text' => $item ['docNumber'], 'class' => ''];
		if (isset($item['docStateAcc']) && $item['docStateAcc'] == 9)
			$docNumber['class'] = 'e10-error';

		$props [] = $docNumber;

		if ($this->useMoreTaxRegs && $item['taxPayer'] && $item['vatReg'])
		{
			$taxReg = $this->taxRegs[$item['vatReg']] ?? NULL;
			if ($taxReg)
			{
				if ($taxReg['payerKind'] === 0)
					$taxRegLabel = ['text' => strtoupper($taxReg['taxCountry']), 'class' => 'label label-default', 'icon' => 'tables/e10doc.base.taxRegs'];
				else
					$taxRegLabel = ['text' => 'OSS', 'suffix' => strtoupper($item['taxCountry']), 'class' => 'label label-default', 'icon' => 'tables/e10doc.base.taxRegs'];

				$props [] = $taxRegLabel;
			}
		}

		if ($item ['symbol1'] != '' && $item ['symbol1'] !== $item ['docNumber'])
			$props [] = ['icon' => 'system/iconExchange', 'text' => $item ['symbol1'], 'class' => ''];

		if ($showDate === 'c' && $item['dateIssue']->format('ymd') === $this->today)
		{
			$props [] = ['icon' => 'icon-clock-o', 'text' => \E10\df ($item['activateTimeFirst']), 'class' => ''];
			if ($item['activateTimeFirst'] != $item['activateTimeLast'])
				$props [] = ['icon' => 'icon-pencil', 'text' => \E10\df ($item['activateTimeLast']), 'class' => ''];
			$listItem ['t2'] = $props;
		}
		else
		{
			$props [] = ['icon' => 'system/iconCalendar', 'text' => \E10\df ($item['dateAccounting'], '%D'), 'class' => ''];
		}
		$this->renderRow_rosProps ($item, $props);
		$listItem ['t2'] = $props;
		return $listItem;
	}

	function renderRow_rosProps ($item, &$props)
	{
		if (isset($item['rosReg']) && $item['rosReg'])
		{
			$props[] = ['text' => 'EET', 'icon' => 'icon-microchip', 'class' => 'label '.$this->rosClasses[$item['rosState']]];
		}
	}
} // class ViewHeads


/**
 * Editační formulář hlavičky běžného dokladu
 *
 */

class FormHeads extends TableForm
{
	protected $sumBaseHcFromRows = 0.0;
	protected $sumDebitHcFromRows = 0.0;
	protected $sumCreditHcFromRows = 0.0;
	protected $tablePersons;

	protected $testNewDocRowsEdit  = 0;

	var $useAttInfoPanel = 0;

	var $vatRegs = NULL;

	function openForm ($layoutType = TableForm::ltForm)
	{
		$this->testNewDocRowsEdit = $this->app()->cfgItem ('options.experimental.testNewDocRowsEdit', 0);

		parent::openForm ($layoutType);
		$this->addColumnInput ("docType", TableForm::coHidden);
	}

	protected function addVatSettingsIn ()
	{
		$this->addColumnInput ("taxRoundMethod");
		$this->addColumnInput ("taxPercentDateType");
		$this->addColumnInput ('vatCS');
		$this->addColumnInput ('personVATIN');
	}

	public function addAccountingTab (array &$tabs)
	{
		if (!$this->readOnly)
			return;

		if (!$this->table->app()->hasRole ('acc') && !$this->table->app()->hasRole ('audit'))
			return;

		if ($this->table->accountingDocument ($this->recData))
			$tabs [] = array ('text' => 'Účtování', 'icon' => 'system/formAccounting');
	}

	public function addAccountingTabContent ()
	{
		if (!$this->readOnly)
			return;

		if (!$this->table->app()->hasRole ('acc') && !$this->table->app()->hasRole ('audit'))
			return;

		if (!$this->table->accountingDocument ($this->recData))
			return;

		$this->openTab (TableForm::ltNone);
			if (isset($this->recData['docStateMain']) && $this->recData['docStateMain'] > 1)
				$this->addDocumentCard('e10doc.core.dc.Accounting');
			else
				$this->addStatic(['text' => 'Doklad není uzavřen', 'class' => 'padd5']);
		$this->closeTab ();
	}

	public function addAttachmentsTabContent ()
	{
		$this->openTab (TableForm::ltNone);
		if ($this->readOnly)
			$this->addDocumentCard('e10doc.core.dc.Attachments');
		else
			$this->addAttachmentsViewer();
		$this->closeTab ();
	}

	protected function addCurrency ($colOptions = 0)
	{
		$this->addColumnInput ("currency", $colOptions);
		if ($this->recData ['currency'] != $this->recData ['homeCurrency'] || ($this->recData ['exchangeRate'] != 0 && $this->recData ['exchangeRate'] != 1))
			$this->addColumnInput ("exchangeRate");
	}

	protected function addRecapitulation ()
	{
		$sendDocsAttachments = intval($this->app()->cfgItem ('options.experimental.sendDocsAttachments', 0));

		$docType = $this->app()->cfgItem ('e10.docs.types.' . $this->recData ['docType'], FALSE);

		$this->layoutOpen (TableForm::ltGrid);
			$this->addColumnInput ("title", TableForm::coColW12);
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout|TableForm::coColW12);

			if ($sendDocsAttachments)
			{
				if ($this->recData ['docType'] === 'invno')
					$this->addList ('sendAtts', '', TableForm::loAddToFormLayout|TableForm::coColW12);
			}
			$this->addList ('clsf', '', TableForm::loAddToFormLayout|TableForm::coColW12);
		$this->layoutClose ();

		if ($docType['useTax'])
		{
			$this->layoutOpen (TableForm::ltForm);
			if ($this->recData['taxPayer'] && $this->recData['taxCalc'] != 0)
			{
				$this->addSeparator();
				$this->openRow ();
					$this->addStatic ('Rekapitulace DPH', TableForm::coH1);

					if ($this->recData['docType'] === 'invni' || ($this->recData['docType'] === 'cash' && $this->recData['cashBoxDir'] == 2))
						$this->addColumnInput ('taxManual', TableForm::coRight);
				$this->closeRow ();

				if ($this->recData['taxPayer'] && isset($this->recData['taxManual']) && $this->recData['taxManual'] != 0)
					$this->addList ('taxes');
				else
					$this->addStatic($this->table->summaryVAT ($this->recData));
			}
			$this->layoutClose ('padd5');
		}
	}

	function checkInfoPanelAttachments($width = '36vw')
	{
		if ($this->viewPortWidth > 1900)
			$this->useAttInfoPanel = intval($this->app()->cfgItem ('options.experimental.testAttInDocEditForms', 0));

		if ($this->useAttInfoPanel)
		{
			$this->setFlag('infoPanelPos', 1);
			$this->setFlag('infoPanelWidth', $width);
		}
	}

	function addInfoPanelAttachments($startCode = '')
	{
		if (!$this->useAttInfoPanel)
			return;

		$card = $this->app()->createObject('e10doc.core.dc.Attachments');
		$card->setDocument($this->table, $this->recData);
		$card->createContent();

		$cr = new ContentRenderer($this->app());
		$cr->setDocumentCard($card);

		$c = '';
		$c .= "<div class='infoPanelContent' style='min-height: 100%; background-color: #FAFAFA; overflow-y: auto; padding-left: 2px; padding-right: 2px; border-left: 6px solid steelblue;'>";
		$c .= $startCode;
		$c .= $cr->createCode('body');
		$c .= '</div>';
		$this->infoPanel = $c;
	}

	public function appendListRow ($listId)
	{
		if ($this->recData['docType'] === 'cashreg' || $this->recData['docType'] === 'purchase' || $this->recData['docType'] === 'mnf')
			return FALSE;
		return parent::appendListRow($listId);
	}

	public function checkAfterSave ()
	{
    $this->calcTaxes ();
		$this->calcMoney ();

		if ($this->recData ['currency'] === $this->recData ['homeCurrency'] && $this->recData ['sumBaseHc'] !== $this->sumBaseHcFromRows)
		{
			$sumBaseHcCorr = round($this->recData ['sumBaseHc'] - $this->sumBaseHcFromRows, 2);
			$q = 'SELECT ndx, taxBaseHc FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY taxBaseHc DESC LIMIT 1';
			$bigRow = $this->table->db()->query ($q, $this->recData['ndx'])->fetch ();
			if (isset ($bigRow['ndx']))
				$this->table->db()->query ('UPDATE e10doc_core_rows SET taxBaseHcCorr = %f WHERE ndx = %i', $sumBaseHcCorr, $bigRow['ndx']);
		}

		if ($this->recData ['currency'] !== $this->recData ['homeCurrency'] && $this->recData ['debit'] === $this->recData ['credit'])
		{
			if ($this->recData ['creditHc'] !== $this->sumCreditHcFromRows)
			{
				$sumCreditHcCorr = round($this->recData ['creditHc'] - $this->sumCreditHcFromRows, 2);
				$q = 'SELECT ndx, taxBaseHc FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY creditHc DESC LIMIT 1';
				$bigRow = $this->table->db()->query ($q, $this->recData['ndx'])->fetch ();
				if (isset ($bigRow['ndx']))
					$this->table->db()->query ('UPDATE e10doc_core_rows SET taxBaseHcCorr = %f WHERE ndx = %i', $sumCreditHcCorr, $bigRow['ndx']);
			}
			if ($this->recData ['debitHc'] !== $this->sumDebitHcFromRows)
			{
				$sumDebitHcCorr = round($this->recData ['debitHc'] - $this->sumDebitHcFromRows, 2);
				$q = 'SELECT ndx, taxBaseHc FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY debitHc DESC LIMIT 1';
				$bigRow = $this->table->db()->query ($q, $this->recData['ndx'])->fetch ();
				if (isset ($bigRow['ndx']))
					$this->table->db()->query ('UPDATE e10doc_core_rows SET taxBaseHcCorr = %f WHERE ndx = %i', $sumDebitHcCorr, $bigRow['ndx']);
			}
		}

		if ($this->recData['docNumber'] == '')
			$this->recData['docNumber'] = '!'.sprintf ('%09d', $this->recData['ndx']);

		return true;
	}

  public function calcMoney ()
	{
   	// sum money
		$q = "SELECT SUM(credit) as credit, SUM(debit) as debit, SUM(creditHc) as creditHc, SUM(debitHc) as debitHc, SUM(taxBaseHc) as sumBaseHc,
								 SUM(costBase) AS costBase, SUM(costTotal) AS costTotal, SUM(costBaseHc) AS costBaseHc, SUM(costTotalHc) AS costTotalHc,
								 SUM(quantity) as quantity, SUM(weightNet) as weightNet, SUM(weightGross) as weightGross
								 FROM [e10doc_core_rows]
								 WHERE [document] = %i AND rowType != 1";
		$sum = $this->table->db()->query ($q, $this->recData['ndx'])->fetch ();

		$this->sumBaseHcFromRows = $sum ['sumBaseHc'];
		$this->sumCreditHcFromRows = $sum ['creditHc'];
		$this->sumDebitHcFromRows = $sum ['debitHc'];

		$this->recData ['credit'] = $sum ['credit'];
    $this->recData ['debit'] = $sum ['debit'];

		$this->recData ['costBase'] = $sum ['costBase'];
		$this->recData ['costTotal'] = $sum ['costTotal'];

		if ($this->recData ['currency'] !== $this->recData ['homeCurrency'] && $this->recData ['docType'] !== 'bank')
		{
			$this->recData ['creditHc'] = round ($this->recData ['credit'] * $this->recData ['exchangeRate'], 2);
			$this->recData ['debitHc'] = round ($this->recData ['debit'] * $this->recData ['exchangeRate'], 2);
			$this->recData ['costBaseHc'] = round ($this->recData ['costBase'] * $this->recData ['exchangeRate'], 2);
			$this->recData ['costTotalHc'] = round ($this->recData ['costTotal'] * $this->recData ['exchangeRate'], 2);
		}
		else
		{
			$this->recData ['creditHc'] = $sum ['creditHc'];
			$this->recData ['debitHc'] = $sum ['debitHc'];
			$this->recData ['costBaseHc'] = $sum ['costBaseHc'];
			$this->recData ['costTotalHc'] = $sum ['costTotalHc'];
		}

    $this->recData ['quantity'] = $sum ['quantity'];
    $this->recData ['weightNet'] = $sum ['weightNet'];
    $this->recData ['weightGross'] = $sum ['weightGross'];

		// balance
		$this->recData ['balance'] = $this->recData ['initBalance'] + $this->recData ['credit'] - $this->recData ['debit'];

		// toPay
		$roundMethod = $this->app()->cfgItem ('e10.docs.roundMethods.' . $this->recData['roundMethod'], 0);

		$this->recData ['rounding'] = utils::e10round ($this->recData ['sumTotal'] + $this->recData ['advance'], $roundMethod) - \round ($this->recData ['sumTotal'] + $this->recData ['advance'], 2);
    $this->recData ['toPay'] = round (($this->recData ['sumTotal'] + $this->recData ['advance'] + $this->recData ['rounding']), 2);

		// home currency
    $this->recData ['advanceHc'] = round (($this->recData ['advance'] * $this->recData ['exchangeRate']), 2);
    $this->recData ['toPayHc'] = round (($this->recData ['toPay'] * $this->recData ['exchangeRate']), 2);

		if ($this->recData ['currency'] !== $this->recData ['homeCurrency'])
    	$this->recData ['roundingHc'] = round (($this->recData ['toPayHc'] - ($sum ['sumBaseHc'] + $this->recData ['sumTaxHc'])), 2);
		else
			$this->recData ['roundingHc'] = $this->recData ['rounding'];

		// totalCash
		$paymentMethod = $this->app()->cfgItem ('e10.docs.paymentMethods.' . $this->recData['paymentMethod'], 0);
		$this->recData ['totalCash'] = 0.0;
		if ($paymentMethod ['cash'])
		{
			if (($this->recData['docType'] === 'cash' && $this->recData['cashBoxDir'] === 2) || ($this->recData['docType'] === 'invni')  || ($this->recData['docType'] === 'purchase'))
				$this->recData ['totalCash'] = - $this->recData ['toPay'];
			else
				$this->recData ['totalCash'] = $this->recData ['toPay'];
		}
  }

	public function calcTaxes ()
	{
		// clean totals
		$this->recData ['sumPrice'] = 0.0;
		$this->recData ['sumBase'] = 0.0;
		$this->recData ['sumTax'] = 0.0;
		$this->recData ['sumTotal'] = 0.0;

    $this->recData ['sumPriceHc'] = 0.0;
		$this->recData ['sumBaseHc'] = 0.0;
		$this->recData ['sumTaxHc'] = 0.0;
		$this->recData ['sumTotalHc'] = 0.0;

		$docTaxCodes = E10Utils::docTaxCodes($this->app(), $this->recData);

		if ($this->recData ['taxManual'])
		{ // manual taxes; calc sums only
			$q = 'SELECT taxCode, sumBase, sumTax, sumTotal, sumPrice,'.
					 ' sumBaseHc, sumTaxHc, sumTotalHc as sumTotalHc, sumPriceHc as sumPriceHc'.
					 ' FROM [e10doc_core_taxes] WHERE [document] = %i';
			$newTaxes = $this->table->db()->query ($q, $this->recData ['ndx']);
			forEach ($newTaxes as $newTax)
			{
				$cfgTaxCode = $docTaxCodes[$newTax['taxCode']];//$this->app()->cfgItem ('e10.base.!taxCodes.' . $newTax['taxCode'], NULL);

				if (isset ($cfgTaxCode['hidden']) && $cfgTaxCode['hidden'])
					continue;

				$zeroTax = 0;
				if (isset ($cfgTaxCode ['zeroTax']) && ($cfgTaxCode ['zeroTax'] == 1))
					$zeroTax = 1;
				$noPayTax = 0;
				if (isset ($cfgTaxCode ['noPayTax']) && ($cfgTaxCode ['noPayTax'] == 1))
					$noPayTax = 1;

				$this->recData ['sumPrice'] += $newTax['sumPrice'];
				$this->recData ['sumBase'] += $newTax['sumBase'];
				if (!$noPayTax)
					$this->recData ['sumTax'] += $newTax['sumTax'];
				if (!$noPayTax && !$zeroTax)
					$this->recData ['sumTotal'] += $newTax['sumTotal'];
				else
					$this->recData ['sumTotal'] += $newTax['sumBase'];

				$this->recData ['sumPriceHc'] += $newTax['sumPriceHc'];
				$this->recData ['sumBaseHc'] += $newTax['sumBaseHc'];
				if (!$noPayTax)
					$this->recData ['sumTaxHc'] += $newTax['sumTaxHc'];
				if (!$noPayTax && !$zeroTax)
					$this->recData ['sumTotalHc'] += $newTax['sumTotalHc'];
				else
					$this->recData ['sumTotalHc'] += $newTax['sumBaseHc'];
			}
			return;
		}

		$taxRoundMethod = $this->app()->cfgItem ('e10.docs.taxRoundMethods.' . $this->recData['taxRoundMethod'], 0);

		// sum taxes
		$q = "SELECT SUM([priceAll]) as priceAll, SUM([taxBase]) as taxBase, SUM([tax]) as tax, SUM([priceTotal]) as priceTotal,
								 SUM([priceAllHc]) as priceAllHc, SUM([taxBaseHc]) as taxBaseHc, SUM([taxHc]) as taxHc,
								 SUM([priceTotalHc]) as priceTotalHc, SUM(weightNet) as weight, SUM(quantity) as quantity,
								 [dateTax], [taxPeriod], [taxCode], [taxRate], [taxPercents]
					FROM [e10doc_core_rows]
					WHERE [document] = {$this->recData['ndx']} AND rowType != 1
					GROUP BY [dateTax], [taxPeriod], [taxCode], [taxRate], [taxPercents]";
		$newTaxes = $this->table->db()->fetchAll ($q);
		$newRows = [];
		forEach ($newTaxes as $newTax)
		{
			if ($newTax['taxCode'] === '')
				$newTax['taxCode'] = 'EUCZ000';
			$cfgTaxCode = $docTaxCodes[$newTax['taxCode']];
			$zeroTax = 0;
			if (isset ($cfgTaxCode ['zeroTax']) && ($cfgTaxCode ['zeroTax'] == 1))
				$zeroTax = 1;
			$noPayTax = 0;
			if (isset ($cfgTaxCode ['noPayTax']) && ($cfgTaxCode ['noPayTax'] == 1))
				$noPayTax = 1;

			$nt = array ();
			$nt ['document'] = $this->recData ['ndx'];
			$nt ['sumPrice'] = $newTax ['priceAll'];
			$nt ['sumBase'] = $newTax ['taxBase'];
			$nt ['sumTax'] = $newTax ['tax'];
			$nt ['sumTotal'] = $newTax ['priceTotal'];

			$nt ['sumPriceHc'] = $newTax ['priceAllHc'];
			$nt ['sumBaseHc'] = $newTax ['taxBaseHc'];
			$nt ['sumTaxHc'] = $newTax ['taxHc'];
			$nt ['sumTotalHc'] = $newTax ['priceTotalHc'];

			$nt ['weight'] = $newTax ['weight'];
			$nt ['quantity'] = $newTax ['quantity'];

			$nt ['dateTax'] = $newTax ['dateTax'];
			$nt ['dateTaxDuty'] = $this->recData['dateTaxDuty'];
			$nt ['taxPeriod'] = $newTax ['taxPeriod'];
			$nt ['taxCode'] = $newTax ['taxCode'];
			$nt ['taxRate'] = $newTax ['taxRate'];
			$nt ['taxPercents'] = doubleval($newTax ['taxPercents']);

			$this->recData ['sumPrice'] += $nt ['sumPrice'];
			$this->recData ['sumPriceHc'] += $nt ['sumPriceHc'];

      if ($this->recData ['taxMethod'] == 1) {
        // metoda výpočtu daně z hlavičky
        switch (intval ($this->recData ['taxCalc']))
        {
          case 0: // bez daně
                $nt ['sumBase'] = $nt ['sumPrice'];
                $nt ['sumTax'] = 0;
                $nt ['sumTotal'] = $nt ['sumPrice'];
                break;
          case 1: // ze základu
                $nt ['sumBase'] = $nt ['sumPrice'];
                if ($zeroTax)
                  $nt ['sumTax'] = 0.0;
                else
                  $nt ['sumTax'] = utils::e10round ($nt ['sumBase'] * ($nt ['taxPercents'] / 100.0), $taxRoundMethod);

								$nt ['sumTotal'] = $nt ['sumBase'];
								if (!$noPayTax)
									$nt ['sumTotal'] += $nt ['sumTax'];
                break;
          case 2: // z ceny celkem KOEF
								{
									$k = round(($nt ['taxPercents'] / ($nt ['taxPercents'] + 100)), 4);
									$nt ['sumTax'] = utils::e10round(($nt ['sumPrice'] * $k), $taxRoundMethod);
									$nt ['sumBase'] = round(($nt ['sumPrice'] - $nt ['sumTax']), 2);
									if ($noPayTax)
										$nt ['sumTotal'] = $nt ['sumBase'];
									else
										$nt ['sumTotal'] = $nt ['sumPrice'];
								}
								break;
					case 3: // z ceny celkem
								{
									$k = round((100 + $nt ['taxPercents']) / 100, 2);
									$nt ['sumBase'] = utils::e10round(($nt ['sumPrice'] / $k), $taxRoundMethod);
									$nt ['sumTax'] = round(($nt ['sumPrice'] - $nt ['sumBase']), 2);
									if ($noPayTax)
										$nt ['sumTotal'] = $nt ['sumBase'];
									else
										$nt ['sumTotal'] = $nt ['sumPrice'];
								}
								break;
        }
      }

			if ($this->recData ['taxMethod'] == 1)
			{ // přepočet kurzem na domácí měnu v případě výpočtu dph z hlavičky...
				$nt['sumBaseHc'] = round ($nt['sumBase'] * $this->recData ['exchangeRate'], 2);
				$nt['sumTaxHc'] = round ($nt['sumTax'] * $this->recData ['exchangeRate'], 2);
				$nt['sumTotalHc'] = round ($nt['sumTotal'] * $this->recData ['exchangeRate'], 2);
			}

			$this->recData ['sumBase'] += $nt ['sumBase'];
			$this->recData ['sumTotal'] += $nt ['sumTotal'];

			$this->recData ['sumBaseHc'] += $nt ['sumBaseHc'];
			$this->recData ['sumTotalHc'] += $nt ['sumTotalHc'];

			if (!isset ($cfgTaxCode['noPayTax']))
			{
				$this->recData ['sumTax'] += $nt ['sumTax'];
				$this->recData ['sumTaxHc'] += $nt ['sumTaxHc'];
			}

			$newRows [] = $nt;

			if (isset ($cfgTaxCode['reverseTaxCode']))
			{
				$rt = $nt;
				$rt ['taxCode'] = $cfgTaxCode['reverseTaxCode'];
				$newRows [] = $rt;
			}
		} // forEach

		// delete old lines
		$q = "DELETE FROM [e10doc_core_taxes] WHERE [document] = {$this->recData['ndx']}";
		$this->table->db()->query($q);
		// insert new
		foreach ($newRows as $ntr)
			$this->table->db()->query ("INSERT INTO [e10doc_core_taxes]", $ntr);
	} // calcTaxes

	public function checkNewRec ()
	{
		parent::checkNewRec ();

		if (isset ($this->postData))
		{
			if (isset ($this->postData['docActionData.person']))
				$this->recData['person'] = $this->postData['docActionData.person'];
			if (isset ($this->postData['docActionData.title']))
				$this->recData['title'] = $this->postData['docActionData.title'];
			if (isset ($this->postData['docActionData.dbCounter']))
				$this->recData['dbCounter'] = $this->postData['docActionData.dbCounter'];
			if (isset ($this->postData['docActionData.symbol1']))
				$this->recData['symbol1'] = $this->postData['docActionData.symbol1'];
		}

		if (!isset ($this->recData['dbCounter']))
			$this->recData['dbCounter'] = 0;
	}

	function checkLoadedList ($list)
	{
		if (($list->listId === 'doclinks'))
		{
			if (isset($this->recData['fromIssueNdx']))
			{
				if (!isset($list->data['e10docs-inbox']) || !count($list->data['e10docs-inbox']))
				{
					$list->data['e10docs-inbox'] = [['dstTableId' => 'wkf.core.issues', 'dstRecId' => $this->recData['fromIssueNdx'], 'title' => $this->recData['fromIssueSubject'], ]];
				}
			}
		}

		if (isset($this->recData['fromIssueNdx']))
			unset($this->recData['fromIssueNdx']);
		if (isset($this->recData['fromIssueSubject']))
			unset($this->recData['fromIssueSubject']);

		if (isset($this->recData['firstRowItem']) && $list->listId === 'rows' && count($list->data) == 0)
		{
			$witem = $this->table->loadItem ($this->recData['firstRowItem'], 'e10_witems_items');
			if (!$witem)
				return;
			$newRow = array ('ndx' => 0, 'item' => $this->recData['firstRowItem'], 'text' => $witem['fullName']);
			unset ($this->recData['firstRowItem']);

			if (isset ($this->recData['firstRowUnit']))
			{
				$newRow['unit'] = $this->recData['firstRowUnit'];
				unset ($this->recData['firstRowUnit']);
			}
			if (isset ($this->recData['firstRowQuantity']))
			{
				$newRow['quantity'] = $this->recData['firstRowQuantity'];
				unset ($this->recData['firstRowQuantity']);
			}
			if (isset ($this->recData['firstRowOperation']))
			{
				$newRow['operation'] = $this->recData['firstRowOperation'];
				unset ($this->recData['firstRowOperation']);
			}
			$list->data [] = $newRow;
			return;
		}

		if (!isset ($this->postData))
			return;

		if (($list->listId === 'rows') && (count($list->data) == 0))
		{
			$rows = array ();

			forEach ($this->postData as $ddId => $ddValue)
			{
				$parts = explode ('.', $ddId);
				if ($parts[0] !== 'docActionData')
					continue;

				if (isset ($parts[1]) && $parts[1] === 'rows')
				{
					$rows [$parts[2]][$parts[3]] = $ddValue;
					continue;
				}
			}

			$docNumbers = array();
			forEach ($rows as $r)
			{
				if (!isset ($r['enabled']))
					continue;

				$newRow = array ('ndx' => 0);

				if (isset ($this->postData['docActionData.operation']))
					$newRow ['operation'] = $this->postData['docActionData.operation'];

				if (isset($r['symbol1']))
				{
					$newRow ['symbol1'] = $r['symbol1'];
					$docNumbers[] = $r['symbol1'];
				}
				if (isset($r['text']))
					$newRow ['text'] = $r['text'];
				if (isset($r['item']))
					$newRow ['item'] = $r['item'];
				if (isset($r['itemBalance']))
					$newRow ['itemBalance'] = $r['itemBalance'];
				if (isset($r['taxCode']))
					$newRow ['taxCode'] = $r['taxCode'];
				if (isset($r['weightNet']))
					$newRow ['weightNet'] = $r['weightNet'];

				if (isset($r['quantity']))
					$newRow ['quantity'] = $r['quantity'];
				else
				{
					if (isset($r['price']))
					{
						$newRow ['priceItem'] = $r['price'];
						$newRow ['quantity'] = 1;
					}
				}

				$list->data [] = $newRow;
			}
			$this->recData['title'] .= ' '.implode (', ', $docNumbers);
		}
	}

	public function createHeader ()
	{
		return $this->table->createHeader ($this->recData, ['lists' => $this->lists]);
	}

	public function createSaveData ()
	{
		$saveData = ['recData' => $this->recData, 'lists' => ['rows' => []]];
		$saveData ['documentPhase'] = $this->documentPhase;

		foreach ($this->lists['rows'] as $r)
		{
			$saveData['lists']['rows'][] = ['ndx' => $r['ndx'], 'item' => $r['item'], 'quantity' => $r['quantity'],
																			'text' => $r['text'], 'unit' => $r['unit'], 'priceItem' => $r['priceItem']];
		}

		return $saveData;
	}

	protected function renderMainSidebar ($allRecData, $recData)
	{
		if ($allRecData['docType'] !== 'cashreg' && $allRecData['docType'] !== 'purchase' && $allRecData['docType'] !== 'mnf')
			return;

		$comboParams = array ('docType' => strval ($allRecData ['docType']), 'person' => $allRecData ['person']);
		if (is_string($allRecData['dateAccounting']))
			$comboParams['dateAccounting'] = $allRecData ['dateAccounting'];
		else
			$comboParams['dateAccounting'] = $allRecData ['dateAccounting']->format('Y-m-d');

		$browseTable = $this->table->app()->table ('e10.witems.items');

		switch ($allRecData['docType'])
		{
			case 'purchase': $viewer = $browseTable->getTableView ("e10doc.purchase.libs.ViewItemsForPurchase", $comboParams); break;
			case 'cashreg':  $viewer = $browseTable->getTableView ("e10doc.cashRegister.libs.ViewItemsForCashRegister", $comboParams); break;
			case 'mnf': 		 $viewer = $browseTable->getTableView ("e10doc.mnf.ViewItemsForMnf", $comboParams); break;
		}

		$viewer->objectSubType = TableView::vsMini;
		$viewer->enableToolbar = FALSE;
		$viewer->comboSettings = array ('ABCDE' => 'WWWWW');

		$viewer->renderViewerData ("html");
		$c = $viewer->createViewerCode ("html", "fullCode");

		$sideBar = new FormSidebar ($this->app());
		$sideBar->addTab('t1', $browseTable->tableName());
		$sideBar->setTabContent('t1', $c);

		$this->sidebar = $sideBar->createHtmlCode();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.core.rows' && $srcColumnId === 'item')
		{
			$cp = array ('docType' => strval ($allRecData ['recData']['docType']),
									 'operation' => strval ($recData['operation']),
									 );
			if (is_string($allRecData ['recData']['dateAccounting']))
				$cp['dateAccounting'] = $allRecData ['recData']['dateAccounting'];
			else
				$cp['dateAccounting'] = $allRecData ['recData']['dateAccounting']->format('Y-m-d');
			$docType = $this->app()->cfgItem ('e10.docs.types.' . $allRecData ['recData']['docType']);
			if ($docType ['docDir'] != 0)
				$cp ['docDir'] = strval ($docType ['docDir']);

			$cp ['warehouse'] = strval ($allRecData ['recData']['warehouse']);

			return $cp;
		}

		if ($srcTableId === 'e10doc.core.rows' && $srcColumnId === 'symbol1')
		{
			$cp = [
				'docType' => strval ($allRecData ['recData']['docType']),
				'operation' => strval ($recData['operation']), 'item' => strval ($recData['item']), 'currency' => $allRecData ['recData']['currency'],
				'dateAccounting' => utils::createDateTime ($allRecData ['recData']['dateAccounting'])->format('Y-m-d')
			];

			return $cp;
		}

		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'exchangeRate')
		{
			$cp = [
				'docType' => strval ($recData['docType']),
				'srcCurrency' => $recData['homeCurrency'], 'dstCurrency' => $recData['currency'],
				'dateAccounting' => utils::createDateTime ($recData['dateAccounting'])->format('Y-m-d')
			];
			return $cp;
		}
		if ($srcTableId === 'e10doc.core.rows' && $srcColumnId === 'exchangeRate')
		{
			$cp = [
				'docType' => strval ($allRecData ['recData']['docType']),
				'srcCurrency' => $allRecData ['recData']['homeCurrency'], 'dstCurrency' => $allRecData ['recData']['currency'],
				'dateAccounting' => utils::createDateTime ($recData['dateDue'])->format('Y-m-d')
			];

			return $cp;
		}

		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'ownerOffice')
		{
			$cp = [
				'personNdx' => strval ($allRecData ['recData']['owner'])
			];

			return $cp;
		}

		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'otherAddress1')
		{
			$cp = [
				'personNdx' => strval ($allRecData ['recData']['person'])
			];

			return $cp;
		}

		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'personNomencCity')
		{
			$level = ($recData['docType'] === 'purchase' && $recData['personType'] == 2) ? 1 : 2;
			$cp = [
				'level' => strval ($level),
			];
			return $cp;
		}

		if ($srcTableId === 'e10doc.core.heads' && $srcColumnId === 'transportPersonDriver')
		{
			$cp = [
				'docType' => strval ($recData['docType']), 'transport' => $recData['transport'],
			];
			return $cp;
		}


		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}

	public function listParams ($srcTableId, $listId, $listGroup, $recData)
	{
		if ($srcTableId === 'e10doc.core.heads')
		{
			$cp = [
				'docType' => strval ($recData['docType']),
				'srcCurrency' => $recData['homeCurrency'], 'dstCurrency' => $recData['currency'],
				'dateAccounting' => utils::createDateTime ($recData['dateAccounting'])->format('Y-m-d'),
				'srcDocNdx' => $recData['ndx'],
			];
			return $cp;
		}

		return [];
	}

	protected function useDocKinds ()
	{
		$useDocKinds = 0;
		if (isset ($this->recData['dbCounter']) && $this->recData['dbCounter'] !== 0)
		{
			$dbCounter = $this->table->app()->cfgItem ('e10.docs.dbCounters.'.$this->recData['docType'].'.'.$this->recData['dbCounter'], FALSE);
			$useDocKinds = utils::param ($dbCounter, 'useDocKinds', 0);
		}

		return $useDocKinds;
	}

	protected function useMoreVATRegs()
	{
		if (!$this->vatRegs)
			$this->vatRegs = $this->app()->cfgItem ('e10doc.base.taxRegs', []);
		return (count($this->vatRegs) > 1);
	}

	public function validNewDocumentState ($newDocState, $saveData)
	{
		if ($saveData['recData']['docType'] !== 'cashreg' && $saveData['recData']['docType'] !== 'mnf' &&
				$saveData['recData']['docType'] !== 'bank' && $saveData['recData']['docType'] !== 'stockinst' && $newDocState !== 9800 && $newDocState !== 8000)
		{
			if ($saveData['recData']['person'] == 0)
			{
				$this->setColumnState('person', utils::es ('Hodnota'." '".$this->columnLabel($this->table->column ('person'), 0)."' ".'není vyplněna'));
				return FALSE;
			}
		}
		return parent::validNewDocumentState($newDocState, $saveData);
	}
} // class FormHeads


/**
 * Class ViewDetailHead
 * @package E10Doc\Core
 */
class ViewDetailHead extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.core.dc.Detail');
	}
}


/**
 * Detail Dokladu - Řádky
 *
 */

class ViewDetailDocRows extends ViewDetailHead
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10doc.core.rows', 'e10doc.core.ViewDocumentRows',
														 array ('document' => $this->item ['ndx']));
	}
} // class ViewDetailDocRows
