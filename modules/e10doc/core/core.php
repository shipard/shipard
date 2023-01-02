<?php

namespace E10Doc\Core;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/core/tables/heads.php';

use \E10\Application, \E10\utils, \E10\TableForm, \E10\TableView, E10\Utility, E10\Base\ListRows, \e10\uiutils, \e10\world;
use \e10doc\core\libs\Aggregate;
use \Shipard\Utils\Json;

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
		$this->spaydQRCodeURL = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/imgcache/spayd/'.$this->spaydQRCodeBaseFileName;

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
 * class ViewDetailItemsAnalysis
 */
class ViewDetailItemsAnalysis extends \E10\TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addDocumentCard('e10doc.core.libs.dc.DCWitemAnalysis');
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

		$this->docTypes = $this->app()->cfgItem ('e10.docs.types');

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

			// -- subColumns
			$delKeys = [];
			$subColumns = [];
			foreach ($row as $rk => $rv)
			{
				if (str_starts_with($rk, 'subColumns_'))
				{
					$delKeys[] = $rk;
					$rkParts = explode('_', $rk);
					$subColumns[$rkParts[1]][$rkParts[2]] = $rv;
				}
			}
			foreach ($subColumns as $skk => $skv)
			{
				Json::polish($skv);
				$row[$skk] = Json::lint($skv);
			}
			foreach ($delKeys as $dk)
				unset($row[$dk]);

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

