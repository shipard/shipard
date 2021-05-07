<?php

namespace e10doc\helpers;

use \E10\utils, \E10\TableView, \E10\TableForm, \E10\DbTable;


/**
 * Class TableRowsSettings
 * @package e10doc\helpers
 */
class TableRowsSettings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.helpers.rowsSettings', 'e10doc_helpers_rowsSettings', 'Nastavení řádků dokladů');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['name']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * Class ViewRowsSettings
 * @package e10doc\helpers
 */
class ViewRowsSettings extends TableView
{
	var $settingsTypes;

	public function init ()
	{
		$this->settingsTypes = $this->app()->cfgItem ('e10doc.helpers.rowsSettingsTypes');

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$st = $this->settingsTypes[$item['settingsType']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $st['name'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT st.* FROM [e10doc_helpers_rowsSettings] AS st';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' st.[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'st.', ['[name]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormRowSettings
 * @package e10doc\helpers
 */
class FormRowSettings extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('settingsType');
			$this->addColumnInput ('name');

			$this->addSeparator(self::coH2);
			$this->layoutOpen (TableForm::ltGrid);
				$this->openRow (/*'grid-form-tabs'*/);
					$this->addStatic('Osoba na hlavičce dokladu je', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryHeadPerson',TableForm::coColW9);
				$this->closeRow ();
				$this->openRow (/*'grid-form-tabs'*/);
					$this->addStatic('Vlastní bankovní spojení', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryMyBankAccountType', TableForm::coColW2);
					$this->addColumnInput ('myBankAccount',TableForm::coColW7);
				$this->closeRow ();
				$this->openRow (/*'grid-form-tabs'*/);
					$this->addStatic('Směr pohybu peněz', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryRowDirType', TableForm::coColW2);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Číslo účtu', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryRowBankAccountType', TableForm::coColW2);
					$this->addColumnInput ('qryRowBankAccountValue', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('VS', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryRowSymbol1Type', TableForm::coColW2);
					$this->addColumnInput ('qryRowSymbol1Value', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('SS', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryRowSymbol2Type', TableForm::coColW2);
					$this->addColumnInput ('qryRowSymbol2Value', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('KS', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryRowSymbol3Type', TableForm::coColW2);
					$this->addColumnInput ('qryRowSymbol3Value', TableForm::coColW7);
				$this->closeRow ();
				/*
				$this->openRow ();
					$this->addStatic('Pohyb', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryOperationType', TableForm::coColW2);
					$this->addColumnInput ('qryOperationValue', TableForm::coColW7);
				$this->closeRow ();
				*/
				$this->openRow (/*'grid-form-tabs'*/);
					$this->addStatic('Text řádku', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('qryRowTextType', TableForm::coColW2);
					$this->addColumnInput ('qryRowTextValue',TableForm::coColW7);
				$this->closeRow ();
			$this->layoutClose ();

			$this->addSeparator(self::coH2);
			$this->layoutOpen (TableForm::ltGrid);
				$this->openRow (/*'grid-form-tabs'*/);
					$this->addStatic('Osoba', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowPersonType', TableForm::coColW2);
					$this->addColumnInput ('valRowPersonValue',TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Pohyb', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowOperationType', TableForm::coColW2);
					$this->addColumnInput ('valRowOperationValue', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('VS', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowSymbol1Type', TableForm::coColW2);
					$this->addColumnInput ('valRowSymbol1Value', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('SS', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowSymbol2Type', TableForm::coColW2);
					$this->addColumnInput ('valRowSymbol2Value', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('KS', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowSymbol3Type', TableForm::coColW2);
					$this->addColumnInput ('valRowSymbol3Value', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Položka', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowItemType', TableForm::coColW2);
					$this->addColumnInput ('valRowItemValue', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Středisko', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowCentreType', TableForm::coColW2);
					$this->addColumnInput ('valRowCentreValue', TableForm::coColW7);
				$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Zakázka', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowWorkOrderType', TableForm::coColW2);
					$this->addColumnInput ('valRowWorkOrderValue', TableForm::coColW7);
					$this->closeRow ();
				$this->openRow ();
					$this->addStatic('Text řádku', TableForm::coColW3|TableForm::coRight);
					$this->addColumnInput ('valRowTextType', TableForm::coColW2);
					$this->addColumnInput ('valRowTextValue', TableForm::coColW7);
				$this->closeRow ();
			$this->layoutClose ();
		$this->closeForm ();
	}
}
