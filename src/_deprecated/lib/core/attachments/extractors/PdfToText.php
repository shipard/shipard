<?php

namespace lib\core\attachments\extractors;
use \lib\core\attachments\extractors\Base;


/**
 * Class PdfToText
 * @package lib\core\attachments\extractors
 */
class PdfToText extends Base
{
	public function run()
	{
		$this->detectPdfInfo();

		$txtFileName = $this->tmpFileName.'.txt';
		$cmd = 'pdftotext -layout "'.$this->attFileName.'" '.$txtFileName;
		//echo "      -> ".$cmd."\n";
		system($cmd);
		$text = file_get_contents($txtFileName);
		if ($text)
			$text = trim($text, " \t\n\r\0\x0B\f");
		if ($text && $text != '')
		{
			$this->saveData(self::mdtTextContent, $text);

			//$testDocDataMining = intval($this->app()->cfgItem('options.experimental.testDocDataMining', 0));
			if (1 /*$testDocDataMining*/)
			{
				$o = new \e10doc\ddf\ddm\libs\DocsDataMining($this->app());
				$o->init();
				if ($this->attRecData && $this->attRecData['tableid'] === 'wkf.core.issues')
					$o->inboxNdx = $this->attRecData['recid'];
				$o->setFileContent($text, $this->attRecData['ndx']);
				$o->checkFileContent();

				if ($o->ddfId)
				{
					$this->db()->query ('UPDATE [e10_attachments_files] SET ddfId = %i', $o->ddfId, ', ddfNdx = %i', $o->ddfNdx, ' WHERE [ndx] = %i', $this->attRecData['ndx']);
				}
			}
		}
		else
		{ // scanned?
			/* @TODO: remove
			$textPdfFileName = $this->tmpFileName.'.pdf';
			$cmd = "export LC_ALL=C.UTF-8 && export LANG=C.UTF-8 && ocrmypdf -l ces --clean --deskew --remove-background --sidecar {$txtFileName} {$this->attFileName} ".$textPdfFileName.' > '.$txtFileName.'.log 2>&1';
			exec($cmd);
			$text = file_get_contents($txtFileName);
			if ($text && $text != '')
			{
				$this->saveData(self::mdtTextContent, $text);
			}
			*/
		}
	}

	function detectPdfInfo()
	{
		$infoFileName = $this->tmpFileName.'.info';
		$cmd = 'pdfinfo "'.$this->attFileName.'" > '.$infoFileName;
		//echo "      -> ".$cmd."\n";
		system($cmd);
		$infoText = file_get_contents($infoFileName);

		if (!$infoText || $infoText === '')
			return;

		$values = [];

		$rows = explode ("\n", $infoText);
		foreach ($rows as $r)
		{
			$parts = explode (':', $r);
			if ($parts[0] === 'Pages')
				$values['i3'] = intval($parts[1]);
		}

		$this->applyUpdateValues($values);
	}
}

