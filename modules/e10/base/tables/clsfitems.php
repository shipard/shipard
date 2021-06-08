<?php

namespace E10\Base;

require_once __DIR__ . '/../../base/base.php';
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableClsfItems
 * @package E10\Base
 */
class TableClsfItems extends DbTable
{
	var $itemsGroups;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.base.clsfitems', 'e10_base_clsfitems', 'Položky zatřídění');
		$this->itemsGroups = $this->app()->cfgItem ('e10.base.clsfGroups');
	}

	public function checkAfterSave (&$recData)
	{
		$this->saveConfig ();
		\E10\compileConfig ();
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave($recData, $ownerData);
		if ($recData['colorbg'] === '')
			$recData['colorfg'] = '';
		else
		{
			$rgb = hexdec(substr($recData['colorbg'], 1));
			$r = ($rgb >> 16) & 0xFF;
			$g = ($rgb >> 8) & 0xFF;
			$b = $rgb & 0xFF;
			$l = sqrt((pow($r, 2) * .241) + (pow($g, 2) * .691) + (pow($b, 2) * .068));

			$recData ['colorfg'] = ($l <= 128) ? '#fff' : '#000';
		}
	}

	public function icon ($item)
	{
		if (isset ($this->itemsGroups [$item['group']]))
		{
			$itemGroup = $this->itemsGroups [$item['group']];
			return (isset ($itemGroup ['icon'])) ? $itemGroup ['icon'] : 'x-tag';
		}
		return 'x-tag';
	}

	public function createHeaderInfo ($recData)
	{
		$hdr ['icon'] = $this->icon ($recData);
		$hdr ['title'] = '';
		$hdr ['info'] = '';

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$ndx = $recData ['ndx'];
		$hdr ['title'] = \E10\es ($recData ['fullName']);

		return $hdr;
	}

	public function saveConfig ()
	{
		$fileName = __APP_DIR__ . '/config/_e10.base.clsf.json';
		$clsfItems = array ();

		$q = 'SELECT * FROM [e10_base_clsfitems] WHERE 1 AND docState != 9800 ORDER BY [order], [fullName], [ndx]';
		$rows = $this->app()->db()->query ($q);
		forEach ($rows as $r)
		{
			$iid = $r ['id'];
			if ($iid == '')
				$iid = 'iid'.$r ['ndx'];
			$ci = [
					'name' => $r ['fullName'], 'id' => $iid, 'ndx' => $r ['ndx'],
					'colorbg' => $r ['colorbg'], 'colorfg' => $r ['colorfg']
			];
			if ($r['colorbg'] !== '')
				$ci['css'] = 'color: ' . $r['colorfg'] . '; background-color: ' . $r['colorbg'];

			$clsfItems ['e10']['base']['clsf'][$r['group']][$r ['ndx']] = $ci;
		}

		file_put_contents ($fileName, json_encode ($clsfItems));
	}
}


/**
 * Class ViewClsfItems
 * @package E10\Base
 */
class ViewClsfItems extends TableView
{
	var $defaultType;

	public function init ()
	{
		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->addAddParam ('group', $this->queryParam ('group'));

		$this->setMainQueries();

		parent::init();
	}

	public function selectRows ()
	{
		$dotaz = $this->fullTextSearch ();
		$q [] = 'SELECT * FROM [e10_base_clsfitems] WHERE 1';
		// -- fulltext
		if ($dotaz != '')
			array_push ($q, " AND [fullName] LIKE %s", '%'.$dotaz.'%');

		if ($this->queryParam ('group'))
			array_push ($q, " AND [group] = %s", $this->queryParam ('group'));

		$this->queryMain ($q, '', ['[order]', '[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['i1'] = ['text' => '#'.$item['group'].'.'.$item['ndx'], 'class' => 'id'];
		$listItem ['icon'] = $this->table->icon ($item);

		$labelText = ($item['id'] !== '') ? $item['id'] : $item['fullName'];

		if ($item['colorbg'] !== '')
		{
			$css = 'color: '.$item['colorfg'].'; background-color: '.$item['colorbg'];
			$listItem ['t2'] = ['text' => $labelText, 'css' => $css, 'class' => 'label'];
		}
		else
			$listItem ['t2'] = ['text' => $labelText, 'class' => 'label label-default'];

		if ($item['order'])
			$listItem ['i2'] = ['text' => utils::nf($item['order']), 'icon' => 'system/iconOrder', 'class' => 'label label-default'];

		return $listItem;
	}
}


/**
 * Class ViewDetailClsfItems
 * @package E10\Base
 */
class ViewDetailClsfItems extends TableViewDetail
{
	public function createHeaderCode ()
	{
		$hdr = $this->table->createHeaderInfo ($this->item);
		return $this->defaultHedearCode ($hdr);
	}
}


/**
 * Class FormClsfItems
 * @package E10\Base
 */
class FormClsfItems extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$this->addColumnInput ('fullName');
			$this->addColumnInput ('id');
			$this->addColumnInput ('colorbg');
			$this->addColumnInput ('order');
			$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
		$this->closeForm ();
	}

	public function createHeaderCode ()
	{
		$hdr = $this->table->createHeaderInfo ($this->recData);
		return $this->defaultHedearCode ($hdr);
	}
}

