<?php

namespace lib\core\attachments\extractors;
use \lib\core\attachments\extractors\Base;


/**
 * Class PdfToText
 * @package lib\core\attachments\extractors
 */
class ImageOCR extends Base
{
	public function run()
	{
		$txtFileName = $this->tmpFileName.'.txt';
		$logFileName = $this->tmpFileName.'.log';
		$cmd = 'tesseract "'.$this->attFileName.'" '.$txtFileName.' -l ces > '.$logFileName.' 2>&1';
		//echo "      -> ".$cmd."\n";
		system($cmd);
		$text = file_get_contents($txtFileName.'.txt');
		if ($text)
			$text = trim($text, " \t\n\r\0\x0B\f");

		if ($text && $text != '')
		{
			$this->saveData(self::mdtTextContent, $text);
		}
	}
}

