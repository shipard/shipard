<?php

namespace lib\docs\utils;
use e10\utils, e10\Utility;


/**
 * Class DocEraserCore
 * @package lib\docs\utils
 */
class DocEraserCore extends Utility
{
	var $debug = 0;
	var $docNdx = 0;

	protected function eraseAttachments($tableId, $ndx)
	{
		$q = [];
		array_push ($q, 'SELECT * FROM [e10_attachments_files] ');
		array_push ($q, ' WHERE [tableid] = %s', $tableId, ' AND [recid] = %i', $ndx);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$fileName = __APP_DIR__.'/att/'.$r['path'].'/'.$r['filename'];

			if (is_readable($fileName))
				unlink($fileName);
			if ($this->debug)
			{
				echo ' --> rm '.$fileName."\n";
			}


			$this->db()->query('DELETE FROM [e10_attachments_files] WHERE ndx = %i', $r['ndx']);
			if ($this->debug)
			{
				echo \dibi::$sql."\n";
			}
		}
	}

	protected function eraseClassification($tableId, $ndx)
	{
		$this->db()->query ('DELETE FROM [e10_base_clsf] WHERE [tableid] = %s', $tableId, ' AND [recid] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function eraseDocLinks($tableId, $ndx)
	{
		$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [srcTableId] = %s', $tableId, ' AND [srcRecId] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
		$this->db()->query ('DELETE FROM [e10_base_doclinks] WHERE [dstTableId] = %s', $tableId, ' AND [dstRecId] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}

	protected function eraseHistory($tableId, $ndx)
	{
		$this->db()->query ('DELETE FROM [e10_base_docslog] WHERE [tableid] = %s', $tableId,' AND [recid] = %i', $ndx);
		if ($this->debug)
		{
			echo \dibi::$sql."\n";
		}
	}
}
