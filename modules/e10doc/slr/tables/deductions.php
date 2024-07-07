<?php

namespace e10doc\slr;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableDeductions
 */
class TableDeductions extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.deductions', 'e10doc_slr_deductions', 'Srážky a exekuce');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		//$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewDeductions
 */
class ViewDeductions extends TableView
{
	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];

		$props3 = [];
		if ($item['bankAccount'] !== '')
			$props3[] = ['text' => $item['bankAccount'], 'icon' => 'paymentMethodTransferOrder', 'class' => 'label label-default'];
		else
			$props3[] = ['text' => 'Chybí bankovní účet pro úhradu', 'class' => 'label label-danger'];

		$props3[] = ['text' => $item['symbol1'], 'prefix' => 'VS', 'class' => 'label label-default'];

		if ($item['symbol2'] !== '')
			$props3[] = ['text' => $item['symbol2'], 'prefix' => 'SS', 'class' => 'label label-default'];

		if ($item['symbol3'] !== '')
			$props3[] = ['text' => $item['symbol3'], 'prefix' => 'KS', 'class' => 'label label-default'];

		$listItem ['t2'] = $props3;


		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];

    array_push ($q, 'SELECT [deds].* ');
		array_push ($q, ' FROM [e10doc_slr_deductions] AS [deds]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [deds].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [deds].[bankAccount] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [deds].[symbol1] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [deds].[symbol2] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [deds].[symbol3] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[deds].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormDeduction
 */
class FormDeduction extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Srážka', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
          $this->addSeparator(self::coH4);
          $this->addColumnInput ('slrItem');
          $this->addColumnInput ('payTo');
					$this->addSeparator(self::coH4);
          $this->addColumnInput ('bankAccount');
					$this->addColumnInput ('symbol1');
					$this->addColumnInput ('symbol2');
					$this->addColumnInput ('symbol3');
          $this->addSeparator(self::coH4);
					$this->addColumnInput ('validFrom');
					$this->addColumnInput ('validTo');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.slr.deductions' && $srcColumnId === 'bankAccount')
		{
			$cp = [
				'personNdx' => strval ($allRecData ['recData']['payTo'])
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * Class ViewDetailDeduction
 */
class ViewDetailDeduction extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('e10doc.slr.dc.DCImport');
	}
}
