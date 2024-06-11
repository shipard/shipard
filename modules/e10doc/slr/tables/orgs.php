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
	var $orgTypes;

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();

		$this->orgTypes = $this->app()->cfgItem('e10doc.slr.orgTypes');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];


		//$listItem ['i1'] = ['text' => $item['importId'], 'class' => 'id'];

		$ot = $this->orgTypes[$item['orgType']];

		$props = [];

		$props[] = ['text' => $ot['sn'], 'class' => 'label label-default'];

		$listItem ['t2'] = $props;


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
					$this->addColumnInput ('orgType');
					$this->addColumnInput ('isDefault');
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
