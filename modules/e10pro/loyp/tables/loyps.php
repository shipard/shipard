<?php

namespace e10pro\loyp;

use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableViewDetail;
use \Shipard\Utils\Utils, \Shipard\Utils\Json;


/**
 * class TableLoyps
 */
class TableLoyps extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.loyp.loyps', 'e10pro_loyp_loyps', 'Věrnostní programy');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData ['shortName']];
		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function saveConfig ()
	{
		$list = [];
		$rows = $this->app()->db->query ('SELECT * from [e10pro_loyp_loyps] WHERE [docState] != 9800 ORDER BY [fullName]');

		foreach ($rows as $r)
    {
			$list [$r['ndx']] = [
        'ndx' => $r ['ndx'],
				'type' => $r ['loypType'],
        'fn' => $r ['fullName'],
        'sn' => $r ['shortName'],
				'minPointsPerDoc' => $r ['minPointsPerDoc'],
        'dbCounterInvoiceOut' => $r ['dbCounterInvoiceOut'],
        'warehouse' => $r ['warehouse'],
      ];

			if (!Utils::dateIsBlank($r['validFrom']))
				$list [$r['ndx']]['validFrom'] = $r['validFrom']->format('Y-m-d');
			if (!Utils::dateIsBlank($r['validTo']))
				$list [$r['ndx']]['validTo'] = $r['validTo']->format('Y-m-d');
    }

		$cfg ['e10pro']['loyp']['loyps'] = $list;
		file_put_contents(__APP_DIR__ . '/config/_e10pro.loyp.loyps.json', Json::lint ($cfg));
	}
}


/**
 * class ViewLoyps
 */
class ViewLoyps extends TableView
{
	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;
		$this->setMainQueries ();
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item['ndx'];

    $listItem ['t1'] = $item['fullName'];

    $ft = utils::dateFromTo($item['validFrom'], $item['validTo'], NULL);
		if ($ft !== '')
			$listItem['i2'] = ['text' => $ft, 'class' => 'label label-default'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push ($q, 'SELECT [loyps].*');
    array_push ($q, ' FROM [e10pro_loyp_loyps] AS [loyps]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, " AND (loyps.[fullName] LIKE %s OR loyps.[shortName] LIKE %s)", '%'.$fts.'%', '%'.$fts.'%');

		$this->queryMain ($q, '[loyps].', ['fullName', 'ndx']);
		$this->runQuery ($q);
	}
}


/**
 * class FormLoyp
 */
class FormLoyp extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('loypType');
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('shortName');
			$this->addSeparator(self::coH4);
			$this->addColumnInput ('minPointsPerDoc');
      $this->addSeparator(self::coH4);
      $this->addColumnInput ('validFrom');
      $this->addColumnInput ('validTo');
      $this->addSeparator(self::coH4);
      $this->addColumnInput ('dbCounterInvoiceOut');
      $this->addColumnInput ('warehouse');
		$this->closeForm ();
	}
}


/**
 * class ViewDetailLoyp
 */
class ViewDetailLoyp extends TableViewDetail
{
}
