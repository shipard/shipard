<?php

namespace e10doc\waster;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;

/**
 * class TableWasteSettings
 */
class TableWasteSettings extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10doc.waster.wasteSettings', 'e10doc_waster_wasteSettings', 'Nastavení odpadů');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
    parent::checkBeforeSave($recData, $ownerData);
    if (intval($recData['calendarYear'] ?? 0))
      $recData ['name'] = 'Nastavení odpadů '.$recData['calendarYear'];
  }

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['name']];

		return $hdr;
	}


	public function saveConfig ()
	{
		$list = [];


		$rows = $this->app()->db->query ('SELECT * from [e10doc_waster_wasteSettings] WHERE [docState] != 9800 ORDER BY [calendarYear]');

		foreach ($rows as $r)
    {
      $wsi = [
        'y' => $r['calendarYear'],
        'docModes' => [
          'invno' => intval($r['docModeInvoiceOut']),
          'stockout' => intval($r['docModeStockOut']),
          'purchase' => intval($r['docModePurchase']),
          'wastelp' => 2,
        ]
      ];
			$list [$r['calendarYear']] = $wsi;
    }
		// save to file
		$cfg ['e10doc']['waster']['settings'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10doc.waster.settings.json', Json::lint($cfg));
	}
}


/**
 * class ViewWasteSettings
 */
class ViewWasteSettings extends TableView
{
	var $wasteDocModes;

	public function init ()
	{
    $this->wasteDocModes = $this->app()->cfgItem('e10doc.waster.docModes');

		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['name'];

    $flags = [];

    $flags[] = ['text' => 'Faktury vydané: '.$this->wasteDocModes[$item['docModeInvoiceOut']]['sc'], 'class' => 'label label-default', 'icon' => 'docType/invoicesOut'];
    $flags[] = ['text' => 'Výdejky: '.$this->wasteDocModes[$item['docModeStockOut']]['sc'], 'class' => 'label label-default', 'icon' => 'docType/stockOut'];
    $flags[] = ['text' => 'Výkupy: '.$this->wasteDocModes[$item['docModePurchase']]['sc'], 'class' => 'label label-default', 'icon' => 'docTypeRedemptions'];

    $listItem ['t2'] = $flags;
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [ws].*');
    array_push ($q, ' FROM [e10doc_waster_wasteSettings] AS [ws]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,' ws.[name] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'ws.', ['[calendarYear]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormWasteSettings
 */
class FormWasteSettings extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('calendarYear');
      $this->addSeparator(self::coH4);
      $this->addColumnInput ('docModeInvoiceOut');
      $this->addColumnInput ('docModeStockOut');
      $this->addColumnInput ('docModePurchase');
		$this->closeForm ();
	}
}
