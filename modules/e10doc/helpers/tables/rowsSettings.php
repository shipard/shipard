<?php

namespace e10doc\helpers;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Viewer\TableViewDetail;


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
	var $bankAccounts;

	public function init ()
	{
		$this->settingsTypes = $this->app()->cfgItem ('e10doc.helpers.rowsSettingsTypes');
		$this->bankAccounts = $this->app()->cfgItem ('e10doc.bankAccounts');

		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$st = $this->settingsTypes[$item['settingsType']];

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];

		$props2 = [];
		$props3 = [];
		$props2[] = ['text' => $st['name'], 'class' => 'label label-default'];

		if ($item['qryMyBankAccountType'])
		{
			$mba = $this->bankAccounts[$item['myBankAccount']] ?? NULL;
			if ($mba)
				$props2[] = ['text' => $mba['fullName'], 'icon' => 'tables/e10doc.base.bankaccounts', 'class' => 'label label-default'];
		}

		if ($item['qryRowDirType'] === 1)
			$props2[] = ['text' => 'Příjem', 'icon' => 'system/actionInputPlus', 'class' => 'label label-default'];
		elseif ($item['qryRowDirType'] === 2)
			$props2[] = ['text' => 'Výdej', 'icon' => 'system/actionInputMinus', 'class' => 'label label-default'];

		$this->addStringQuery($item['qryRowBankAccountType'], $item['qryRowBankAccountValue'], $props3, 'bú');

		$this->addStringQuery($item['qryRowSymbol1Type'], $item['qryRowSymbol1Value'], $props3, 'vs');
		$this->addStringQuery($item['qryRowSymbol2Type'], $item['qryRowSymbol2Value'], $props3, 'ss');
		$this->addStringQuery($item['qryRowSymbol3Type'], $item['qryRowSymbol3Value'], $props3, 'ks');
		$this->addStringQuery($item['qryRowTextType'], $item['qryRowTextValue'], $props3, 'txt');

		$listItem ['t2'] = $props2;
		if (count($props3))
			$listItem ['t3'] = $props3;

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	protected function addStringQuery($qryType, $value, &$dest, $title = '')
	{
		if (!$qryType)
			return;

		$txt = '';
		if ($qryType === 1)
			$txt = '= `'.$value.'`';
		elseif ($qryType === 2)
			$txt = '`'.$value.'`'.'*';
		elseif ($qryType === 3)
			$txt = '*'.'`'.$value.'`'.'*';

		$label = ['text' => $txt, 'class' => 'label label-info'];
		if ($title !== '')
			$label['prefix'] = $title;

		$dest[] = $label;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT st.*,');
		array_push ($q, ' headPersons.fullName AS headPersonFullName');
		array_push ($q, ' FROM [e10doc_helpers_rowsSettings] AS st');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS headPersons ON st.qryHeadPerson = headPersons.ndx');
		array_push ($q, '');
		array_push ($q, '');
		array_push ($q, '');
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


/**
 * class ViewDetailFormRowSettings
 */
class ViewDetailFormRowSettings extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}
