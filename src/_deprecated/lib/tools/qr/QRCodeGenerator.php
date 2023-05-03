<?php

namespace lib\tools\qr;
use \E10\Utility, \e10\utils;


/**
 * Class QRCodeGenerator
 * @package lib\tools\qr
 */
class QRCodeGenerator extends Utility
{
	var $textData = '';
	var $fileType = 'svg';
	var $fullFileName = '';
	var $url = '';

	public function createQRCode ()
	{
		$this->fullFileName = utils::tmpFileName($this->fileType);
		$this->url = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/tmp/'.basename($this->fullFileName);

		if (is_file($this->fullFileName))
			return;

		$cmd = "qrencode -lM -t ".strtoupper($this->fileType)." -o \"{$this->fullFileName}\" \"{$this->textData}\"";
		exec ($cmd);
	}
}
