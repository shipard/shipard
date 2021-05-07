<?php

namespace lib\docDataFiles;

use e10\Utility, lib\core\attachments\MetaData;


/**
 * Class AttachmentsUpdater
 * @package lib\docDataFiles
 */
class AttachmentsUpdater extends Utility
{
	/** @var \e10\base\TableAttachments */
	var $tableAttachments;

	public function init()
	{
		$this->tableAttachments = $this->app()->table('e10.base.attachments');
	}

	public function doOne($attNdx)
	{
		$attRecData = $this->tableAttachments->loadItem($attNdx);
		if (!$attRecData)
			return;

		$attFileName = __APP_DIR__.'/att/'.$attRecData['path'].$attRecData['filename'];

		$fk = $attRecData['fileKind'];
		if ($fk === MetaData::fkUnknown || $fk === MetaData::fkNone)
		{
			$mt = strtolower(mime_content_type($attFileName));
			if (substr($mt, 0, 5) === 'text/')
			{
				$fk = MetaData::fkText;
			}
		}

		if ($fk !== MetaData::fkNone)
		{
			$attRecData['fileKind'] = $fk;
			$this->db()->query ('UPDATE [e10_attachments_files] SET fileKind = %i', $fk, ' WHERE [ndx] = %i', $attRecData['ndx']);
		}

		if ($fk !== MetaData::fkText)
			return;

		$ddd = new \lib\docDataFiles\Detector($this->app());
		$ddd->init();
		$ddd->setAttachment($attRecData['ndx'], $attRecData['ddfNdx']);
		$ddd->detect();

		if ($ddd->ddfId)
		{
			$this->db()->query ('UPDATE [e10_attachments_files] SET ddfId = %i', $ddd->ddfId, ', ddfNdx = %i', $ddd->ddfNdx, ' WHERE [ndx] = %i', $attRecData['ndx']);
		}
	}

	function doTableDocument($tableId, $recNdx)
	{
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [tableid] = %s', $tableId);
		array_push($q, ' AND [recid] = %i', $recNdx);
		array_push($q, ' ORDER BY ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->doOne($r['ndx']);
		}
	}
}
