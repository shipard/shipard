<?php

namespace e10pro\soci;
use \Shipard\Utils\Utils, \Shipard\Viewer\TableViewGrid, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * class TableEntriesInvoicing
 */
class TableEntriesInvoicing extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.soci.entriesInvoicing', 'e10pro_soci_entriesInvoicing', 'Fakturace přihlášek');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}
}


/**
 * class ViewEntriesInvoicing
 */
class ViewEntriesInvoicing extends TableViewGrid
{
  var $entryKinds;
  var $saleTypes;
  var $paymentPeriods;

	public function init ()
	{
    $this->gridEditable = TRUE;
		$this->objectSubType = self::vsDetail;

    $this->entryKinds = $this->table->app()->cfgItem ('e10pro.soci.entriesKinds', FALSE);
    $this->saleTypes = $this->table->app()->cfgItem ('e10pro.soci.saleTypes', FALSE);
    $this->paymentPeriods = $this->table->app()->cfgItem ('e10pro.soci.paymentPeriods', FALSE);

		parent::init();

		$g = [
			'fullName' => 'Název',
      'entryKind' => 'Druh p.',
      'saleType' => 'Sleva',
      'paymentPeriod' => 'Období',
      'priceAll' => ' Cena',
    ];

		$this->setGrid ($g);
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, 'SELECT invoicing.*,');
		array_push ($q, ' workOrders.docNumber AS woDocNumber, workOrders.title AS woTitle');
		array_push ($q, ' FROM [e10pro_soci_entriesInvoicing] AS invoicing');
		array_push ($q, ' LEFT JOIN e10mnf_core_workOrders AS workOrders ON invoicing.entryTo = workOrders.ndx');

		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' invoicing.[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'invoicing.', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];

		$listItem ['fullName'] = [['text' => $item ['fullName'], 'class' => 'block']];
		if ($item['woTitle'])
			$listItem ['fullName'][] = ['text' => $item ['woTitle'], 'class' => 'e10-small'];

    $listItem ['priceAll'] = $item ['priceAll'];

    $listItem ['entryKind'] = $this->entryKinds[$item ['entryKind']]['fn'] ?? '!!!';
    $listItem ['saleType'] = $this->saleTypes[$item ['saleType']]['fn'] ?? '!!!';
    $listItem ['paymentPeriod'] = $this->paymentPeriods[$item ['paymentPeriod']]['fn'] ?? '!!!';

		return $listItem;
	}
}


/**
 * class FormEntryInvoicing
 */
class FormEntryInvoicing extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];

			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('entryKind');
					$this->addColumnInput ('entryTo');
					$this->addColumnInput ('saleType');
					$this->addColumnInput ('paymentPeriod');

					$this->addColumnInput ('item');
					$this->addColumnInput ('priceAll');

          $this->addColumnInput ('order');
				$this->closeTab ();
				$this->openTab ();
				$this->closeTab ();
		$this->closeTabs();
		$this->closeForm ();
	}
}
