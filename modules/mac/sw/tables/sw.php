<?php

namespace mac\sw;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


use \Shipard\Form\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableSW
 * @package mac\sw
 */
class TableSW extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.sw.sw', 'mac_sw_sw', 'Software', 1352);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'info', 'value' => $recData['fullName']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['swClass'] === 1)
		{
			$icon = '';
			$osFamily = $this->app()->cfgItem('mac.swcore.osFamily.'.$recData['osFamily'], NULL);
			if ($osFamily)
				$icon = $osFamily['icon'];

			if ($icon !== '')
				return $icon;
		}

		$swClass = $this->app()->cfgItem('mac.swcore.swClass.'.$recData['swClass'], NULL);
		if ($swClass && isset($swClass['icon']))
			return $swClass['icon'];

		return parent::tableIcon ($recData, $options);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		parent::checkBeforeSave ($recData, $ownerData);

		if (isset($recData['suid']) && $recData['suid'] === '' && isset($recData['ndx']) && $recData['ndx'] !== 0)
		{
			$recData['suid'] = utils::createRecId($recData, '!06Z');
		}
	}

	public function columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, TableForm $form = NULL)
	{
		if ($columnId === 'osEdition')
		{
			if (!isset ($cfgItem['osf']))
				return TRUE;

			if ($cfgItem['osf'] == $form->recData['osFamily'])
				return TRUE;

			return FALSE;
		}

		return parent::columnInfoEnumTest ($columnId, $cfgKey, $cfgItem, $form);
	}
}


/**
 * Class ViewSW
 * @package mac\sw
 */
class ViewSW extends TableView
{
	var $swClass;
	var $osFamily;
	var $osEdition;
	var $lifeCycle;

	var $categories = [];

	public function init ()
	{
		parent::init();

		$this->enableDetailSearch = TRUE;

		$this->swClass = $this->app()->cfgItem ('mac.swcore.swClass');
		$this->osFamily = $this->app()->cfgItem ('mac.swcore.osFamily');
		$this->osEdition = $this->app()->cfgItem ('mac.swcore.osEdition');
		$this->lifeCycle = $this->app()->cfgItem ('mac.swcore.lifeCycle');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['suid'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);


		$props = [];

		$swc = $this->swClass[$item['swClass']];
		$props[] = ['text' => $swc['fn'], 'class' => 'label label-default'];

		if ($item['swClass'] === 1)
		{
			$osf = $this->osFamily[$item['osFamily']];
			$props[] = ['text' => $osf['sn'], 'icon' => $osf['icon'], 'class' => 'label label-default'];

			$ose = $this->osEdition[$item['osEdition']];
			$props[] = ['text' => $ose['sn'], 'x-icon' => '', 'class' => 'label label-default'];

		}

		$listItem['t2'] = $props;


		if ($item['lifeCycle'] !== 1)
		{
			$lc = $this->lifeCycle[$item['lifeCycle']];
			$listItem['i2'] = ['text' => $lc['sn'], 'icon' => $lc['icon'], 'class' => 'label label-warning'];
		}

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [sw].*';
		array_push ($q, ' FROM [mac_sw_sw] AS [sw]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [sw].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [sw].[suid] LIKE %s', '%'.$fts.'%');
			array_push ($q, " OR EXISTS (SELECT [ndx] FROM [mac_sw_swIds] WHERE [sw].[ndx] = [mac_sw_swIds].[sw] AND [id] LIKE %s)", '%'.$fts.'%');
			array_push ($q, " OR EXISTS (SELECT [ndx] FROM [mac_sw_swNames] WHERE [sw].[ndx] = [mac_sw_swNames].[sw] AND [name] LIKE %s)", '%'.$fts.'%');
			array_push ($q, " OR EXISTS (SELECT [ndx] FROM [mac_sw_swVersions] WHERE [sw].[ndx] = [mac_sw_swVersions].[sw] AND [versionNumber] LIKE %s)", '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'sw.', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		// -- sections
		$q[] = 'SELECT docLinks.*, [cats].shortName, [cats].icon';
		array_push($q, ' FROM [e10_base_doclinks] AS docLinks');
		array_push($q, ' LEFT JOIN [mac_sw_categories] AS [cats] ON docLinks.dstRecId = [cats].ndx');
		array_push($q, ' WHERE srcTableId = %s', 'mac.sw.sw', 'AND dstTableId = %s', 'mac.sw.categories');
		array_push($q, ' AND docLinks.linkId = %s', 'mac-sw-swCats', 'AND srcRecId IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$l = [
				'text' => $r['shortName'],
				'icon' => $r['icon'] === '' ? 'icon-folder' : $r['icon'],
				'class' => 'label label-default'
			];
			$this->categories[$r['srcRecId']][] = $l;
		}
	}

	function decorateRow (&$item)
	{
		if (isset ($this->categories [$item ['pk']]))
		{
			$item['t2'] = array_merge($item['t2'], $this->categories [$item ['pk']]);
		}
	}
}


/**
 * Class FormSW
 * @package mac\sw
 */
class FormSW extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$swClass = $this->app()->cfgItem('mac.swcore.swClass.'.$this->recData['swClass']);
		$osFamily = $this->app()->cfgItem('mac.swcore.osFamily.'.$this->recData['osFamily']);

		$tabs ['tabs'][] = ['text' => 'Aplikace', 'icon' => 'icon-hourglass-half'];
		$tabs ['tabs'][] = ['text' => 'Verze', 'icon' => 'icon-hashtag'];
		$tabs ['tabs'][] = ['text' => 'Názvy', 'icon' => 'icon-tags'];
		$tabs ['tabs'][] = ['text' => 'ID', 'icon' => 'icon-crosshairs'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'icon-paperclip'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('swClass');
					if (isset($swClass['askOSInfo']))
					{
						$this->addColumnInput ('osFamily');
						$this->addColumnInput ('osEdition');
					}
					$this->addColumnInput ('publisher');
					$this->addColumnInput ('lifeCycle');

					$this->addColumnInput ('useFree');
					$this->addColumnInput ('licenseType');

					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);

					$this->addColumnInput ('swVersionsMode');
					$this->addColumnInput ('ignoreIDs');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('versions', 'default');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('names', 'default');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addListViewer ('ids', 'default');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					\E10\Base\addAttachmentsWidget ($this);
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSW
 * @package mac\sw
 */
class ViewDetailSW extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * Class ViewDetailSWAnnotations
 * @package mac\sw
 */
class ViewDetailSWAnnotations extends TableViewDetail
{
	public function createDetailContent ()
	{
		$this->addContentViewer ('e10pro.kb.annots', 'default',
			['docTableNdx' => $this->table->ndx, 'docRecNdx' => $this->item['ndx']]);
	}
}
