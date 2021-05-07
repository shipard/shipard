<?php

namespace lib\nomenclature;

use \e10\Utility;


/**
 * Class ImportNomenclature
 * @package lib\nomenclature
 */
class ImportNomenclature extends Utility
{
	var $destFileName;

	protected function downloadFile ($url, $baseDestFileName)
	{
		$today = new \DateTime();
		$this->destFileName = '/var/lib/e10/tmp/'.$today->format('Y-m-d').'_'.$baseDestFileName;
		if (is_readable($this->destFileName))
			return TRUE;

		$ch = curl_init ();
		curl_setopt ($ch, CURLOPT_URL, $url);

		$fp = fopen ($this->destFileName, 'w+');
		curl_setopt ($ch, CURLOPT_FILE, $fp);

		$res = curl_exec ($ch);

		curl_close ($ch);
		fclose($fp);

		return $res;
	}

	public function run()
	{
	}
}
