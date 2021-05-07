<?php

namespace E10Pro\Property;

use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableDepreciation
 * @package E10Pro\Property
 */

class TableDepreciation extends DbTable
{
	CONST pdtIn = 1, pdtEnhancement = 2, pdtReduction = 4, pdtDepreciation = 99, pdtDecommission = 120;
	static $rowTypesName = [1 => "Zařazení", 2 => "Zhodnocení", 4 => "Snížení hod.", 99 => "Odpis", 120 => "Vyřazení"];
	static $rowsIcons = ['1' => 'icon-dot-circle-o', '2' => 'icon-arrow-circle-up', '4' => 'icon-arrow-circle-down', '99' => 'icon-level-down', '120' => 'icon-times-circle'];

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10pro.property.depreciation", "e10pro_property_depreciation", "Odpisy majetku");
	}

	public function calcRow (&$recData, $prevRow)
	{
		if ($recData['rowType'] == TableDepreciation::pdtIn)
		{
			$recData['accInitState'] = 0.0;
			$recData['accDepreciation'] = 0.0;
			$recData['accCorrection'] = 0.0;
			$recData['accUsedDepreciation'] = 0.0;
			$recData['accBalance'] = $recData['amount'];

			$recData['taxInitState'] = 0.0;
			$recData['taxDepreciation'] = 0.0;
			$recData['taxCorrection'] = 0.0;
			$recData['taxUsedDepreciation'] = 0.0;
			$recData['taxBalance'] = $recData['amount'];

			return;
		}

		if ($prevRow === FALSE)
		{
			$recData['accInitState'] = 0.0;
			$recData['taxInitState'] = 0.0;
		}
		else
		{
			$recData['accInitState'] = $prevRow['accBalance'];
			$recData['taxInitState'] = $prevRow['taxBalance'];
		}

		if ($recData['rowType'] == TableDepreciation::pdtEnhancement)
		{
			$recData['accBalance'] = $recData['accInitState'] + $recData['amount'];
			$recData['taxBalance'] = $recData['taxInitState'] + $recData['amount'];

			return;
		}

		if ($recData['rowType'] == TableDepreciation::pdtDepreciation)
		{
			$recData['accUsedDepreciation'] = $recData['accDepreciation'] + $recData['accCorrection'];
			$recData['accBalance'] = $recData['accInitState'] - $recData['accUsedDepreciation'];

			$recData['taxUsedDepreciation'] = $recData['taxDepreciation'] + $recData['taxCorrection'];
			$recData['taxBalance'] = $recData['taxInitState'] - $recData['taxUsedDepreciation'];

			return;
		}

		if ($recData['rowType'] == TableDepreciation::pdtReduction)
		{
			$recData['accBalance'] = $recData['accInitState'] - $recData['amount'];
			$recData['taxBalance'] = $recData['taxInitState'] - $recData['amount'];

			return;
		}

		if ($prevRow !== FALSE && $recData['rowType'] == TableDepreciation::pdtDecommission)
		{
			$recData['accInitState'] = $prevRow['accBalance'];
			$recData['accDepreciation'] = $prevRow['accBalance'];
			$recData['accCorrection'] = 0.0;
			$recData['accUsedDepreciation'] = $prevRow['accBalance'];
			$recData['accBalance'] = 0.0;

			$recData['taxInitState'] = $prevRow['taxBalance'];
			$recData['taxDepreciation'] = $prevRow['taxBalance'];
			$recData['taxCorrection'] = 0.0;
			$recData['taxUsedDepreciation'] = $prevRow['taxBalance'];
			$recData['taxBalance'] = 0.0;

			return;
		}
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$prevRow = FALSE;

		if ($recData['rowType'] != TableDepreciation::pdtIn)
		{
			$q = 'SELECT * FROM e10pro_property_depreciation WHERE property = %i AND ((dateAccounting = %d AND ndx < %i) OR (dateAccounting < %d AND ndx != %i)) ORDER BY dateAccounting DESC, rowType DESC, ndx DESC LIMIT 0, 1';
			$prevRowRec = $this->db()->query ($q, $recData['property'], $recData['dateAccounting'], $recData['ndx'], $recData['dateAccounting'], $recData['ndx'])->fetch();
			if (isset ($prevRowRec['ndx']))
				$prevRow = $prevRowRec->toArray();
		}

		$this->calcRow($recData, $prevRow);

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function tableIcon ($recData, $options = NULL)
	{
		return self::$rowsIcons[$recData['rowType']];
	}
}


/**
 * Class ViewDepreciation
 * @package E10Pro\Property
 */

class ViewDepreciation extends \E10\TableViewGrid
{
	public function init ()
	{
		parent::init();
		$this->gridEditable = TRUE;
		$this->classes = ['editableGrid'];
		$this->enableDetailSearch = TRUE;

		if ($this->queryParam ('property'))
			$this->addAddParam ('property', $this->queryParam ('property'));

		$mq [] = array ('id' => 'active', 'title' => 'Aktivní');
		$mq [] = array ('id' => 'done', 'title' => 'Odepsáno');
		$mq [] = array ('id' => 'all', 'title' => 'Vše');
		$mq [] = array ('id' => 'trash', 'title' => 'Koš');
		$this->setMainQueries ($mq);

		$g = [
			'dateAccounting' => 'Datum',
			'rowType' => 'Typ',
			'accChange' => ' Pohyb Ú',
			'accBalance' => ' Zůstatek Ú',
			'taxChange' => ' Pohyb D',
			'taxBalance' => ' Zůstatek D'
		];

		$this->setGrid ($g);

		$this->setInfo('title', 'Odpisy majetku');
		$this->setInfo('icon', 'icon-sort-amount-desc');
	}

	public function selectRows ()
	{
		$mainQuery = $this->mainQueryId ();

		$q [] = 'SELECT * FROM [e10pro_property_depreciation] WHERE 1';

		array_push ($q, " AND property = %i", $this->queryParam ('property'));

		// -- active
		if ($mainQuery === 'active' || $mainQuery == '')
			array_push ($q, " AND [docStateMain] < 4");

		if ($mainQuery === 'done')
			array_push ($q, " AND [docStateMain] = 2");
		if ($mainQuery === 'trash')
			array_push ($q, " AND [docStateMain] = 4");

		if ($mainQuery === 'all')
			array_push ($q, ' ORDER BY [dateAccounting] DESC, [rowType] DESC, [ndx] DESC ' . $this->sqlLimit ());
		else
			array_push ($q, ' ORDER BY [docStateMain], [dateAccounting], [rowType] DESC, [ndx] DESC ' . $this->sqlLimit ());

		$this->runQuery ($q);
	} // selectRows


	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$listItem ['dateAccounting'] = $item['dateAccounting'];

		$listItem ['accBalance'] = $item['accBalance'];
		$listItem ['taxBalance'] = $item['taxBalance'];

		$listItem ['rowType'] = ['text' => TableDepreciation::$rowTypesName[$item ['rowType']]];

		if ($item['rowType'] == TableDepreciation::pdtDepreciation)
		{
			$listItem ['accChange'] = $item['accUsedDepreciation'];
			$listItem ['taxChange'] = $item['taxUsedDepreciation'];
			$listItem ['rowType']['icon'] = 'icon-minus-square';
		}
		else
		if ($item['rowType'] == TableDepreciation::pdtIn || $item['rowType'] == TableDepreciation::pdtEnhancement)
		{
			$listItem ['accChange'] = $item['amount'];
			$listItem ['taxChange'] = $item['amount'];
			$listItem ['rowType']['icon'] = 'icon-plus-square';
		}
		else
		if ($item['rowType'] == TableDepreciation::pdtReduction)
		{
			$listItem ['accChange'] = $item['amount'];
			$listItem ['taxChange'] = $item['amount'];
			$listItem ['rowType']['icon'] = 'icon-minus-square';
		}
		else
		if ($item['rowType'] == TableDepreciation::pdtDecommission)
		{
			$listItem ['accChange'] = $item['accUsedDepreciation'];
			$listItem ['taxChange'] = $item['taxUsedDepreciation'];
			$listItem ['rowType']['icon'] = 'icon-times';
		}

		return $listItem;
	}
} // class ViewDepreciation


/**
 * Class FormDepreciation
 * @package E10Pro\Property
 */

class FormDepreciation extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
//		$this->setFlag ('maximize', 1);
		$this->openForm ();

		$this->addColumnInput ('rowType');
		$this->addColumnInput ('dateAccounting');

		if ($this->recData['rowType'] == TableDepreciation::pdtDepreciation)
		{
			$this->layoutOpen (TableForm::ltHorizontal);
				$this->layoutOpen (TableForm::ltForm);
					$this->addColumnInput ('accDepreciation');
					$this->addColumnInput ('accCorrection');
					$this->addColumnInput ('accUsedDepreciation');
				$this->layoutClose ('width50');

				$this->layoutOpen (TableForm::ltForm);
					$this->addColumnInput ("depreciationGroup");
					$this->addColumnInput ('taxDepreciation');
					$this->addColumnInput ('taxCorrection');
					$this->addColumnInput ('taxUsedDepreciation');
				$this->layoutClose ('width50');
			$this->layoutClose();
		}
		else
		if ($this->recData['rowType'] == TableDepreciation::pdtIn || $this->recData['rowType'] == TableDepreciation::pdtEnhancement || $this->recData['rowType'] == TableDepreciation::pdtReduction)
		{
			$this->addColumnInput ('amount');
		}
		$this->addColumnInput ('text');

		$this->closeForm ();
	}
} // class FormDepreciation
