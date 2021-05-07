<?php

namespace lib\core\attachments;
use \lib\core\attachments\MetaData;


/**
 * Class Extract
 * @package lib\core\attachments
 */
class Extract extends MetaData
{
	var $extractConfig = NULL;
	var $attNdx = 0;
	var $attTableId = NULL;
	var $attRecId = 0;
	var $scanAllCount = 20;

	public function setAttNdx ($attNdx)
	{
		$this->attNdx = $attNdx;
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE ndx = %i', $this->attNdx);

		$exist = $this->db()->query($q)->fetch();
		if ($exist)
		{
			$this->setAttRecData($exist->toArray());
		}
		else
		{
			//echo "!!! Attachment `{$attNdx}` no found!\n";
		}
	}

	public function setAttTableDocument ($attTableId, $attRecId)
	{
		$this->attTableId = $attTableId;
		$this->attRecId = $attRecId;
	}

	public function fileKind($ext)
	{
		$fk = self::fkNone;

		$ext = strtolower($ext);

		switch ($ext)
		{
			case 'pdf': $fk = self::fkPdf; break;

			case 'jpg':
			case 'jpeg': $fk = self::fkPhoto; break;

			case 'eps':
			case 'svg':
			case 'tiff':
			case 'png': $fk = self::fkPicture; break;

			case 'doc':
			case 'docx': $fk = self::fkWord; break;

			default: $fk = self::fkUnknown; break;
		}

		return $fk;
	}

	function detectFileType($ext = NULL)
	{
		$fk = $this->fileKind($this->attRecData['filetype']);

		if ($fk === self::fkUnknown)
		{
			$mt = strtolower(mime_content_type($this->attFileName));
			if (substr($mt, 0, 5) === 'text/')
			{
				$fk = self::fkText;
			}
		}

		if ($fk !== self::fkNone)
		{
			$this->attRecData['fileKind'] = $fk;
			$this->db()->query ('UPDATE [e10_attachments_files] SET fileKind = %i', $fk, ' WHERE [ndx] = %i', $this->attRecData['ndx']);
			return TRUE;
		}

		return FALSE;
	}

	function confirmExtract()
	{
		$version = $this->extractConfig['version'];
		$this->db()->query ('UPDATE [e10_attachments_files] SET mddVersion = %i', $version, ' WHERE [ndx] = %i', $this->attRecData['ndx']);
	}

	function extract ($extractCfg)
	{
		if (isset($extractCfg['extractors']))
		{
			foreach ($extractCfg['extractors'] as $eid)
			{
				$classId = 'lib.core.attachments.extractors.' . strtoupper(substr($eid, 0, 1)) . substr($eid, 1);
				//echo "    --> $classId \n";

				/** @var \lib\core\attachments\extractors\Base $e */
				$e = $this->app()->createObject($classId);
				if (!$e)
				{
					//echo "create object failed \n";
					continue;
				}

				$e->setAttRecData($this->attRecData);
				$e->run();
			}
		}

		$this->confirmExtract();
	}

	function scanOne()
	{
		if ($this->attRecData['fileKind'] == 0)
		{
			if (!$this->detectFileType())
				return;
		}

		$extract = \e10\searchArray($this->extractConfig['fileKinds'], 'ndx', $this->attRecData['fileKind']);
		if (!$extract)
		{
			//echo "   !!! unknown extract cfg data\n";
			return;
		}

		$this->extract($extract);
	}

	function scanAll()
	{
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND mddVersion = %i', 0);
		array_push($q, ' ORDER BY ndx DESC');
		array_push($q, ' LIMIT 0, %i', $this->scanAllCount);

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->setAttRecData($r->toArray());
			$this->scanOne();
		}
	}

	function scanTableDocument()
	{
		$q[] = 'SELECT * FROM [e10_attachments_files]';
		array_push($q, ' WHERE 1');
		array_push($q, ' AND [tableid] = %s', $this->attTableId);
		array_push($q, ' AND [recid] = %i', $this->attRecId);
		array_push($q, ' ORDER BY ndx');

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$this->setAttRecData($r->toArray());
			$this->scanOne();
		}
	}

	public function run()
	{
		$this->extractConfig = $this->loadCfgFile(__APP_DIR__.'/e10-modules/lib/core/attachments/config/extract.json');
		if ($this->attNdx)
			$this->scanOne();
		elseif ($this->attTableId !== NULL)
			$this->scanTableDocument();
		else
			$this->scanAll();
	}
}

