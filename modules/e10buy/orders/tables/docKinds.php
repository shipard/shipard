<?php

namespace e10buy\orders;
use \e10\utils, \e10\TableView, \e10\TableForm, \e10\DbTable;


/**
 * Class TableDocKinds
 * @package e10buy\orders
 */
class TableDocKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10buy.orders.docKinds', 'e10buy_orders_docKinds', 'Druhy objednávek');
	}

	public function saveConfig ()
	{
		$docKinds = [];

		$rows = $this->app()->db->query ('SELECT * FROM [e10buy_orders_docKinds] WHERE [docState] != 9800 ORDER BY [order], [fullName], [ndx]');

		foreach ($rows as $r)
		{
			$dk = [
				'ndx' => $r ['ndx'],
				'fullName' => $r ['fullName'], 'shortName' => $r ['shortName'],
				'orderType' => $r ['orderType'],
				'disableRows' => $r ['disableRows'], 'priceOnHead' => $r ['priceOnHead'],
				'useDescription' => $r ['useDescription'],
			];

			$docKinds [strval($r['ndx'])] = $dk;
		}

		$docKinds ['0'] = [
			'ndx' => 0, 'fullName' => '', 'shortName' => '',
			'orderType' => 0, 'disableRows' => 0, 'priceOnHead' => 0,
			'useDescription' => 0,
		];

		// save to file
		$cfg ['e10buy']['orders']['kinds'] = $docKinds;
		file_put_contents(__APP_DIR__ . '/config/_e10buy.orders.docKinds.json', utils::json_lint (json_encode ($cfg)));

		// -- properties
		unset ($cfg);
		$tablePropDefs = new \E10\Base\TablePropdefs($this->app());
		$cfg ['e10buy']['orders']['properties'] = $tablePropDefs->propertiesConfig($this->tableId());
		file_put_contents(__APP_DIR__ . '/config/_e10buy.orders.properties.json', utils::json_lint(json_encode ($cfg)));
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
 * Class ViewDocKinds
 * @package e10buy\orders
 */
class ViewDocKinds extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10buy_orders_docKinds]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q,
				' [fullName] LIKE %s', '%'.$fts.'%',
				' OR [shortName] LIKE %s', '%'.$fts.'%'
			);
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];
		$props[] = ['text' => '#'.$item['ndx'], 'class' => 'pull-right e10-small e10-id'];
		$props[] = ['text' => $item['shortName'], 'class' => ''];

		if ($item['order'])
			$props[] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'pull-right label label-default'];

		$listItem ['t2'] = $props;

		return $listItem;
	}
}


/**
 * Class FormDocKind
 * @package e10buy\orders
 */
class FormDocKind extends TableForm
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
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('orderType');
					$this->addColumnInput ('disableRows');
					$this->addColumnInput ('priceOnHead');
					$this->addColumnInput ('useDescription');
					$this->addColumnInput ('order');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();
				$this->openTab ();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}
