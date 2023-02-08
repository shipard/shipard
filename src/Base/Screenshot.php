<?php

namespace Shipard\Base;
use \Shipard\Utils\Utils;
use \Shipard\Base\Utility;


/**
 * class Screenshot
 */
class Screenshot extends Utility
{
	var \lib\screenshot\SCCreator $scCreator;
  var $dstFullFileName;
  var $url = '';

	public function run ()
	{
    $this->dstFullFileName = Utils::tmpFileName('png', 'sc');

    $this->scCreator = new \lib\screenshot\SCCreator($this->app());
		$this->scCreator->setUrl($this->url, $this->dstFullFileName);
		$this->scCreator->createSC();

    $convert = $this->app()->testGetParam('convert');
    if ($convert === '1')
    {
      $cmd = "convert {$this->dstFullFileName} -colors 16  -colorspace Gray {$this->dstFullFileName}.pnm";
      exec($cmd);
      $this->dstFullFileName .= '.pnm';
    }
	}
}
