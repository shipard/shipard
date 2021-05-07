<?php

namespace E10\Witems;

use \E10\Application, \E10\TableView, \E10\TableForm, \E10\TableViewDetail, \E10\DbTable, \E10\DataModel;

class TableItemCategoriesQry extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.witems.itemcategoriesqry", "e10_witems_itemcategoriesqry", "Podmínky pro kategorie položek");
	}

	public function columnInfoReference ($destTable, $srcColumnDef, $srcRecData, $search = '')
	{
		if ($srcColumnDef ['sql'] == 'valueEnum')
		{
			$res = array ();
			$res [0] = '---';
			$prop = intval ($srcRecData['property']);
			$q = "SELECT [ndx], [fullName] FROM [e10_base_propdefsenum] WHERE [property] = $prop ORDER BY [fullName] LIMIT 0, 800";
			$rows = $this->fetchAll ($q);
			forEach ($rows as $r)
				$res [$r ['ndx']] = $r ['fullName'];
			return $res;
		}
		return parent::columnInfoReference ($destTable, $srcColumnDef, $srcRecData, $search);
	}
} // class TableItemCategoriesQry


/* 
 * FormItemCategoriesQry
 * 
 */

class FormItemCategoriesQry extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$ppty = $this->table->loadItem ($this->recData['property'], 'e10_base_propdefs');

		$this->openForm ();
			$this->addColumnInput ("queryType", DataModel::coSaveOnChange);

			if ($this->recData ['queryType'] == 0)
			{
				$this->addColumnInput ("property", DataModel::coSaveOnChange);

				if (isset($ppty['type']) && $ppty['type'] === 'enum')
					$this->addColumnInput ("valueEnum");
				else
					$this->addColumnInput ("valueString");
			}
			else
			if ($this->recData ['queryType'] == 1)
			{
				$this->addColumnInput ("valueItemType");
			}
		$this->closeForm ();
	}	
} // class FormItemCategoriesQry

