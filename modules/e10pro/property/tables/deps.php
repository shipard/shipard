<?php

namespace e10pro\property;


use \E10\utils, \E10\TableView, \E10\TableViewDetail, \E10\TableForm, \E10\DbTable;


/**
 * Class TableDeps
 * @package e10pro\property
 */
class TableDeps extends DbTable
{
	CONST pdtIn = 1, pdtEnhancement = 2, pdtReduction = 4, pdtDepreciation = 99, pdtDecommission = 120;
	static $rowTypesName = [1 => "Zařazení", 2 => "Zhodnocení", 4 => "Snížení hod.", 99 => "Odpis", 120 => "Vyřazení"];
	static $rowsIcons = ['1' => 'icon-dot-circle-o', '2' => 'icon-arrow-circle-up', '4' => 'icon-arrow-circle-down', '99' => 'icon-level-down', '120' => 'icon-times-circle'];

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.property.deps', 'e10pro_property_deps', 'Odpisy majetku');
	}

	public function checkAfterSave2 (&$recData)
	{
		$this->resetBalances ($recData['property']);
	}

	public function resetBalances ($propertyNdx)
	{
		$q [] = 'SELECT * FROM [e10pro_property_deps] WHERE 1';
		array_push ($q, ' AND property = %i', $propertyNdx);
		array_push ($q, ' AND [docStateMain] < 4');
		array_push ($q, ' ORDER BY [dateAccounting], [rowType], [ndx]');

		$rows = $this->db()->query($q);
		$balance = 0;
		$taxBalance = 0.0;
		$accBalance = 0.0;
		foreach ($rows as $r)
		{
			if ($r['rowType'] == TableDeps::pdtIn)
			{
				$balance = $r['amount'];
				$initState = $balance;
				$taxBalance = $r ['amount'];
				$accBalance = $r ['amount'];
			}
			elseif ($r['rowType'] == TableDeps::pdtEnhancement)
			{
				if ($balance)
				{
					$initState = $balance;
					$balance += $r ['amount'];
				}
				else
				{
					$initState = $r ['amount'];
					$balance = $r ['amount'];
				}
				$taxBalance += $r ['amount'];
				$accBalance += $r ['amount'];
			}
			elseif ($r['rowType'] == TableDeps::pdtDepreciation)
			{
				if ($r['depsPart'] == 1)
				{
					$initState = $taxBalance;
					$taxBalance -= $r ['depreciation'];
					$balance = $taxBalance;
				}
				elseif ($r['depsPart'] == 2)
				{
					$initState = $accBalance;
					$accBalance -= $r ['usedDepreciation'];
					$balance = $accBalance;
				}
			}
			elseif ($r['rowType'] == TableDeps::pdtReduction)
			{
				$initState = $balance;
				$balance -= $r ['amount'];
				$taxBalance -= $r ['amount'];
				$accBalance -= $r ['amount'];
			}
			elseif ($r['rowType'] == TableDeps::pdtDecommission)
			{
				$initState = 0.0;
				$balance = 0.0;
				$taxBalance = 0.0;
				$accBalance = 0.0;
			}

			if ($balance != $r['balance'] || $initState != $r['initState'])
				$this->db()->query('UPDATE [e10pro_property_deps] SET ', ['balance' => $balance, 'initState' => $initState], ' WHERE ndx = %i', $r['ndx']);
		}
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if ($recData['depsPart'] == 2) // acc dep
			$recData['depreciation'] = $recData['usedDepreciation'];

		if (!utils::dateIsBlank($recData['dateAccounting']))
		{
			if (utils::dateIsBlank($recData['periodBegin']))
			{
				$de = new \e10pro\property\DepreciationsEngine ($this->app());
				$fp = $de->fiscalPeriod($recData['dateAccounting']);
				$recData['periodBegin'] = $fp['begin'];
			}
			if (utils::dateIsBlank($recData['periodEnd']))
			{
				$de = new \e10pro\property\DepreciationsEngine ($this->app());
				$fp = $de->fiscalPeriod($recData['dateAccounting']);
				$recData['periodEnd'] = $fp['end'];
			}
		}

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$itemTop = [];
		$property = $this->app()->loadItem($recData['property'], 'e10pro.property.property');
		if ($property['propertyType'])
		{
			$pt = $this->app()->loadItem ($property['propertyType'], 'e10pro.property.types');
			$itemTop[] = ['text' => $pt['fullName']];
		}

		$itemBottom = [];
		if ($recData['depsPart'] == 1)
		{
			$itemBottom[] = ['text' => 'Daňový odpis'];
		}
		elseif ($recData['depsPart'] == 2)
		{
			$itemBottom[] = ['text' => 'Účetní odpis'];
		}

		$hdr ['info'][] = ['class' => 'info', 'value' => $itemTop];
		$hdr ['info'][] = ['class' => 'title', 'value' => $property ['fullName']];
		$hdr ['info'][] = ['class' => 'info', 'value' => $itemBottom];

		return $hdr;
	}


	public function tableIcon ($recData, $options = NULL)
	{
		return self::$rowsIcons[$recData['rowType']];
	}
}


/**
 * Class ViewDeps
 * @package e10pro\property
 */
class ViewDeps extends \E10\TableViewGrid
{ // TODO: delete?
}


/**
 * Class FormDep
 * @package e10pro\property
 */
class FormDep extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
//		$this->setFlag ('maximize', 1);
		$this->openForm ();

			$this->addColumnInput ('dateAccounting');

			if ($this->recData['depsPart'] == 1)
			{ // tax dep
				$this->addColumnInput ('depreciation');
				$this->addColumnInput ('usedDepreciation');
				$this->addColumnInput ('periodBegin');
				$this->addColumnInput ('periodEnd');
			}
			elseif ($this->recData['depsPart'] == 2)
			{ // acc dep
				$this->addColumnInput ('usedDepreciation');
				$this->addColumnInput ('periodBegin');
				$this->addColumnInput ('periodEnd');
			}
			else
			{
				$this->addColumnInput('rowType');
			}

			if ($this->recData['rowType'] == TableDepreciation::pdtIn || $this->recData['rowType'] == TableDepreciation::pdtEnhancement || $this->recData['rowType'] == TableDepreciation::pdtReduction)
			{
				$this->addColumnInput ('amount');
			}
			$this->addColumnInput ('text');



		$this->closeForm ();
	}
}
