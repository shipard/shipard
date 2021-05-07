<?php

namespace e10doc\core\libs;

require_once __APP_DIR__ . '/e10-modules/e10doc/debs/debs.php';

use \e10\TableForm, \e10\utils, \e10\Utility;


/**
 * Class RecalcDocuments
 * @package e10doc\core\libs
 *
 * sudo e10-app cli-action --action=e10doc.core/recalc-docs --date-from=2019-01-01 --date-to=2019-12-31 --doc-types=purchase --mode=successors
 */
class RecalcDocuments extends Utility
{
	var $docTypes = NULL;
	var $dateFrom = NULL;
	var $dateTo = NULL;
	var $mode = '';

	var $witems = [];

	var $tableHeads;
	var $tableRows;

	function init()
	{
		$this->tableHeads = $this->app->table ('e10doc.core.heads');
		$this->tableRows = $this->app->table ('e10doc.core.rows');
	}

	function setDateFrom($dateFrom)
	{
		$this->dateFrom = utils::createDateTime($dateFrom);
	}

	function setDateTo($dateTo)
	{
		$this->dateTo = utils::createDateTime($dateTo);
	}

	function setDocTypes($docTypes)
	{
		$this->docTypes = explode(',', $docTypes);
	}

	function recalcOne($ndx, $docTypeId)
	{
		$docType = $this->app()->cfgItem ('e10.docs.types.' . $docTypeId, NULL);

		$f = $this->tableHeads->getTableForm ('edit', $ndx);

		$rowsWithSets = [];
		$this->db()->query('DELETE FROM [e10doc_core_rows] WHERE [document] = %i', $ndx, ' AND [rowType] = %i', 1);

		$q = "SELECT * FROM [e10doc_core_rows] WHERE [document] = %i ORDER BY ndx";
		$rows = $this->db()->query ($q, $f->recData ['ndx']);
		forEach ($rows as $row)
		{
			$r = $row->toArray();

			$item = $this->witem($r['item']);

			if ($r['itemType'] != $item['itemType'])
			{
				//echo " - wrong item type #{$r['item']} - {$item['fullName']} \n";

				$itemType = $this->app()->cfgItem ('e10.witems.types.' . $r['itemType'], NULL);
				if ($itemType === NULL)
				{
					echo ("* ERROR: Unknown item type {$r['itemType']}\n");
				}
				if (!isset($itemType['kind']))
				{
					echo ("* ERROR: Bad item type {$r['itemType']}\n");
				}

				$r['itemType'] = $item['type'];
				$r['invDirection'] = $docType['invDirection'];
				if ($itemType['kind'] == 1)
				{
					if ($docType && isset($docType['invDirection']))
						$r ['invDirection'] = $docType['invDirection'];
				}
				$updateRow['isSet'] = 0;
				if ($item['isSet'] )
				{
					$updateRow['isSet'] = 1;
					$rowsWithSets[] = $r['ndx'];
				}
			}

			$this->tableRows->dbUpdateRec ($r, $f->recData);
		}

		$this->reAccountingDocument($f->recData);

		if (count($rowsWithSets))
		{
			//echo "    –> SR: ".json_encode($rowsWithSets)."\n";
		}

		if ($f->checkAfterSave())
			$this->tableHeads->dbUpdateRec ($f->recData);
	}

	function witem($itemNdx)
	{
		if (isset($this->witems[$itemNdx]))
			return $this->witems[$itemNdx];

		$item = $this->db()->query('SELECT * FROM [e10_witems_items] WHERE [ndx] = %i', $itemNdx)->fetch();
		if ($item)
		{
			$this->witems[$itemNdx] = $item->toArray();
			return $this->witems[$itemNdx];
		}

		return NULL;
	}

	function reAccountingDocument($recData)
	{
		$this->db()->query ('DELETE FROM [e10doc_debs_journal] WHERE [document] = %i', $recData['ndx']);

		$docAccEngine = new \E10Doc\Debs\docAccounting ($this->app());
		$docAccEngine->setDocument ($recData);
		$docAccEngine->run();
		$docAccEngine->save();

		if ($docAccEngine->messagess() !== FALSE)
		{
			$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = 9 WHERE ndx = %i", $recData['ndx']);
		}
		else
			$this->db()->query ("UPDATE [e10doc_core_heads] SET docStateAcc = 1 WHERE ndx = %i", $recData['ndx']);
	}

	function recalcAll()
	{
		$q[] = 'SELECT ndx, docNumber, docType FROM [e10doc_core_heads] AS [heads]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [docType] IN %in', $this->docTypes);
		array_push($q, ' AND [docState] = %i', 4000);
		array_push($q, ' AND [dateAccounting] >= %d', $this->dateFrom, ' AND [dateAccounting] <= %d', $this->dateTo);
		array_push($q, ' ORDER BY dateAccounting, activateTimeLast, ndx');

		$rows = $this->db()->query ($q);

		$this->db()->begin();
		foreach ($rows as $r)
		{
			echo ("* {$r['docNumber']} ({$r['docType']})");
			$this->recalcOne($r['ndx'], $r['docType']);
			echo ("\n");
		}

		$this->db()->commit();
	}

	public function replaceSuccessorsItems()
	{
		$q[] = 'SELECT [rows].*, ';
		array_push($q, ' [heads].docNumber AS headDocNumber, [heads].[title] AS headTitle, [heads].docType,');
		array_push($q, ' [items].[id] AS itemId, [items].fullName AS itemFullName,');
		array_push($q, ' successors.id AS successorId, successors.fullName AS successorFullName, [items].successorItem AS successorItemNdx');
		array_push($q, ' FROM [e10doc_core_rows] AS [rows]');
		array_push($q, ' LEFT JOIN [e10doc_core_heads] AS [heads] ON [rows.document] = [heads].[ndx]');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [rows.item] = [items].[ndx]');
		array_push($q, ' LEFT JOIN [e10_witems_items] AS [successors] ON [items.successorItem] = [successors].[ndx]');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [heads].[dateAccounting] >= %d', $this->dateFrom, ' AND [heads].[dateAccounting] <= %d', $this->dateTo);
		array_push($q, ' AND (items.successorItem IS NOT NULL AND items.successorItem != %i', 0,
				' AND items.successorDate <= [heads].[dateAccounting]', ')');
		array_push($q, ' ORDER BY [heads].[dateAccounting], [rows].[document], [rows].ndx');

		$cntRowsAll = 0;
		$lastDocNdx = -1;

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			if ($lastDocNdx !== $r['document'])
			{
				if ($lastDocNdx !== -1)
				{
					$this->recalcOne($lastDocNdx, $r['docType']);

					//if ($cntRowsAll > 1000)
					//	break;
				}
				echo $r['headDocNumber'].': '.$r['headTitle']."\n";
			}

			//echo ' - '.$r['itemId'].'/'.$r['itemFullName'].' -> '.$r['successorId'].'/'.$r['successorFullName']."\n";
			$updateRow = ['item' => $r['successorItemNdx']];

			$oldItem = $this->witem($r['item']);
			$newItem = $this->witem($r['successorItemNdx']);

			if ($oldItem['itemKind'] == 1 && $newItem['itemKind'] == 3)
			{
				if ($r['operation'] == 1010002) // prodej zásob
					$updateRow['operation'] = 1010099; // prodej zásob bez evidence
			}

			$this->db()->query('UPDATE [e10doc_core_rows] SET ', $updateRow, ' WHERE [ndx] = %i', $r['ndx']);

			$lastDocNdx = $r['document'];
			$cntRowsAll++;
		}

		if ($lastDocNdx !== -1)
		{
			$this->recalcOne($lastDocNdx, $r['docType']);
		}
	}

	public function run()
	{
		$this->init();
		if ($this->mode === 'all')
			$this->recalcAll();
		elseif ($this->mode === 'successors')
			$this->replaceSuccessorsItems();
	}
}
