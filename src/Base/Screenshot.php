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
  var $vpWidth = 2880;
  var $vpHeight = 2160;

	public function run ()
	{
    $this->dstFullFileName = Utils::tmpFileName('png', 'sc');

    $this->scCreator = new \lib\screenshot\SCCreator($this->app());
		$this->scCreator->setUrl($this->url, $this->dstFullFileName);
    $this->scCreator->setViewPort($this->vpWidth, $this->vpHeight);
		$this->scCreator->createSC();

    $convert = $this->app()->testGetParam('convert');

    if ($convert === '1')
    {
      $cmd = "convert {$this->dstFullFileName} -colors 16 -colorspace Gray {$this->dstFullFileName}.pnm";
      exec($cmd);
      $this->dstFullFileName .= '.pnm';
    }

    $rotate = intval($this->app()->testGetParam('rotate'));
    if ($rotate !== 0)
    {
      $cmd = "convert {$this->dstFullFileName} -rotate ".$rotate." {$this->dstFullFileName}_r.png";
      exec($cmd);
      $this->dstFullFileName .= '_r.png';
    }
  }
}
