<?php


namespace e10\reports;
use \Shipard\Viewer\TableView, \Shipard\Form\TableForm, \Shipard\Table\DbTable;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\Utils;


/**
 * class TableReportsTexts
 */
class TableReportsTexts extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('e10.reports.reportsTexts', 'e10_reports_reportsTexts', 'Texty na výstupních sestavách');
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		$recData ['systemOrder'] = 99;

		if ($recData['language'] !== '')
			$recData ['systemOrder']--;

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['language']) || $recData['language'] === '')
			$recData['language'] = 'cs';
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$props = [];
		$t1 = '';
		$this->itemInfo ($recData, $props, $t1);

		$hdr ['info'][] = ['class' => 'info', 'value' => $props];
		$hdr ['info'][] = ['class' => 'title', 'value' => $t1];

		return $hdr;
	}

	public function itemInfo ($recData, &$props, &$title)
	{
		$enumPlaces = $this->columnInfoEnum('reportPlace');
		if (isset($enumPlaces[$recData['reportPlace']]))
			$title = $enumPlaces[$recData['reportPlace']];
	}

	public function loadReportTexts (\Shipard\Report\Report $report, &$dest)
	{
		$lang = $report->lang;
		if ($lang === FALSE || $lang == '')
			$lang = 'cs';

		$q[] = 'SELECT * FROM [e10_reports_reportsTexts]';
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [docStateMain] IN %in', [0, 2]);
		array_push ($q, ' AND ([language] = %s', $report->lang, ' OR [language] = %s)', '');
		array_push ($q, ' AND (onAllReports = %i', 1, ' OR EXISTS ');
		array_push ($q, ' (SELECT ndx FROM [e10_base_doclinks] ');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND e10_reports_reportsTexts.ndx = srcRecId');
		array_push ($q, ' AND dstRecId = %i', $report->sendReportNdx);
		array_push ($q, ' AND [srcTableId] = %s', 'e10.reports.reportsTexts');
		array_push ($q, ' AND [dstTableId] = %s', 'e10.reports.reports');
		array_push ($q, '))');
		array_push ($q, ' ORDER BY [systemOrder], [order], [ndx]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			if (isset($dest[$r['reportPlace']]))
				continue;

			$txt = $r['text'];

			$dest[$r['reportPlace']] = $txt;
		}
	}
}


/**
 * Class ViewReportsTexts
 */
class ViewReportsTexts extends TableView
{
	var $toReports;

	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$langCfg = $this->app()->cfgItem('e10.base.languages.'.$item['language'], NULL);

		$listItem ['pk'] = $item ['ndx'];
		$listItem ['onAllReports'] = $item ['onAllReports'];

		$props = [];
		$t1 = '';
		$this->table->itemInfo ($item, $props, $t1);

		$listItem ['t1'] = $t1;
		$listItem ['t2'] = $props;

		if ($langCfg)
			$listItem['t2'][] = ['text' => $langCfg['name'], 'class' => 'label label-info'];

		if ($item['note'] !== '')
			$listItem['t3'] = $item['note'];

		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [e10_reports_reportsTexts]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [note] LIKE %s', '%'.$fts.'%', ' OR [text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[order]', '[ndx]']);
		$this->runQuery ($q);
	}

	function decorateRow (&$item)
	{
		if ($item ['onAllReports'])
		{
			$item ['t2'][] = ['text' => 'Bude na všech sestavách', 'class' => 'label label-info'];
		}
		else
		{
			if (isset ($this->toReports [$item ['pk']]))
			{
				$item ['t2'] = array_merge($item ['t2'], $this->toReports [$item ['pk']]);
			}
		}
	}

	public function selectRows2 ()
	{
		if (!count($this->pks))
			return;

		$this->toReports = UtilsBase::linkedSendReports($this->app(), $this->table, $this->pks);
	}
}


/**
 * class FormReportText
 */
class FormReportText extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Základní', 'icon' => 'system/formHeader'];
			$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'formText'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'system/formAttachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('onAllReports');
					if (!$this->recData['onAllReports'])
						$this->addList ('reports', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ('language');
					$this->addColumnInput ('reportPlace');
					$this->addColumnInput ('note');
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addColumnInput ('text', TableForm::coFullSizeY);
				$this->closeTab();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs();
		$this->closeForm ();
	}
}

