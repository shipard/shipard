<?php

namespace integrations\core;
use \e10\utils, \e10\TableView, \e10\TableViewDetail, \e10\TableForm, \e10\DbTable;


/**
 * Class TableDownloadFiles
 * @package integrations\core
 */
class TableDownloadFiles extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.core.downloadFiles', 'integrations_core_downloadFiles', 'Stahování souborů', 0);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['fileName']];

		return $hdr;
	}

	public function addFileToFront($recData)
	{
		$exist = $this->db()->query('SELECT * FROM [integrations_core_downloadFiles] WHERE [fileId] = %s', $recData['fileId'], ' AND [service] = %i', $recData['service'])->fetch();
		if ($exist)
			return 0;

		// -- detect file kind
		$fileExt = substr(strrchr($recData['fileName'], '.'), 1);
		$ee = new \lib\core\attachments\Extract($this->app());
		$recData['fileKind'] = $ee->fileKind($fileExt);

		// -- detect user


		// -- add to front
		$ndx = $this->dbInsertRec($recData);
		$this->docsLog ($ndx);

		return $ndx;
	}
}


/**
 * Class ViewDownloadFiles
 * @package integrations\core
 */
class ViewDownloadFiles extends TableView
{
	var $tableAttachments;

	public function init ()
	{
		parent::init();

		//$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->tableAttachments = $this->app()->table('e10.base.attachments');
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['fileName'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		$attInfo = $this->tableAttachments->attInfo($item);
		$listItem ['icon'] = $attInfo['icon'];

		$listItem['t2'] = $attInfo['labels'];

		if ($item['fileCreatedDateTime'])
			$listItem['t2'][] = ['text' => utils::datef($item['fileCreatedDateTime'], '%D, %T'), 'class' => 'label label-default', 'icon' => 'icon-clock-o'];

		if ($item['fileSize'])
			$listItem['t2'][] = ['text' => utils::memf($item['fileSize']), 'class' => 'label label-default', 'icon' => 'system/actionDownload'];

		if ($item['userEmail'] !== '')
			$listItem['t2'][] = ['text' => $item['userEmail'], 'class' => 'label label-default', 'icon' => 'icon-user-circle-o'];

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT * FROM [integrations_core_downloadFiles] AS df';
		array_push ($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' df.[fileName] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		array_push ($q, ' ORDER BY [ndx] DESC');
		array_push ($q, $this->sqlLimit ());

		$this->runQuery ($q);
	}
}


/**
 * Class FormDownloadFile
 * @package integrations\core
 */
class FormDownloadFile extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			$tabs ['tabs'][] = ['text' => 'Soubor', 'icon' => 'icon-file'];
			$this->openTabs ($tabs, TRUE);
				$this->openTab ();
					$this->addColumnInput ('service');
					$this->addColumnInput ('task');
				$this->closeTab();
			$this->closeTabs();
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailDownloadFile
 * @package integrations\core
 */
class ViewDetailDownloadFile extends TableViewDetail
{
}


