<?php

namespace E10\Share;


use \E10\utils, \E10\Utility, \E10\TableViewDetail, \E10\TableForm, \E10\HeaderData, \E10\DbTable;


/**
 * Class ShareEngine
 * @package E10\Share
 */
class SharePackager extends Utility
{
	/** @var  \E10\DbTable */
	var $tableShares;
	var $tableSharesItems;
	var $tableSharesFolders;

	var $coreParams;
	var $shareRecData = [];
	var $shareNdx = 0;
	var $shareId = '';

	var $folders = [];
	var $items = [];

	var $tmpFolderName;

	public function init ()
	{
		$this->tableShares = $this->app->table ('e10.share.shares');
		$this->tableSharesItems = $this->app->table ('e10.share.sharesitems');
		$this->tableSharesFolders = $this->app->table ('e10.share.sharesfolders');
	}

	public function loadShare ()
	{
		$q = 'SELECT * FROM e10_share_shares WHERE id = %s AND docState = 4000';
		$this->shareRecData = $this->db()->query ($q, $this->shareId)->fetch();
		if (!$this->shareRecData)
			return;

		$this->shareNdx = $this->shareRecData['ndx'];

		$this->loadFolders();
		$this->loadItems();
	}

	public function loadFolders ()
	{
		$q = 'SELECT * FROM e10_share_sharesfolders WHERE share = %i';
		$rows = $this->db()->query ($q, $this->shareNdx);
		foreach ($rows as $r)
		{
			$folder = $r->toArray ();
			$this->folders[$r['ndx']] = $folder;
		}
	}

	public function loadItems ()
	{
		$q[] = 'SELECT * FROM e10_share_sharesitems  ';
		array_push($q, ' WHERE share = %i', $this->shareRecData['ndx']);
		array_push($q, ' ORDER BY id, ndx');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$item = $r->toArray ();
			$item['attCounter'] = 1;
			$this->items[$r['ndx']] = $item;
		}
	}

	public function setCoreParams (array $params)
	{
		$this->coreParams = $params;
		$this->shareId = $params['shareId'];
	}

	public function prepare ()
	{
		$this->tmpFolderName = __APP_DIR__.'/tmp/share-'.time().'-'.mt_rand(100000,999999);
		mkdir ($this->tmpFolderName);
	}

	public function save ()
	{
		foreach ($this->items as $item)
		{
			$this->saveFiles ($item['ndx']);
		}
	}

	public function saveFiles ($itemNdx)
	{
		$attachments = \E10\Base\loadAttachments ($this->app, [$itemNdx], 'e10.share.sharesitems');
		if (!count($attachments))
			return;

		$chunksFileList = [];
		$item = &$this->items[$itemNdx];
		$folder = &$this->folders[$item['folder']];

		$dstPrepareFolderName = $this->tmpFolderName.'/prepare/'.$folder['id'].'';
		if (!is_dir($dstPrepareFolderName))
			mkdir ($dstPrepareFolderName, 0777, TRUE);

		foreach ($attachments[$itemNdx]['images'] as $a)
		{
			$srcFullFileName = __APP_DIR__.'/att/'. $a['path'].$a['filename'];



			$dstFileName = $folder['id'].'-'.$item['id'].'-'.sprintf('%03d', $item['attCounter']).'.pdf';
			$dstFullFileName = $dstPrepareFolderName.'/'.$dstFileName;
			if ($a['filetype'] === 'pdf')
			{
				copy ($srcFullFileName, $dstFullFileName);
			}
			else
			{
				exec ("convert \"{$srcFullFileName}\" \"{$dstFullFileName}\"");
			}

			$item['attCounter']++;
			$chunksFileList[] = $dstFullFileName;
		}

		// -- concat to one file
		$dstFolderName = $this->tmpFolderName.'/docs/'.$folder['id'].'';
		if (!is_dir($dstFolderName))
			mkdir ($dstFolderName, 0777, TRUE);

		$dstFileName = $folder['id'].'-'.$item['id'].'.pdf';
		$dstFullFileName = $dstFolderName.'/'.$dstFileName;

		if (count($chunksFileList) === 1)
		{
			copy ($chunksFileList[0], $dstFullFileName);
			$this->folders[$item['folder']]['fileList'][$dstFullFileName] = filesize($dstFullFileName);
		}
		else
		{
			$cmd = 'pdfunite ';
			foreach ($chunksFileList as $oneFileName)
			{
				$cmd .= "\"{$oneFileName}\" ";
			}
			$cmd .= "\"{$dstFullFileName}\"";
			exec ($cmd);

			$this->folders[$item['folder']]['fileList'][$dstFullFileName] = filesize($dstFullFileName);
		}
	}

	public function createLongPdfs ()
	{
		$dstFolderName = $this->tmpFolderName.'/download/pdf';
		if (!is_dir($dstFolderName))
			mkdir ($dstFolderName, 0777, TRUE);

		foreach ($this->folders as $folder)
		{
			if (!isset($folder['fileList']))
				continue;
			$concats = [];
			$lastConcat = ['firstFile' => '', 'lastFile' => '', 'size' => 0, 'files' => []];
			foreach ($folder['fileList'] as $fileName => $fileSize)
			{
				if ($lastConcat['size'] + $fileSize > 9000000)
				{
					$concats[] = $lastConcat;
					$lastConcat = ['firstFile' => '', 'lastFile' => '', 'size' => 0, 'files' => []];
				}

				if ($lastConcat['firstFile'] === '')
					$lastConcat['firstFile'] = $fileName;
				$lastConcat['lastFile'] = $fileName;
				$lastConcat['size'] += $fileSize;
				$lastConcat['files'][] = $fileName;
			}
			$concats[] = $lastConcat;

			foreach ($concats as $c)
			{
				$firstFile = $this->baseFileName($c['firstFile']);
				$lastFile = $this->baseFileName($c['lastFile']);

				$concatFileName = $firstFile.'__'.substr($lastFile, strlen($folder['id']) + 1).'.pdf';
				$concatFullFileName = $dstFolderName.'/'.$concatFileName;

				if (count($c['files']) === 1)
				{
					copy ($c['files'][0], $concatFullFileName);
				}
				else
				{
					$cmd = 'pdfunite ';
					foreach ($c['files'] as $fn)
						$cmd .= "\"{$fn}\" ";
					$cmd .= "\"$concatFullFileName\"";
					exec ($cmd);
				}
			}
		}
	}

	public function createFoldersPdfs ()
	{
		$dstFolderName = $this->tmpFolderName.'/download/pdf';
		if (!is_dir($dstFolderName))
			mkdir ($dstFolderName, 0777, TRUE);

		foreach ($this->folders as $folder)
		{
			if (!isset($folder['fileList']))
				continue;

			$fileNumber = 1;
			$tmpFileName = $dstFolderName.'/'.'xxx0.pdf';
			foreach ($folder['fileList'] as $fileName => $fileSize)
			{
				if ($fileNumber === 1)
				{
					copy ($fileName, $tmpFileName);
				}
				else
				{
					$newTmpFileName = $dstFolderName.'/'.'xxx'.$fileNumber.'.pdf';
					$cmd = "pdfunite \"$tmpFileName\" \"$fileName\" \"$newTmpFileName\"";
					exec ($cmd);
					unlink ($tmpFileName);
					$tmpFileName = $newTmpFileName;
				}
				$fileNumber++;
			}
			rename ($tmpFileName, $dstFolderName.'/'.$folder['id'].'.pdf');
		}
	}


	public function baseFileName ($fn)
	{
		$path_parts = pathinfo ($fn);
		return $path_parts ['filename'];
	}

	public function createZip ()
	{
		$dstFolderName = $this->tmpFolderName.'/download/';
		if (!is_dir($dstFolderName))
			mkdir ($dstFolderName, 0777, TRUE);

		$zipMasks = '';
		$zipPath = $this->tmpFolderName.'/docs';
		foreach ($this->folders as $folder)
		{
			$zipMask = $folder['id'].'/*.pdf';
			$zipFileName = $this->tmpFolderName.'/download/'.$folder['id'].'.zip';
			exec ("cd $zipPath && zip -9 $zipFileName $zipMask");
		}

		$zipFileName = $this->tmpFolderName.'/download/'.'all.zip';
		exec ("cd $zipPath && zip -r -9 $zipFileName *");
	}

	public function run ()
	{
		$this->loadShare();
		$this->prepare();
		$this->save();
		$this->createLongPdfs();
		$this->createFoldersPdfs();
		$this->createZip();
	}
}
