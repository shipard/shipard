<?php

namespace e10pro\bume;

use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable, \Shipard\Utils\Utils;


/**
 * Class TableLists
 */
class TableLists extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10pro.bume.lists', 'e10pro_bume_lists', 'Seznamy pro Hromadnou poštu');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		if (!$recData || !isset ($recData ['ndx']) || $recData ['ndx'] == 0)
			return $hdr;

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fullName']];

		return $hdr;
	}

	public function recipientsLabels($recData, $class = 'label label-default', &$labels, $rows = NULL)
	{
		$virtualGroups = $this->app()->cfgItem ('e10.persons.virtualGroups', []);
		$tableBulkRecipients = $this->app()->table ('e10pro.bume.bulkRecipients');

		if (!$rows)
		{
			$q = [];
			array_push($q, 'SELECT * FROM [e10pro_wkf_bulkRecipients]');
			array_push($q, ' WHERE [bulkMail] = %i', $recData['ndx']);

			$rows = $this->db()->query($q);
		}
		foreach ($rows as $r)
		{
			$enumId = '';
			foreach ($virtualGroups[$r['virtualGroup']]['queryColumns'] as $qcId => $qcLabel)
				$enumId .= $r[$qcId] . '_';

			if (!isset($virtualGroups[$r['virtualGroup']]['enums'][$enumId]))
			{
				foreach ($virtualGroups[$r['virtualGroup']]['queryColumns'] as $qcId => $qcLabel)
				{
					$virtualGroups[$r['virtualGroup']]['enums'][$enumId][$qcId] = $tableBulkRecipients->virtualGroupEnumItems($qcId, $r);
				}
			}

			$itemName = '';
			foreach ($virtualGroups[$r['virtualGroup']]['queryColumns'] as $qcId => $qcLabel)
			{
				if (!$r[$qcId])
					continue;
				if ($itemName !== '')
					$itemName .= '/';

				$itemName .= $virtualGroups[$r['virtualGroup']]['enums'][$enumId][$qcId][$r[$qcId]];
			}

			if (!isset($labels[$r['virtualGroup']]))
			{
				$vg = $virtualGroups[$r['virtualGroup']];
				$labels[$r['virtualGroup']] = ['text' => $vg['name'] . ': ' . $itemName, 'class' => $class, 'icon' => 'system/iconPaperPlane', 'css' => 'white-space: pre-line;'];
			}
			else
				$labels[$r['virtualGroup']]['text'] .= ', '.$itemName;
		}
	}
}


/**
 * class ViewLists
 */
class ViewLists extends TableView
{
	var $recipients = [];
	var $virtualGroups;
	var $tableBulkRecipients;

	public function init ()
	{
		parent::init();

		$this->virtualGroups = $this->app()->cfgItem ('e10.persons.virtualGroups', []);
		$this->tableBulkRecipients = $this->app()->table ('e10pro.bume.bulkRecipients');

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = $this->table->tableIcon ($item);
		$listItem ['t1'] = $item['fullName'];
		//$listItem ['t2'] = [['text' => $item['authorName'], 'icon' => 'system/iconUser']];
		//$listItem ['t2'][] = ['text' => utils::datef($item['dateCreate'], '%D, %T'), 'icon' => 'system/iconCalendar'];

		//$listItem ['i2'] = strval($item['sendingState']).' ';

		//if ($item['sendingState'] === 4 && $item['dateSent'])
		//	$listItem ['i2'] = ['text' => utils::datef ($item['dateSent'], '%D, %T'), 'icon' => 'system/iconPaperPlane'];


//		$listItem ['i2'] = ['text' => utils::datef ($item['dateReadyToSend'], '%D, %T'), 'icon' => 'system/iconPaperPlane'];

		/*
				$props = [];

				if ($item['dateSend'])
					$props [] = ['icon' => 'system/iconPaperPlane', 'text' => utils::datef ($item['dateSend'], '%D, %T')];

				if (count($props))
					$listItem ['i2'] = $props;
		*/
		return $listItem;
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$labels = [];

		if (isset($this->recipients[$item['pk']]))
		{
			//$this->table->recipientsLabels([], 'label label-default', $labels, $this->recipients[$item['pk']]);
		}

		$item ['t3'] = $labels;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();
		$mainQuery = $this->mainQueryId ();

		$q = [];
    array_push ($q, 'SELECT [lists].*');
    array_push ($q, ' FROM [e10pro_bume_lists] AS [lists]');
    array_push ($q, '');
    array_push ($q, '');
//		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS authors ON mails.author = authors.ndx');
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
			array_push ($q, ' AND ([fullName] LIKE %s)', '%'.$fts.'%');

		$this->queryMain ($q, '[lists].', ['[fullName]', '[ndx]']);
		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
    /*
		if (!count ($this->pks))
			return;

		$q = [];
		array_push ($q, 'SELECT * FROM [e10pro_wkf_bulkRecipients]');
		array_push ($q, ' WHERE [bulkMail] IN %in', $this->pks);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->recipients[$r['bulkMail']][] = $r->toArray();
		}
    */
	}
}


/**
 * Class ViewDetailList
 */
class ViewDetailList extends TableViewDetail
{
	public function createDetailContent ()
	{
	}
}


/**
 * class ViewDetailListRecipients
 */
class ViewDetailListRecipients extends TableViewDetail
{
	public function createDetailContent ()
	{
    /*
		$this->addContent (
			[
				'type' => 'viewer', 'table' => 'e10pro.bume.bulkPosts', 'viewer' => 'e10pro.bume.ViewBulkPosts',
				'params' => ['bulkMail' => $this->item ['ndx']]
			]);
    */
	}
}


/**
 * class FormList
 */
class FormList extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			//$this->layoutOpen (TableForm::ltGrid);
			//	$this->openRow ('grid-form-tabs');
					$this->addColumnInput ('fullName');
			//	$this->closeRow ();
			$this->layoutClose ();

			//$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Příjemci', 'icon' => 'formRecipients'];
			$tabs ['tabs'][] = ['text' => 'Nastavení', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Rozšíření VCARD', 'icon' => 'system/formSettings'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];

			$this->openTabs ($tabs);
				$this->openTab ();
	        $this->addList ('rows');
				$this->closeTab ();

				$this->openTab ();
					$this->addColumnInput ('bcCompany');
					$this->addColumnInput ('bcQRCodeLinkMask');
					$this->addColumnInput ('vcardPersFuncProperty');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('vcardExt', NULL, TableForm::coFullSizeY);
				$this->closeTab ();

				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
