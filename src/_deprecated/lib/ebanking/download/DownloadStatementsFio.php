<?php

namespace lib\ebanking\download;

use E10\utils, E10\Utility;


/**
 * Class DownloadStatementsFio
 * @package lib\ebanking\download
 *
 * @url https://www.fio.cz/docs/cz/API_Bankovnictvi.pdf
 *
 */
class DownloadStatementsFio extends \lib\ebanking\download\DownloadStatements
{
	protected $filesDownloaded = FALSE;

	protected function downloadFiles ()
	{
		$this->downloadOneStatement();

		if ($this->filesDownloaded)
			return;

		$today = new \DateTime();
		$todayYear = intval($today->format('Y'));
		$todayMonth = intval($today->format('m'));
		$todayDay = intval($today->format('d'));

		if ($todayMonth === 1 && $todayDay > 1 && $todayYear === ($this->nextStatementYear + 1))
		{ // try download first statement in new year
			$this->nextStatementYear++;
			$this->nextStatementNumber = 1;
			sleep(33); // wait before next download
			$this->downloadOneStatement();
		}
	}

	protected function downloadOneStatement ()
	{
		// https://www.fio.cz/ib_api/rest/by-id/API-TOKEN/YEAR/STATEMENT-ORDER-NUMBER/transactions.pdf
		$url = 'https://www.fio.cz/ib_api/rest/by-id/'.$this->bankAccountRec['apiToken'].'/'.
			$this->nextStatementYear.'/'.$this->nextStatementNumber.'/'.'transactions';

		$tmpFileName = __APP_DIR__.'/tmp/'.'vypis-B'.$this->bankAccountCfg['id'].'-'.$this->nextStatementYear.'-'.$this->nextStatementNumber.'-'.time();

		$urlPdf = $url . '.pdf';
		$filePdf = $tmpFileName . '.pdf';
		if ($this->app()->debug)
			echo "Download PDF statement - contact FIO API: ".$urlPdf."\n";
		$data = file_get_contents($urlPdf);
		$responseHeaders = $http_response_header ?? [];
		if ($this->app()->debug)
		{
			echo ("response data: ".json_encode($data))."\n";
			echo ("response headers: ".json_encode($responseHeaders))."\n";
		}

		if ($data === FALSE)
			return;
		file_put_contents($filePdf, $data);

		sleep(33); // minimal pause between requests is 30 seconds

		$testImportFioJson = $this->app()->cfgItem ('options.experimental.testImportFioJson', 0);
		if ($testImportFioJson)
		{
			$urlData = $url . '.json';
			$fileData = $tmpFileName . '.json.txt';
		}
		else
		{
			$urlData = $url . '.gpc';
			$fileData = $tmpFileName . '.gpc';
		}
		$data = file_get_contents($urlData);
		if ($data === FALSE)
			return;
		file_put_contents($fileData, $data);

		$this->statementTextData = $data;

		$this->inboxRecData ['subject'] = 'Bankovní výpis '.$this->bankAccountCfg['bankAccount'];
		$this->addInboxAttachment ($filePdf);
		$this->addInboxAttachment ($fileData);

		$this->saveToInbox();

		$this->filesDownloaded = TRUE;
	}


	public function run ()
	{
		if (!$this->downloadEnabled)
			return;

		if (!isset($this->bankAccountRec['apiToken']) || $this->bankAccountRec['apiToken'] === '')
			return;

		$this->downloadFiles();
		$this->createBankDocument ();
	}
}
