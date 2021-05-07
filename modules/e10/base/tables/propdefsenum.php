<?php

namespace E10\Base;

use \E10\Application;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;

class TablePropdefsenum extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.propdefsenum", "e10_base_propdefsenum", "Výčet hodnot vlastností");
	}
} // class TablePropDefsEnum


/*
 * ViewPropDefsEnum
 *
 */

class ViewPropDefsEnum extends TableView
{
	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['i1'] = $item['id'];

		$listItem ['icon'] = 'x-properties';

		return $listItem;
	}

	public function selectRows ()
	{
		if ($this->queryParam ('property'))
		{
			$q = "SELECT * FROM [e10_base_propdefsenum] WHERE [property] = %i ORDER BY [id]" . $this->sqlLimit();
			$this->runQuery ($q, $this->queryParam ('property'));
		}
		else
		{
			$q = "SELECT * FROM [e10_base_propdefsenum] ORDER BY [id]" . $this->sqlLimit();
			$this->runQuery ($q);
		}
	}
} // class ViewPropDefsEnum


/* 
 * FormPropDefsEnum
 * 
 */

class FormPropDefsEnum extends TableForm
{
	public function renderForm ()
	{
		$this->openForm (TableForm::ltHorizontal);
			$this->addColumnInput ("fullName");
			$this->addColumnInput ("id");
		$this->closeForm ();
	}	

} // class FormPropDefsEnum

