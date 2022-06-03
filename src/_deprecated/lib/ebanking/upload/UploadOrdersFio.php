<?php

namespace lib\ebanking\upload;
use E10\utils;


/**
 * Class UploadOrdersFio
 * @package lib\ebanking\upload
 */
class UploadOrdersFio extends \lib\ebanking\upload\UploadOrders
{
	public function init ()
	{
		parent::init();
	}

	protected function checkResult ($data)
	{
		$xml = simplexml_load_string($data);
		$results = json_decode (json_encode($xml), TRUE);

		if (isset ($results['result']['errorCode']) && $results['result']['errorCode'] == 0)
			$this->uploadResult = TRUE;
	}

	protected function upload ()
	{
		$uploadUrl = 'https://www.fio.cz/ib_api/rest/import/';
		$post = ['type' => 'abo', 'token' => $this->bankAccountRec['apiTokenUploads'], 'file' => curl_file_create($this->fileNameData, 'text/plain', 'prikaz.abo')];

		$ch = curl_init();
		curl_setopt ($ch, CURLOPT_HEADER, 0);
		curl_setopt ($ch, CURLOPT_VERBOSE, 0);
		curl_setopt ($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $post);
		curl_setopt ($ch, CURLOPT_URL, $uploadUrl);
		curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
		$result = curl_exec ($ch);
		curl_close ($ch);

		$this->fileNameResult = utils::tmpFileName('xml', 'fio-bank-order-upload');
		file_put_contents($this->fileNameResult, $result);

		$this->checkResult($result);
	}

	public function run ()
	{
		if (!isset ($this->bankAccountRec['apiTokenUploads']) || $this->bankAccountRec['apiTokenUploads'] === '')
			return;

		$this->createFiles();
		$this->upload();
		$this->setOrderUploadResult();
	}
}
