<?php

namespace E10\Base;

require_once __DIR__ . '/../../base/base.php';
require_once __DIR__ . '/clsfitems.php';


use \E10\Application, \e10\utils;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\HeaderData;
use \E10\DbTable;

class TableClsfGroups extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.clsfgroups", "e10_base_clsfgroups", "Skupiny zatřídění");
	}

	public function icon ($item)
	{
		return $item ['icon'];
	}

	public function loadItem ($ndx, $table = NULL)
	{
		$clsf = $this->app()->cfgItem ('e10.base.clsfGroups');
		$group = $clsf [$ndx];
		$item = array ("ndx" => $ndx, "fullName" => $group['name'], 'icon' => $group ['icon']);
		return $item;
	}

	public function createHeaderInfo ($recData)
	{
		$hdr ['icon'] = $this->icon ($recData);
		$hdr ['title'] = $recData ['fullName'];
		$hdr ['info'] = '';

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$ndx = $recData ['ndx'];
		$hdr ['title'] = \E10\es ($recData ['fullName']);

		return $hdr;
	}
} // class TableClsfGroups


/**
 * Class ViewClsfGroups
 * @package E10\Base
 */
class ViewClsfGroups extends TableView
{
	public function selectRows ()
	{
		$this->rowsPageSize = 500;
		$this->queryRows = [];
		$this->ok = 1;

		if ($this->rowsFirst > 0)
			return;

		$fts = $this->fullTextSearch();

		$clsf = $this->app()->cfgItem ('e10.base.clsfGroups');
		forEach ($clsf as $key => $group)
		{
			if ($fts != '')
			{
				$nd = strtr($group['name'], utils::$transDiacritic);
				if (mb_stristr($group['name'], $fts, FALSE, 'UTF-8') === FALSE && mb_stristr($nd, $fts, FALSE, 'UTF-8') === FALSE)
						continue;
			}
			$this->queryRows [] = ["ndx" => $key, "fullName" => $group['name'], 'icon' => $group ['icon']];
		}
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->icon ($item);

		return $listItem;
	}

	public function createToolbar ()
	{
		return [];
	}
}


/**
 * Základní detail Položek zatřídění
 *
 */

class ViewDetailClsfGroups extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$hdr = $this->table->createHeaderInfo ($this->item);
		return $this->defaultHedearCode ($hdr);
	}

	public function createDetailContent ()
	{
		$this->addContentViewer ('e10.base.clsfitems', 'e10.base.ViewClsfItems', array ('group' => $this->item ['ndx']));
	}

	public function createToolbar ()
	{
		$toolbar = array ();
		return $toolbar;
	} // createToolbar
}


/* 
 * FormClsfGroups
 * 
 */

class FormClsfGroups extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');

		$this->openForm ();
			$this->addColumnInput ("fullName");
		$this->closeForm ();
	}

	public function createHeaderCode ()
	{
		$hdr = $this->table->createHeaderInfo ($this->recData);
		return $this->defaultHedearCode ($hdr);
	}
} // class FormClsfGroups

