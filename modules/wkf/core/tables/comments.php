<?php

namespace wkf\core;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;



/**
 * Class TableComments
 * @package wkf\core
 */
class TableComments extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('wkf.core.comments', 'wkf_core_comments', 'Komentáře', 1240);
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['dateCreate']) || self::dateIsBlank ($recData ['dateCreate']))
			$recData ['dateCreate'] = new \DateTime();

		if (!isset($recData ['dateTouch']) || utils::dateIsBlank($recData ['dateTouch']) || !isset($recData['activateCnt']) || !$recData['activateCnt'])
			$recData ['dateTouch'] = new \DateTime();

		if (isset($recData['ndx']))
			$recData['displayOrder'] = $recData['ndx'];

		parent::checkBeforeSave ($recData, $ownerData);
	}

	public function checkNewRec (&$recData)
	{
		parent::checkNewRec ($recData);

		if (!isset($recData['commentType']))
			$recData['commentType'] = 0;

		if (!isset($recData ['author']) || !$recData ['author'])
			$recData ['author'] = $this->app()->userNdx();


		if (isset($recData['inReplyToIssue']))
		{
			$issueRecData = $this->app()->loadItem($recData['inReplyToIssue'], 'wkf.core.issues');
			if ($issueRecData)
				$recData['text'] = $this->makeCitationText($issueRecData['text']);

			unset ($recData['inReplyToIssue']);
		}

		if (isset($recData['inReplyToComment']))
		{
			$commentRecData = $this->loadItem($recData['inReplyToComment']);
			if ($commentRecData)
				$recData['text'] = $this->makeCitationText($commentRecData['text']);

			unset ($recData['inReplyToComment']);
		}
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => 'TEST'];

		return $hdr;
	}

	public function checkDocumentState (&$recData)
	{
		parent::checkDocumentState ($recData);

		// -- activating
		if (!isset ($recData['activateCnt']))
			$recData['activateCnt'] = 0;
		if ($recData['docStateMain'] >= 1)
			$recData['activateCnt']++;
	}

	public function docsLog ($ndx)
	{
		$recData = parent::docsLog($ndx);

		if ($recData['docStateMain'] === 2)
		{
			$tableIssues = $this->app()->table('wkf.core.issues');
			$issueRecData = $tableIssues->loadItem($recData['issue']);
			$tableIssues->doNotify($issueRecData, 1, $recData);
		}

		return $recData;
	}

	function makeCitationText($src)
	{
		$c = '';

		$rows = preg_split("/\\r\\n|\\r|\\n/", $src);
		foreach ($rows as $r)
		{
			$c .= '> '.$r."\n";
		}

		return $c;
	}
}


/**
 * Class ViewComments
 * @package wkf\core
 */
class ViewComments extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsMini;
		$this->enableDetailSearch = FALSE;
		//$this->enableToolbar = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fullName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$props = [];

		if ($item['shipardEmailId'] !== '')
			$props [] = ['icon' => 'icon-at', 'text' => $item ['shipardEmailId'], 'class' => 'label label-default'];

		if ($item ['order'] != 0)
			$props [] = ['icon' => 'icon-sort', 'text' => utils::nf ($item ['order']), 'class' => 'pull-right'];

		if (count($props))
			$listItem ['t2'] = $props;

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [wkf_core_comments]';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [text] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * Class FormComment
 * @package wkf\core
 */
class FormComment extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('maximize', 1);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Text', 'icon' => 'x-content'];
			$tabs ['tabs'][] = ['text' => 'Přílohy', 'icon' => 'x-attachments'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab (TableForm::ltNone);
					$this->addInputMemo ('text', NULL, TableForm::coFullSizeY);
				$this->closeTab ();
				$this->openTab (TableForm::ltNone);
					$this->addAttachmentsViewer();
				$this->closeTab ();
			$this->closeTabs ();
		$this->closeForm ();
	}
}
