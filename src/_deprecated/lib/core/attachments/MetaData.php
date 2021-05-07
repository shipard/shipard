<?php

namespace lib\core\attachments;
use e10\utility;

/**
 * Class MetaData
 * @package lib\core\attachments
 */
class MetaData extends Utility
{
	CONST
		fkNone = 0,
		fkUnknown = 1,
		fkPdf = 2,
		fkPhoto = 3,
		fkPicture = 4,
		fkText = 5,
		fkWord = 6,
		fkExcel = 7;

	CONST
		mdtTextContent = 1,
		mdtExif = 2;

	var $attRecData = NULL;
	var $attFileName = '';
	var $tmpFileName = '';

	function setAttRecData($attRecData)
	{
		$this->attRecData = $attRecData;
		$this->attFileName = __APP_DIR__.'/att/'.$this->attRecData['path'].$this->attRecData['filename'];
		$this->tmpFileName = __APP_DIR__.'/tmp/'.$this->attRecData['ndx'].'_'.time().'_'.mt_rand(100000000, 999999999);
	}
}

