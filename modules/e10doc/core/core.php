<?php

namespace E10Doc\Core;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/tables/heads.php';

use \E10\Application, \E10\utils, \E10\TableForm, \E10\TableView, E10\Utility, E10\Base\ListRows, \e10\uiutils, \e10\world;
use \e10doc\core\libs\Aggregate;

class e10utils extends \e10doc\core\libs\E10Utils {}


/**
 * recalcOneDoc
 *
 * přepočet jednoho dokladů
 */


function recalcOneDoc ($app, $ndx)
{
	$tableDocs = $app->table ('e10doc.core.heads');
	$f = $tableDocs->getTableForm ('edit', $ndx);

	$tableRows = $app->table ('e10doc.core.rows');

	$q = "SELECT * FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY ndx";
	$rows = $app->db()->query ($q, $f->recData ['ndx']);
	forEach ($rows as $r)
	{
		$tableRows->dbUpdateRec ($r, $f->recData);
	}

	if ($f->checkAfterSave())
		$tableDocs->dbUpdateRec ($f->recData);
}


/**
 * recalcDocs
 *
 * přepočet všech dokladů
 */

function recalcDocs ($app, $options = NULL)
{
	$objectData ['message'] = 'Doklady jsou přepočítány.';
	$objectData ['finalAction'] = 'reloadPanel';

	$q = "SELECT * FROM [e10doc_core_heads] ORDER BY ndx";
	$rows = $app->db()->query ($q);
	forEach ($rows as $r)
	{
		recalcOneDoc ($app, $r ['ndx']);
	}

	$r = new \E10\Response ($app);
	$r->add ("objectType", "panelAction");
	$r->add ("object", $objectData);

	return $r;
}



/**
 * Class widgetAggregate
 * @package E10Doc\Core
 */
class WidgetAggregate extends \Shipard\UI\Core\WidgetPane
{
	var $fp = [];

	public function init ()
	{
		parent::init();
		e10utils::fiscalPeriods ($this->app, 'L-12-M', $this->fp);
	}
}


/**
 * Class AggregateDocRows
 * @package E10Doc\Core
 */
class AggregateDocRows extends Aggregate
{
	CONST groupItems = 1, groupAccGroups = 2, groupTypes = 3, groupBrands = 4, groupItemKinds = 5;

	var $enabledDocTypes = ['cashreg', 'invno'];
	var $enabledOperations = [1010001, 1010002];

	var $groupBy = self::groupItems;

	var $itemBrand = FALSE;
	var $itemType = FALSE;

	function create ()
	{
		switch ($this->groupBy)
		{
			case self::groupItems:
				$selColumns = 'items.fullName as groupName, item as groupNdx';
				break;
			case self::groupAccGroups:
				$selColumns = 'debsgroups.fullName as groupName, debsgroups.ndx as groupNdx';
				break;
			case self::groupTypes:
				$selColumns = 'itemtypes.fullName as groupName, itemtypes.ndx as groupNdx';
				break;
			case self::groupBrands:
				$selColumns = 'itembrands.fullName as groupName, itembrands.ndx as groupNdx';
				break;
			case self::groupItemKinds:
				$selColumns = "CASE items.itemKind WHEN 0 THEN 'Služby' WHEN 1 THEN 'Zásoby' WHEN 2 THEN 'Účetní položky' WHEN 3 THEN 'Ostatní' END as groupName, items.itemKind as groupNdx";
				break;
		}

		$q[] = 'SELECT ';

		array_push($q, $selColumns);
		array_push($q, ' , SUM([rows].taxBaseHc) as taxBase,');

		switch ($this->period)
		{
			case self::periodDaily:
				array_push($q, ' heads.dateAccounting as dateAccounting '); break;
			case self::periodMonthly:
				array_push($q, ' YEAR(heads.dateAccounting) as dateAccountingYear, MONTH(heads.dateAccounting) as dateAccountingMonth '); break;
		}

		array_push($q, ' FROM e10doc_core_rows as [rows] LEFT JOIN e10doc_core_heads AS heads ON [rows].document = heads.ndx');

		array_push($q, ' LEFT JOIN e10_witems_items AS items ON [rows].item = items.ndx');
		array_push($q, ' LEFT JOIN e10doc_debs_groups AS debsgroups ON items.debsGroup = debsgroups.ndx');
		array_push($q, ' LEFT JOIN e10_witems_itemtypes AS itemtypes ON items.itemType = itemtypes.ndx');
		array_push($q, ' LEFT JOIN e10_witems_brands AS itembrands ON items.brand = itembrands.ndx');

		array_push($q, ' WHERE heads.docState = 4000');
		array_push($q, ' AND docType IN %in', $this->enabledDocTypes);
		array_push($q, ' AND operation IN %in', $this->enabledOperations);

		if ($this->itemBrand !== FALSE)
			array_push($q, ' AND items.brand = %i', $this->itemBrand);
		if ($this->itemType !== FALSE)
			array_push($q, ' AND items.itemType = %i', $this->itemType);

		e10utils::fiscalPeriodQuery ($q, $this->fiscalPeriod);

		switch ($this->period)
		{
			case self::periodDaily:
				array_push($q, ' GROUP BY heads.dateAccounting, [rows].item'); break;
			case self::periodMonthly:
				array_push($q, ' GROUP BY dateAccountingYear, dateAccountingMonth, [rows].item'); break;
		}

		$rows = $this->app->db()->query($q);

		$data = [];
		$total = ['date' => 'CELKEM', 'totalBase' => 0.0];
		$groupNames = [];

		forEach ($rows as $r)
		{
			$groupNdx = 'G'.$r['groupNdx'];

			switch ($this->period)
			{
				case self::periodDaily:
					$dateKey = $r['dateAccounting']->format ('Y-m-d');
					$date = utils::datef ($r['dateAccounting'], '%n %d');
					break;
				case self::periodMonthly:
					$dateKey = $r['dateAccountingYear'].'-'.$r['dateAccountingMonth'];
					$date = $r['dateAccountingMonth'].'.'.$r['dateAccountingYear'];
					break;
			}

			if (!isset ($data [$dateKey]))
				$data [$dateKey] = ['date' => $date, 'totalBase' => 0.0];
			if (!isset ($data [$dateKey][$groupNdx]))
				$data [$dateKey][$groupNdx] = 0.0;

			$data [$dateKey][$groupNdx] += $r['taxBase'];
			$data [$dateKey]['totalBase'] += round($r['taxBase'], 2);

			if (!isset($total[$groupNdx]))
			{
				$total[$groupNdx] = 0.0;
				if ($r['groupName'])
					$groupNames[$groupNdx] = $r['groupName'];
				else
					$groupNames[$groupNdx] = 'NEUVEDENO';
			}

			$total[$groupNdx] += $r['taxBase'];
			$total['totalBase'] += round ($r['taxBase'], 2);
		}

		$h = ['date' => ' '.$this->periodColumnName, 'totalBase' => '+CELKEM'];
		$groupOrder = array_merge([], $total);
		unset ($groupOrder['date']);
		unset ($groupOrder['totalBase']);
		arsort($groupOrder, SORT_NUMERIC);

		foreach ($groupOrder as $opk => $opv)
		{
			$h[$opk] = '+'.$groupNames[$opk];
		}

		$maxCols = $this->maxResultParts;
		$totalsCuted = [];
		utils::cutColumns ($data, $this->data, $h, $this->header, $this->graphLegend, $totalsCuted, 2, $maxCols);

		$this->graphBar = ['type' => 'graph', 'graphType' => 'bar', 'XKey' => 'date', 'stacked' => 1, 'header' => $this->header,
											 'disabledCols' => ['totalBase'], 'graphData' => $this->data];
		$this->graphLine = ['type' => 'graph', 'graphType' => 'spline', 'XKey' => 'date', 'header' => $this->header,
												'graphData' => $this->data];

		foreach ($this->graphLegend as $legendId => $legendTitle)
			$this->pieData[] = [utils::tableHeaderColName($legendTitle), $totalsCuted[$legendId]];
		$this->graphDonut = ['type' => 'graph', 'graphType' => 'pie', 'graphData' => $this->pieData];
	}
}


/**
 * Class CreateDocumentUtility
 * @package E10Doc\Core
 */
class CreateDocumentUtility extends \E10\Utility
{
	public $docHead = array ();
	public $docRows = array ();
	protected $tableDocs;
	protected $tableRows;

	CONST sdsConcept = 0, sdsConfirmed = 1, sdsDone = 2;

	public function __construct ($app)
	{
		parent::__construct ($app);
		$this->tableDocs = new \E10Doc\Core\TableHeads ($this->app);
		$this->tableRows = new \E10Doc\Core\TableRows ($this->app);
	}

	public function createDocumentHead ($docType)
	{
		$this->docHead = array ('docType' => $docType);
		$this->tableDocs->checkNewRec($this->docHead);

		$this->docHead ['docType'] = $docType;

		// -- dbCounter: TODO: settings in sales options
		//$dbCounters = $this->app->cfgItem ('e10.docs.dbCounters.invno', FALSE);
		//$this->invHead ['dbCounter'] = key($dbCounters);

		$this->docRows = array ();
	}

	public function createDocumentRow ($row)
	{
		$r = array ('quantity' => 1);
		$this->tableRows->checkNewRec($r);

		return $r;
	}

	public function addDocumentRow ($row)
	{
		$this->docRows[] = $row;
	}

	function saveDocument ($saveDocState = self::sdsConcept)
	{
		$docNdx = $this->tableDocs->dbInsertRec ($this->docHead);
		$this->docHead['ndx'] = $docNdx;

		$f = $this->tableDocs->getTableForm ('edit', $docNdx);

		forEach ($this->docRows as $r)
		{
			$r['document'] = $docNdx;
			$this->tableRows->dbInsertRec ($r, $f->recData);
		}

		if ($f->checkAfterSave())
		{
			if ($saveDocState == self::sdsConcept)
			{
				$f->recData['docStateMain'] = 0;
				$f->recData['docState'] = 1000;
			}
			elseif ($saveDocState == self::sdsConfirmed)
			{
				$f->recData['docStateMain'] = 1;
				$f->recData['docState'] = 1200;
			}
			elseif ($saveDocState == self::sdsDone)
			{
				$f->recData['docStateMain'] = 2;
				$f->recData['docState'] = 4000;
			}

			$this->tableDocs->checkDocumentState ($f->recData);
			$this->tableDocs->dbUpdateRec ($f->recData);
			$this->tableDocs->checkAfterSave2 ($f->recData);
			$this->tableDocs->docsLog ($f->recData['ndx']);

			$docStates = $this->tableDocs->documentStates ($f->recData);
			$ds = $docStates['states'][$f->recData['docState']];

			$printCfg = [];
			$this->tableDocs->printAfterConfirm($printCfg, $f->recData, $ds);
		}
	}
} // CreateDocumentUtility



/**
 * CreateDocumentWizard
 *
 */

class CreateDocumentWizard extends \E10\Wizard
{
	protected $rows = array();
	protected $docActionInfo = array();

	public function doStep ()
	{
		if ($this->pageNumber === 0)
		{
			$this->recData['postData'] = json_encode($this->postData);
			$this->parseDocData ($this->postData);
		}
		if ($this->pageNumber === 1)
		{
			$this->saveDocument();
			$this->stepResult['lastStep'] = 1;
		}
		if ($this->pageNumber === 2)
		{
			$this->stepResult ['close'] = 1;
			$this->stepResult['lastStep'] = 1;
		}
	}

	public function renderForm ()
	{
		switch ($this->pageNumber)
		{
			case 0: $this->renderFormWelcome (); break;
			case 1: $this->renderFormResult (); break;
			case 2: $this->renderFormDone (); break;
		}
	}

	public function renderFormWelcome ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltNone);

		$c = '';
		$c .= "<div class='docRecapitulation' style='padding: 2em; min-width: 40em;'>";
		$c .= "<h1 style='padding-bottom: .6em; text-align: right;'>" . utils::es ($this->welcomeHeader ()) . '</h1>';

		$h = array ('#' => '#', 'symbol1' => 'Výkup', 'date' => 'datum', 'price' => ' Cena/jed');
		$c .= \E10\renderTableFromArray ($this->rows, $h);
		$c .= '</div>';

		$this->appendCode($c);

		$this->addInput('postData', 'ABC', self::INPUT_STYLE_STRING, TableForm::coHidden, 8000);

		$this->closeForm ();
	}

	public function renderFormResult ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm (TableForm::ltNone);
			$this->appendCode("HOTOVO.");
			$this->addInput('postData', 'ABC', self::INPUT_STYLE_STRING, TableForm::coHidden, 8000);
		$this->closeForm ();
	}

	protected function parseDocData ($docData)
	{
		$rows = array ();
		forEach ($docData as $ddId => $ddValue)
		{
			$parts = explode ('.', $ddId);
			if ($parts[0] !== 'docActionData')
				continue;
			if (isset ($parts[1]) && $parts[1] === 'rows')
			{
				$rows [$parts[2]][$parts[3]] = $ddValue;
				continue;
			}

			$this->docActionInfo[$parts[1]] = $ddValue;
		}

		forEach ($rows as $r)
		{
			if (!isset ($r['enabled']))
				continue;
			$this->rows[] = $r;
		}
	}

	protected function saveDocument ()
	{
		$this->postData = json_decode ($this->recData['postData'], TRUE);
		$this->parseDocData ($this->postData);
	}

	protected function welcomeHeader ()
	{
		return '';
	}

} // CreateDocumentWizard


/**
 * ShortPaymentDescriptor
 *
 */

class ShortPaymentDescriptor extends \E10\Utility
{
	public $dstParams = array();
	public $spaydString = '';
	public $spaydSha1 = '';
	public $spaydQRCodeBaseFileName = '';
	public $spaydQRCodeFullFileName = '';
	public $spaydQRCodeURL = '';

	public function createString ()
	{
		$this->spaydString = 'SPD*1.0';
		forEach ($this->dstParams as $paramId => $paramValue)
		{
			if (strpos($paramValue, '*') !== FALSE)
			{
				$this->spaydString = '';
				return;
			}
			$this->spaydString .= '*'.$paramId.':'.$paramValue;
		}

		$this->spaydSha1 = sha1($this->spaydString);
		$this->spaydQRCodeBaseFileName = 'spayd-qrcode-'.$this->spaydSha1.'.svg';
	}

	public function createQRCode ()
	{
		$dirName = __APP_DIR__.'/imgcache/spayd/';
		$this->spaydQRCodeFullFileName = $dirName . $this->spaydQRCodeBaseFileName;
		$this->spaydQRCodeURL = 'https://'.$this->app()->cfgItem('hosting.serverDomain').'/'.$this->app->cfgItem('dsid').'/imgcache/spayd/'.$this->spaydQRCodeBaseFileName;

		if (is_file($this->spaydQRCodeFullFileName))
			return;

		if (!is_dir($dirName))
			utils::mkDir($dirName);

		$cmd = "qrencode -lM -t SVG -o \"{$this->spaydQRCodeFullFileName}\" \"{$this->spaydString}\"";
		exec ($cmd);
	}

	static function makeIban ($localBankAccountNumber, $country)
	{
		$iban = new \lib\IBANGenerator ($localBankAccountNumber, $country);
		return $iban->iban;
	}

	public function setAmount ($amount, $currency)
	{
		$this->dstParams['AM'] = sprintf ('%.2f', $amount);
		$this->dstParams['CC'] = strtoupper($currency); // ISO 4217
	}

	public function setBankAccount ($country, $localBankAccountNumber, $iban = '', $swift = '')
	{
		$accIban = str_replace(' ', '', $iban);
		if ($accIban === '')
			$accIban = $this->makeIban ($localBankAccountNumber, $country);
		$this->dstParams['ACC'] = $accIban;
	}

	public function setPaymentSymbols ($s1, $s2 = '', $s3 = '')
	{
		$this->dstParams['X-VS'] = $s1;
		if ($s2 !== '')
			$this->dstParams['X-SS'] = $s2;
		if ($s3 !== '')
			$this->dstParams['X-KS'] = $s3;

		$this->dstParams['RF'] = $s1;
		if ($s2 !== '')
			$this->dstParams['RF'] .= $s2;
		if (strlen ($this->dstParams['RF']) > 16)
			$this->dstParams['RF'] = substr($this->dstParams['RF'], 0, 15);
	}
} // ShortPaymentDescriptor



/**
 * Detail Položky - Pohyby
 *
 */

class ViewDetailItemsJournal extends \E10\TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10doc.core.heads', 'e10doc.core.ViewItemsJournal',
			array ('item' => $this->item ['ndx']));
	}
}


/**
 * Prohlížeč pohybů položky
 *
 */

class ViewItemsJournal extends \E10\TableViewGrid
{
	var $docTypes;
	var $inventory = FALSE;

	public function init ()
	{
		if ($this->table->app()->model()->table ('e10doc.inventory.journal') !== FALSE)
			$this->inventory = TRUE;

		$this->objectSubType = TableView::vsDetail;
		if ($this->queryParam ('item'))
			$this->addAddParam ('item', $this->queryParam ('item'));

		$this->docTypes = Application::cfgItem ('e10.docs.types');

		parent::init();

		$g = array ('#' => '#',
			'dateAccounting' => ' Datum',
			'docTypeName' => 'DD',
			'docNumberLink' => '_Doklad',
			'personName' => 'Partner',
			'quantity' => ' Množství',
		);

		$g['salePriceItem'] = ' Cena/jed';
		if ($this->inventory)
		{
			$g['stockPriceItem'] = ' Skl.cena/jed';
		}

		$this->setGrid ($g);
	}

	public function renderRow ($item)
	{
		//$listItem = $item;
		$listItem ['pk'] = $item ['ndxHead'];
		$listItem ['dateAccounting'] = $item ['dateAccounting'];
		$listItem ['personName'] = $item ['personName'];
		$listItem ['quantity'] = $item ['quantity'];

		$docType = $this->docTypes [$item['docType']];
		$listItem ['docTypeName'] = $docType ['shortcut'];
		$listItem ['docNumberLink'] = array ('text'=> $item ['docNumber'], 'docAction' => 'edit', 'icon' => $docType ['icon'],
			'table' => 'e10doc.core.heads', 'pk'=> $item ['ndxHead']);

		$listItem ['salePriceItem'] = utils::nf ($item ['taxBase'] / $item ['quantity'], 2);

		if ($this->inventory)
		{
			$listItem ['stockPriceItem'] = utils::nf ($item ['invPriceItem'], 2);
		}

		return $listItem;
	}

	public function selectRows ()
	{
		if ($this->inventory)
			$q = "SELECT [rows].ndx as ndx, [rows].item as item, [rows].quantity as quantity, [rows].taxBase as taxBase,
						[rows].priceItem as priceItem, persons.fullName as personName,
						heads.docNumber as docNumber, heads.dateAccounting as dateAccounting, heads.docType as docType,
						heads.ndx as ndxHead, journal.price as invPriceAll, (journal.price / [rows].quantity) as invPriceItem
						FROM e10doc_core_rows as [rows]
						LEFT JOIN e10doc_core_heads as heads ON (heads.ndx = [rows].document)
						LEFT JOIN e10doc_inventory_journal as journal ON (journal.docHead = [rows].document AND journal.docRow = [rows].ndx)
						LEFT JOIN e10_persons_persons AS persons ON (heads.person = persons.ndx)
						where [rows].item = %i AND heads.docState = 4000 ORDER BY heads.dateAccounting DESC, [rows].ndx DESC" . $this->sqlLimit();
		else
			$q = "SELECT [rows].ndx as ndx, [rows].item as item, [rows].quantity as quantity, [rows].taxBase as taxBase,
						[rows].priceItem as priceItem, persons.fullName as personName,
						heads.docNumber as docNumber, heads.dateAccounting as dateAccounting, heads.docType as docType,
						heads.ndx as ndxHead
						FROM e10doc_core_rows as [rows]
						LEFT JOIN e10doc_core_heads as heads ON (heads.ndx = [rows].document)
						LEFT JOIN e10_persons_persons AS persons ON (heads.person = persons.ndx)
						where [rows].item = %i AND heads.docState = 4000 ORDER BY heads.dateAccounting DESC, [rows].ndx DESC" . $this->sqlLimit();

		$this->runQuery ($q, $this->queryParam ('item'));
	}

	public function createToolbar ()
	{
		return array();
	}
} // class ViewItemsJournal


/**
 * MergeItems
 *
 */

class MergeItems extends Utility
{
	var $mergeTargetNdx = 0;
	var $mergedNdxs = array();
	var $deleteMergedItems = 0;
	var $tableItems;

	public function setMergeParams ($mergeTargetNdx, $mergedNdxs, $deleteMergedItems)
	{
		$this->mergeTargetNdx = $mergeTargetNdx;
		$this->mergedNdxs = $mergedNdxs;
		$this->deleteMergedItems = $deleteMergedItems;

		$this->tableItems = $this->app->table ('e10.witems.items');
	}

	public function merge ()
	{
		$this->doit ();
	}

	protected function doit ()
	{
		$this->db()->query ('UPDATE [e10doc_core_rows] SET [item] = %i WHERE [item] IN %in', $this->mergeTargetNdx, $this->mergedNdxs);

		if ($this->deleteMergedItems)
			$this->deleteMergedItems ();
	}

	protected function deleteMergedItems ()
	{
		forEach ($this->mergedNdxs as $deletedNdx)
		{
			$this->db()->query ('UPDATE [e10_witems_items] SET [docState] = 9800, [docStateMain] = 4 WHERE [ndx] = %i', $deletedNdx);
			$this->tableItems->docsLog ($deletedNdx);
		}
	}
}


/**
 * @param $app
 * @param $params
 */
function closeVATPeriod ($app, $params)
{
	$vatPeriods = $app->cfgItem('e10doc.vatPeriods');
	$vp = utils::searchArray($vatPeriods, 'id', $params ['vatPeriod']);
	if ($vp !== NULL)
	{
		$app->db()->query ('UPDATE e10doc_base_taxperiods SET docState = 9000, docStateMain = 5 WHERE ndx = %i', $vp['ndx']);
	}
}


/**
 * @param $app
 * @param $params
 */
function createFiscalYear ($app, $params)
{
	if (!isset($params['year']))
		return;

	$year = $params['year'];
	$tableFiscalYears = $app->table ('e10doc.base.fiscalyears');
	$tableFiscalYears->createYear ($year);
}


/**
 * @param $app
 * @param $params
 */
function createVATYear ($app, $params)
{ // TODO: delete
	if (!isset($params['year']))
		return;

	$year = $params['year'];
	$tableTaxPeriods = $app->table ('e10doc.base.taxperiods');
	$tableTaxPeriods->createPeriod ($year);
}


/**
 * Class DocListRows
 * @package E10Doc\Core
 */
class DocListRows extends ListRows
{

	function saveData ($listData)
	{
		//error_log ('##SAVE DATA (' . $this->formData->recData ['ndx'] . ')' . json_encode ($listData));
		$usedNdx = [];
		$lastRowOrder = 0;

		forEach ($listData as &$row)
		{
			if ($this->rowsTableOrderCol && (!isset($row[$this->rowsTableOrderCol]) || !$row[$this->rowsTableOrderCol]))
				$row[$this->rowsTableOrderCol] = $lastRowOrder + 100;

			$row [$this->rowsTableQueryCol] = $this->recData ['ndx'];
			if (!isset ($row ['ndx']) || $row ['ndx'] == 0 || $row ['ndx'] == '')
			{ // insert
				unset ($row['ndx']);
				//	error_log ('##' . json_encode ($row));
				$newNdx = $this->rowsTable->dbInsertRec ($row, $this->recData);
				$usedNdx [] = $newNdx;
			}
			else
			{ // update
				$this->rowsTable->dbUpdateRec ($row, $this->recData);
				$usedNdx [] = $row ['ndx'];
			}

			if ($this->rowsTableOrderCol)
				$lastRowOrder = $row[$this->rowsTableOrderCol];
		}

		// -- clear deleted rows
		if (count($usedNdx))
		{
			$qd [] = "DELETE FROM [{$this->rowsTable->sqlName ()}] WHERE";
			array_push ($qd, " [{$this->rowsTableQueryCol}] = %i", $this->recData ['ndx']);
			array_push ($qd, ' AND (');
			array_push ($qd, ' (rowType = 0 AND [ndx] NOT IN %in)', $usedNdx);
			array_push ($qd, ' OR');
			array_push ($qd, ' (rowType != 0 AND [ownerRow] NOT IN %in)', $usedNdx);
			array_push ($qd, ')');

			$this->rowsTable->app()->db()->query($qd);
		}
		else // no lines; delete all
		{
			$this->rowsTable->app()->db()->query("DELETE FROM [{$this->rowsTable->sqlName ()}] where [{$this->rowsTableQueryCol}] = %i",
					$this->recData ['ndx']);
		}
	}

	function loadDataQry (&$q)
	{
		array_push($q, ' AND rowType = 0');
	}
}

