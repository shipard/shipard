<?php

namespace mac\iot;

use \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail;
use \Shipard\Application\DataModel;


/**
 * class TableESignsKinds
 */
class TableESignsKinds extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.esignsKinds', 'mac_iot_esignsKinds', 'Typy E-cedulek');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function getESignKindInfo($esignKindNdx)
	{
		$info = [];
		$info['esignKindRecData'] = $this->loadItem($esignKindNdx);
		if (!$info['esignKindRecData'])
			return NULL;

		$epaperCfg = NULL;
		$epaperCfg = $this->app()->cfgItem('mac.iot.epds.types.'.$info['esignKindRecData']['displayType'], NULL);
		$info['orientation'] = intval($esignKindRecData['orientation'] ?? 0);

		if ($epaperCfg)
		{
			$info['ok'] = 1;

			if ($info['orientation'] === 0 || $info['orientation'] === 2)
			{
				$info['width'] = $epaperCfg['width'];
				$info['height'] = $epaperCfg['height'];
			}
			else
			{
				$info['height'] = $epaperCfg['width'];
				$info['width'] = $epaperCfg['height'];
			}
			$info['cntColors'] = $epaperCfg['cntColors'];
			$info['colors'] = $epaperCfg['colors'];

			$info['displayInfoLabel'] = [['text' => $info['width'].' ✖️ '.$info['height'].'; '.$epaperCfg['cntColors'].'C', 'class' => 'label label-info']];
			$cc = "<span style='padding: 2px;'>";
			foreach ($epaperCfg['colors'] as $strColor)
			{
				$cc .= "<span style='width: 1rem; display:inline-block; padding-left: 2px;border: 1px solid #777; background-color: #".$strColor.";'> </span>";
			}

			$cc .= '</span>';

			$info['displayInfoLabel'][] = ['code' => $cc, 'class' => ''];
		}

		return $info;
	}
}


/**
 * class ViewESignsKinds
 */
class ViewESignsKinds extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'idName'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

    $t2 = [];

		$displayInfo = $this->table->getESignKindInfo($item ['ndx']);
		if ($displayInfo && ($displayInfo['ok'] ?? 0))
		{
			$t2 = $displayInfo['displayInfoLabel'];
		}

    $listItem['t2'] = $t2;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [esignsKinds].*';
		array_push ($q, ' FROM [mac_iot_esignsKinds] AS [esignsKinds]');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [esignsKinds].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[esignsKinds].', ['[esignsKinds].[shortName], [fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormESignKind
 */
class FormESignKind extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
    $tabs ['tabs'][] = ['text' => 'Šablona', 'icon' => 'formText'];
    $tabs ['tabs'][] = ['text' => 'CSS', 'icon' => 'formText'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('displayType');
					$this->addColumnInput ('orientation');
					$this->addColumnInput ('vds');
				$this->closeTab ();
        $this->openTab (TableForm::ltNone);
          $this->addInputMemo ('codeTemplate', NULL, TableForm::coFullSizeY, DataModel::ctCode);
        $this->closeTab();
        $this->openTab (TableForm::ltNone);
          $this->addInputMemo ('codeStyle', NULL, TableForm::coFullSizeY, DataModel::ctCode);
        $this->closeTab();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * class ViewDetailESignKind
 */
class ViewDetailESignKind extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}

