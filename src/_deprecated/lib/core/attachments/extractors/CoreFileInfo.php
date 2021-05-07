<?php

namespace lib\core\attachments\extractors;
use \lib\core\attachments\extractors\Base;


/**
 * Class CoreFileInfo
 * @package lib\core\attachments\extractors
 */
class CoreFileInfo extends Base
{
	public function run()
	{
		$values = [];

		// -- file size
		if ($this->attRecData['fileSize'] === 0)
		{
			$fileSize = filesize($this->attFileName);
			if ($fileSize !== FALSE)
				$values['fileSize'] = $fileSize;
		}

		// -- check sum
		if ($this->attRecData['fileCheckSum'] === '')
		{
			$fileCheckSum = sha1_file($this->attFileName);
			if ($fileCheckSum !== FALSE)
				$values['fileCheckSum'] = $fileCheckSum;
		}

		// -- save values
		$this->applyUpdateValues($values);
	}
}

