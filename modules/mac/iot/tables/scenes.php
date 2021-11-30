<?php

namespace mac\iot;

use \e10\TableForm, \e10\DbTable, \e10\TableView, \e10\utils, \e10\TableViewDetail;


/**
 * Class TableScenes
 */
class TableScenes extends DbTable
{
	CONST
		sacIoTThingAction = 0,
		sacIotBoxIOPort = 4
	;


	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('mac.iot.scenes', 'mac_iot_scenes', 'Scény');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];
		//	$hdr ['info'][] = ['class' => 'info', 'value' => '#'.$recData['ndx'].'.'.$recData['uid']];

		return $hdr;
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if ($recData['icon'] !== '')
			return $recData['icon'];

		return parent::tableIcon($recData, $options);
	}
}


/**
 * Class ViewScenes
 */
class ViewScenes extends TableView
{
	public function init ()
	{
		parent::init();
		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['i1'] = ['text' => '#'.$item['ndx'], 'class' => 'id'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$t2 = [];
		if ($item['placeName'])
			$t2[] = ['text' => $item['placeName'], 'class' => 'label label-default', 'icon' => 'tables/e10.base.places'];

		$listItem['t2']	= $t2;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [scenes].*,';
		array_push ($q, ' places.fullName AS placeName');
		array_push ($q, ' FROM [mac_iot_scenes] AS [scenes]');
		array_push ($q, ' LEFT JOIN [e10_base_places] AS places ON scenes.place = places.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [scenes].[fullName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, 'scenes.', ['[order], [fullName]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormScene
 */
class FormScene  extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
		//$tabs ['tabs'][] = ['text' => 'Rozvrh', 'icon' => 'formSchedule'];
		$tabs ['tabs'][] = ['text' => 'Akce', 'icon' => 'formAction'];
		$tabs ['tabs'][] = ['text' => 'Události', 'icon' => 'formAction'];
		$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

		$this->openForm ();
			$this->openTabs ($tabs);
				$this->openTab ();
					$this->addColumnInput ('fullName');
					$this->addColumnInput ('shortName');
					$this->addColumnInput ('place');
					$this->addColumnInput ('lan');
					$this->addColumnInput ('friendlyId');
					$this->addColumnInput ('order');
					$this->addColumnInput ('icon');
					$this->addList ('doclinks', '', TableForm::loAddToFormLayout);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addViewerWidget ('mac.iot.eventsDo', 'form', ['dstTableId' => 'mac.iot.scenes', 'dstRecId' => $this->recData['ndx']], TRUE);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addViewerWidget ('mac.iot.eventsOn', 'form', ['dstTableId' => 'mac.iot.scenes', 'dstRecId' => $this->recData['ndx']], TRUE);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailScene
 */
class ViewDetailScene extends TableViewDetail
{
}

