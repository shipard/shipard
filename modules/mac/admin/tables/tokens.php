<?php

namespace mac\admin;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;



/**
 * Class TableTokens
 */
class TableTokens extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.admin.tokens', 'mac_admin_tokens', 'Tokeny');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['token']];

		return $hdr;
	}
}


/**
 * Class ViewTokens
 */
class ViewTokens extends TableView
{
	public function init ()
	{
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = substr($item['token'], 0, 12).'...'.substr($item['token'], -12);

		$labelExp = ['text' => $item['expireAfter']->format('Y-m-d'), 'suffix' => $item['expireAfter']->format('H:m:j'), 'class' => 'label label-success'];
		if ($item['expired'])
			$labelExp['class'] = 'label label-danger';

		$listItem ['t2'] = $labelExp;
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem['i2'] = ['text' => $item['lanShortName'], 'icon' => 'tables/mac.lan.lans'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
		array_push ($q, ' SELECT [tokens].*, lans.shortName AS lanShortName');
		array_push ($q, ' FROM [mac_admin_tokens] AS [tokens]');
		array_push ($q, ' LEFT JOIN mac_lan_lans AS lans ON tokens.lan = lans.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [token] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY expireAfter DESC, ndx');
		array_push ($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * class ViewDetailToken
 */
class ViewDetailToken extends TableViewDetail
{
}


/**
 * Class FormToken
 */
class FormToken extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('lan', self::coReadOnly);
					$this->addColumnInput ('token', self::coReadOnly);
					$this->addColumnInput ('expireAfter', self::coReadOnly);
					$this->addColumnInput ('expired', self::coReadOnly);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
