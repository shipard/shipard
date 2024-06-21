<?php

namespace e10doc\slr;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;



/**
 * class TableOrgs
 */
class TableOrgs extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.slr.orgs', 'e10doc_slr_orgs', 'Instituce');
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
 * class ViewOrgs
 */
class ViewOrgs extends TableView
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

    array_push ($q, 'SELECT [orgs].* ');
		array_push ($q, ' FROM [e10doc_slr_orgs] AS [orgs]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' [orgs].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [orgs].[bankAccount] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [orgs].[symbol1] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [orgs].[symbol2] LIKE %s', '%'.$fts.'%');
			array_push ($q,' OR [orgs].[symbol3] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[orgs].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormOrg
 */
class FormOrg extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Instituce', 'icon' => 'system/formHeader'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
          $this->addColumnInput ('person');
					$this->addColumnInput ('fullName');
					$this->addSeparator(self::coH4);
          $this->addColumnInput ('bankAccount');
					$this->addColumnInput ('symbol1');
					$this->addColumnInput ('symbol2');
					$this->addColumnInput ('symbol3');
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}

	public function comboParams ($srcTableId, $srcColumnId, $allRecData, $recData)
	{
		if ($srcTableId === 'e10doc.slr.orgs' && $srcColumnId === 'bankAccount')
		{
			$cp = [
				'personNdx' => strval ($allRecData ['recData']['person'])
			];

			return $cp;
		}

		return parent::comboParams ($srcTableId, $srcColumnId, $allRecData, $recData);
	}
}


/**
 * Class ViewDetailOrg
 */
class ViewDetailOrg extends TableViewDetail
{
	public function createDetailContent ()
	{
		//$this->addDocumentCard('e10doc.slr.dc.DCImport');
	}
}
