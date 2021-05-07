<?php

namespace E10\Share;


use \E10\utils, \E10\DocumentAction, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable;


/**
 * Class ShareEngine
 * @package E10\Share
 */
class ShareEngine extends DocumentAction
{
	/** @var  \E10\DbTable */
	var $tableShares;
	var $classId;
	var $tableSharesItems;
	var $tableSharesFolders;
	var $shareRecData = [];
	var $shareNdx = 0;

	var $coreParams;
	var $folders = [];
	var $foldersCounts = [];

	var $attOrderCounter = 1;

	public function init ()
	{
		$this->tableShares = $this->app->table ('e10.share.shares');
		$this->tableSharesItems = $this->app->table ('e10.share.sharesitems');
		$this->tableSharesFolders = $this->app->table ('e10.share.sharesfolders');
	}

	public function addDocument ($tableId, $recId, $info, $id, $folder = FALSE)
	{
		$shareItem = ['share' => $this->shareNdx, 'shareItemType' => 0, 'id' => $id, 'tableId' => $tableId, 'recId' => $recId];

		if (isset($info['t1'])) $shareItem['t1'] = $info['t1'];
		if (isset($info['t2'])) $shareItem['t2'] = $info['t2'];
		if (isset($info['i1'])) $shareItem['i1'] = $info['i1'];
		if (isset($info['i2'])) $shareItem['i2'] = $info['i2'];

		if ($folder !== FALSE)
			$shareItem['folder'] = $this->folders[$folder];

		$shareItemNdx = $this->tableSharesItems->dbInsertRec ($shareItem);
		$this->incFolderCount($folder);

		return $shareItemNdx;
	}

	public function addDocumentAttachments ($tableId, $recId, $shareItemNdx)
	{
		$cntAdded = 0;
		$q [] = 'SELECT * FROM e10_attachments_files WHERE 1';
		array_push($q, 'AND tableid = %s', $tableId, ' AND recid = %i', $recId);
		array_push($q, 'AND [deleted] = 0');
		array_push($q, ' ORDER BY ndx');
		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$newAtt = [
				'tableid' => 'e10.share.sharesitems', 'recid' => $shareItemNdx, 'symlinkTo' => $r['ndx'], 'order' => $this->attOrderCounter++,
				'path' => $r['path'], 'filename' => $r['filename'], 'filetype' => $r['filetype'], 'atttype' => $r['atttype']
			];
			$this->db()->query ('INSERT INTO [e10_attachments_files]', $newAtt);
			$cntAdded++;
		}
		return $cntAdded;
	}

	protected function addItemReport ($shareItemNdx, $report)
	{
		$report->renderReport ();
		$report->createReport ();

		\E10\Base\addAttachments ($this->app, 'e10.share.sharesitems', $shareItemNdx, $report->fullFileName, 'pdf', TRUE, $this->attOrderCounter++);
	}

	public function addReport ($report, $name, $id, $folder = FALSE, $subtitle = FALSE)
	{
		$report->renderReport ();
		$report->createReport ();

		$shareItem = [
			'share' => $this->shareNdx, 'shareItemType' => 1,
			't1' => $name, 'id' => $id
		];

		if ($subtitle !== FALSE)
			$shareItem['t2'] = $subtitle;

		if ($folder !== FALSE)
			$shareItem['folder'] = $this->folders[$folder];

		$shareItemNdx = $this->tableSharesItems->dbInsertRec ($shareItem);
		$this->incFolderCount($folder);

		\E10\Base\addAttachments ($this->app, 'e10.share.sharesitems', $shareItemNdx, $report->fullFileName, 'pdf', TRUE, $this->attOrderCounter++);
	}

	public function addViewerReport ($fileName, $name, $id, $folder = FALSE)
	{
		$shareItem = [
			'share' => $this->shareNdx, 'shareItemType' => 1,
			't2' => $name, 't1' => $id, 'id' => $id
		];
		if ($folder !== FALSE)
			$shareItem['folder'] = $this->folders[$folder];

		$shareItemNdx = $this->tableSharesItems->dbInsertRec ($shareItem);
		$this->incFolderCount($folder);

		\E10\Base\addAttachments ($this->app, 'e10.share.sharesitems', $shareItemNdx, $fileName, 'pdf', TRUE, $this->attOrderCounter++);
	}

	public function createShareHeader ()
	{
		$this->shareRecData['classId'] = $this->classId;
		$this->shareRecData['docState'] = 1200;
		$this->shareRecData['docStateMain'] = 1;

		$this->shareNdx = $this->tableShares->dbInsertRec($this->shareRecData);
	}

	public function done ()
	{
		$share = ['ndx' => $this->shareNdx, 'docState' => 4000, 'docStateMain' => 2];
		$this->tableShares->dbUpdateRec($share);
	}

	public function setCoreParams (array $params)
	{
		$this->coreParams = $params;
	}

	protected function addFolder ($folderId, $folderName)
	{
		$f = ['share' => $this->shareNdx, 'id' => $folderId, 'name' => $folderName];
		$folderNdx = $this->tableSharesFolders->dbInsertRec ($f);
		$this->folders[$folderId] = $folderNdx;
		$this->foldersCounts[$folderId] = 0;
	}

	protected function incFolderCount ($folderId)
	{
		if (isset($this->foldersCounts[$folderId]))
			$this->foldersCounts[$folderId]++;
	}

	protected function saveFoldersCounts ()
	{
		foreach ($this->foldersCounts as $folderId => $folderCount)
		{
			$this->db()->query ('UPDATE e10_share_sharesfolders SET cntItems = %i', $folderCount, ' WHERE ndx = %i', $this->folders[$folderId]);
		}
	}

	protected function saveItemAttachmentsCount ($itemNdx, $attachmentsCount)
	{
		$this->db()->query ('UPDATE e10_share_sharesitems SET cntAttachments = %i', $attachmentsCount, ' WHERE ndx = %i', $itemNdx);
	}

	public function run () {}
}
