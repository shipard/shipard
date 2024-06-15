<?php

namespace E10\Base;

include_once __DIR__ . '/../base.php';

use \E10\Application;
use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\utils;
use \E10\DbTable;

class TableAttachments extends DbTable
{
	CONST apLocal = 0, apE10Remote = 1, apRemote = 2, apSymlink = 3;

	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ("e10.base.attachments", "e10_attachments_files", "Přílohy", 1013);
	}

	public function tableIcon ($recData, $options = NULL)
	{
		if (isset($recData['fileKind']))
		{
			$fileKind = $recData['fileKind'];
			if ($fileKind)
			{
				$fileKindCfg = $this->app()->cfgItem('e10.att.fileKinds.' . $fileKind, NULL);
				if ($fileKindCfg)
					return $fileKindCfg['icon'];
			}
		}
		return parent::tableIcon ($recData, $options);
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = [
			'class' => 'title', 'value' => [
				['text' => $recData ['name']],
				['text' => '#'.$recData ['ndx'], 'class' => 'id pull-right'],
			]
		];

		$attInfo = $this->attInfo($recData);
		if ($recData['fileKind'] !== 0)
			$hdr ['info'][] = ['class' => 'info', 'value' => $attInfo['labels']];

		return $hdr;
	}

	public function checkBeforeSave (&$recData, $ownerData = NULL)
	{
		if (!isset ($recData ['ndx']) || $recData ['ndx'] == 0)
		{
			if ($recData ['symlinkTo'] != 0)
			{
				$srcAtt = $this->loadItem ($recData ['symlinkTo']);

				$recData ['name'] = $srcAtt ['name'];
				$recData ['attplace'] = $srcAtt ['attplace'];
				$recData ['path'] = $srcAtt ['path'];
				$recData ['filename'] = $srcAtt ['filename'];
				$recData ['filetype'] = $srcAtt ['filetype'];
				$recData ['atttype'] = $srcAtt ['atttype'];
			}
		}
  }

	public function renderViewerItem ($viewerData, $item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['icon'] = "table";
		$listItem ['t1'] = $item['name'];
		$listItem ['t2'] = $item['filename'];
		return $listItem;
	}

	public function upload ()
	{
		$app = $this->app();

		$maxFileNameLen = 48;
		$rndTxt = '-' . base_convert(time() + rand(), 10, 35);
		$origbn = urldecode(basename($app->requestPath()));
		$path_parts = pathinfo($origbn);
		$baseFileName = utils::safeFileName($path_parts ['filename']);
		$fileType = $path_parts ['extension'];
		$bn = $baseFileName . $rndTxt . '.' . $fileType;

		if (strlen($bn) > $maxFileNameLen)
		{
			$baseFileName = substr($baseFileName, 0, -(strlen($bn) - $maxFileNameLen));
			$bn = $baseFileName . $rndTxt . '.' . $fileType;
		}
		$destFileName = __APP_DIR__ . '/att/' . $bn;
		$relTmpFileName = 'att/' . $bn;

		if (isset ($_FILES['file']['tmp_name']))
		{ // classic php way
			move_uploaded_file($_FILES['file']['tmp_name'], $destFileName);
		}
		else
		{ // ajax upload from application
			$fileReader = fopen('php://input', "r");
			$fileWriter = fopen($destFileName, "w+");

			while (true)
			{
				$buffer = fgets($fileReader, 4096);
				if (strlen($buffer) == 0)
				{
					fclose($fileReader);
					fclose($fileWriter);
					break;
				}
				fwrite($fileWriter, $buffer);
			}
		}

		$destTable = $this->app()->requestPath(2);

		if ($destTable == '_tmp')
		{
			$infoFileName = __APP_DIR__.'/tmp/_upload_' . md5($relTmpFileName) . '.json';
			file_put_contents($infoFileName, json_encode(['fileName' => $path_parts ['filename']]));
		}
		else
		{
			$destRecId = $this->app()->requestPath (3);
			if ($destRecId[0] === '_')
			{
				$table = $this->app()->table($destTable);
				$destRecId = $table->createUploadDocument (substr($destRecId, 1), $baseFileName);
			}
			$origFileName = $this->app()->requestPath (3);

			addAttachments ($this->app(), $destTable, $destRecId, $destFileName, '', TRUE, 0, $path_parts ['filename']);

			$relTmpFileName = 'OK';
		}

		return $relTmpFileName;
	}

	public function loadMetaData($attachmentNdx)
	{
		$mdConfig = $this->app()->cfgItem ('e10.att.metaDataTypes');

		$mdList = [];
		$rows = $this->db()->query ('SELECT * FROM [e10_attachments_metaData] WHERE [attachment] = %i', $attachmentNdx);
		foreach ($rows as $r)
		{
			$mdType = isset($mdConfig[$r['metaDataType']]) ? $mdConfig[$r['metaDataType']] : NULL;
			if (!$mdType)
				continue;

			$item = ['mdType' => $mdType, 'recData' => $r->toArray()];

			$content = [];
			if ($r['metaDataType'] === 1)
			{ // -- text content
				$item['content'][] = ['type' => 'text', 'subtype' => 'auto', 'text' => $r['data']];
			}
			elseif ($r['metaDataType'] === 2)
			{ // -- exif
				$d = json_decode($r['data'], TRUE);
				if (!$d)
				{
					$item['content'][] = ['type' => 'text', 'subtype' => 'auto', 'text' => 'Vadná EXIF data'];
					continue;
				}

				foreach ($d as $exifPartNdx => $exifPartData)
				{
					$t = [];
					foreach ($exifPartData as $key => $value)
						$t[] = ['k' => $key, 'v' => $value];

					$h = ['k' => 'Parametr', 'v' => 'Hodnota'];
					$item['content'][] = ['type' => 'table', 'table' => $t, 'header' => $h];
				}

			}

			$mdList[] = $item;
		}

		return $mdList;
	}

	public function attInfo($attRecData)
	{
		$info = ['labels' => []];

		$fileKind = $attRecData['fileKind'];
		$fileKindCfg = $this->app()->cfgItem('e10.att.fileKinds.'.$fileKind);
		$info['icon'] = $fileKindCfg['icon'];

		// -- labels
		$l = ['icon' => $fileKindCfg['icon'], 'text' => '', 'class' => 'label label-info'];
		if (!utils::dateIsBlank($attRecData['contentDate']))
			$l['text'] = utils::datef($attRecData['contentDate'], '%d, %T');

		if ($fileKind === 2)
		{ // pdf
			if ($attRecData['i3'])
				$l['text'] = $attRecData['i3']. (($attRecData['i3'] == 1) ? ' strana' : ' stran');
		}
		elseif ($fileKind === 3)
		{ // photo
			if ($attRecData['i1'] && $attRecData['i2'])
				$l['suffix'] = $attRecData['i1'].'x'.$attRecData['i2'];
		}
		$info['labels'][] = $l;

		// -- gps coordinates
		if ($attRecData['locState'] === 1)
		{ // location exist
			$info['labels'][] = ['icon' => 'system/iconMapMarker', 'text' => '', 'class' => 'e10-success'];
		}
		elseif ($attRecData['locState'] === 2)
		{ // not available
			if ($fileKind === 3) // photo
				$info['labels'][] = ['icon' => 'system/iconMapMarker', 'text' => '', 'title' => 'Informace o poloze nejsou k dispozici', 'class' => 'e10-error'];
		}

		return $info;
	}
}

/**
 * Class ViewAttachmentsAll
 * @package E10\Base
 */
class ViewAttachmentsAll extends \E10\TableView
{
	public function init ()
	{
		/*
		if ($this->queryParam ('recid'))
			$this->addAddParam ('recid', $this->queryParam ('recid'));
		if ($this->queryParam ('tableid'))
			$this->addAddParam ('tableid', $this->queryParam ('tableid'));
		*/
		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['name'];
		$listItem ['i1'] = '#'.$item ['ndx'];
		$listItem ['icon'] = 'x-image';

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch();
		$mainQuery = $this->mainQueryId ();

		$q[] = 'SELECT att.* FROM [e10_attachments_files] AS att ';

		array_push($q, ' WHERE 1');

		if ($fts !== '')
		{
			array_push($q, ' AND att.[name] LIKE %s', '%'.$fts.'%');
		}

		if ($mainQuery === 'deleted')
			array_push($q, ' AND att.[deleted] = 1');
		else
		if ($mainQuery === 'active')
			array_push($q, ' AND att.[deleted] = 0');

		$this->qryCommon ($q);

		//array_push($q, ' AND [tableid] = %s', $this->queryParam ('tableid'));
		//array_push($q, ' AND [recid] = %s', $this->queryParam ('recid'));
		array_push($q, $this->sqlLimit());

		$this->runQuery ($q);
	}

	public function qryCommon (array &$q)
	{
	}
}


/**
 * Class ViewAttachmentsImages
 * @package E10\Base
 */
class ViewAttachmentsImages extends ViewAttachmentsAll
{
	public function init ()
	{
		//$this->objectSubType = TableView::vsDetail;

		$mq [] = ['id' => 'active', 'title' => 'Aktivní'];
		$mq [] = ['id' => 'deleted', 'title' => 'Smazané'];
		$this->setMainQueries ($mq);

		parent::init();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item ['name'];
		$listItem ['i2'] = '#'.$item ['ndx'];
		$listItem ['image'] = $this->app->dsRoot.'/imgs/-w192/-h384/att/'.$item['path'].$item['filename'];

		return $listItem;
	}

	public function qryCommon (array &$q)
	{
		$enabledTables = ['e10.web.pages'];
		if ($this->queryParam('comboSrcTableId'))
			$enabledTables[] = $this->queryParam('comboSrcTableId');

		array_push($q, 'AND [filetype] IN %in', ['jpg', 'jpeg', 'png', 'gif', 'svg']);

		array_push($q, 'AND (');

		$tableFolders = $this->app->table ('wkf.docs.folders');
		$usersFolders = $tableFolders->usersFolders();
		if (count($usersFolders['all']))
		{
			array_push($q,
				' EXISTS (SELECT ndx FROM wkf_docs_documents WHERE att.recid = ndx AND tableId = %s', 'wkf.docs.documents',
				' AND wkf_docs_documents.[folder] IN %in', array_keys($usersFolders['all']),
				' AND wkf_docs_documents.[docState] IN %in', [1000, 4000, 8000],
				')');
			array_push ($q, ' OR ');
		}

		array_push ($q, ' (', 'att.[tableid] IN %in', $enabledTables, ')');
		array_push ($q, ')');
	}
}


/**
 * Class FormAttachments
 * @package E10\Base
 */
class FormAttachments extends TableForm
{
	var $metaData = [];
	var $hasMetaData = FALSE;

	public function renderForm ()
	{
		$this->loadMetaData();
		if ($this->hasMetaData)
		{
			$this->renderFormWithMetaData();
			return;
		}

		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
			if (!isset ($this->recData ['ndx']) || $this->recData ['ndx'] == 0)
				$this->addColumnInput ("symlinkTo");
			else
			{
				$this->addColumnInput ("name");
				$this->addColumnInput ("perex");
				$this->addColumnInput ("defaultImage");
				$this->addList ('clsf', '', TableForm::loAddToFormLayout);
				$this->addColumnInput ("order");
			}
		$this->closeForm ();
	}

	function renderFormWithMetaData()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();

		$tabs ['tabs'][] = ['text' => 'Příloha', 'icon' => 'system/iconPaperclip'];
			foreach ($this->metaData as $md)
			{
				$tabs ['tabs'][] = ['text' => $md['mdType']['tabLabel'], 'icon' => $md['mdType']['icon']];
			}
			$this->openTabs ($tabs, TRUE);

			$this->openTab ();
				if (!isset ($this->recData ['ndx']) || $this->recData ['ndx'] == 0)
					$this->addColumnInput ("symlinkTo");
				else
				{
					$this->addColumnInput ("name");
					$this->addColumnInput ("perex");
					$this->addColumnInput ("defaultImage");
					$this->addList ('clsf', '', TableForm::loAddToFormLayout);
					$this->addColumnInput ("order");
				}
			$this->closeTab();

			foreach ($this->metaData as $md)
			{
				$this->openTab ();
					$this->addContent($md['content']);
				$this->closeTab();
			}

		$this->closeTabs();
		$this->closeForm ();
	}

	function loadMetaData()
	{
		if (!$this->recData['ndx'] )
			return;

		$this->metaData = $this->table->loadMetaData($this->recData['ndx']);

		if (count($this->metaData))
			$this->hasMetaData = TRUE;
	}
}
