<?php

namespace lib\screenshot;
use e10\Utility, e10\utils;


/**
 * Class SCCreator
 */
class SCCreator extends Utility
{
	var $dstFileName = '';
	var $dstFileNameInfo = '';
	var $srcURL = '';

	var $options = [
		'paperOrientation' => 'landscape',
		'paperFormat' => 'A4',
		'paperMargin' => '1cm',
	];
	var $optsFileName = '';

	public function setUrl($srcUrl, $dstFileName)
	{
		$this->srcURL = $srcUrl;
		$this->dstFileName = $dstFileName;
	}

	public function setViewPort($vpWidth, $vpHeight)
	{
		$this->options['vpWidth'] = intval($vpWidth);
		$this->options['vpHeight'] = intval($vpHeight);
	}

	public function createSC()
	{
		$this->createSCCore();
	}

	protected function createSCCore()
	{
		$this->saveParamsForBrowser();

		$cmd = '';

		$nodePath = \is_dir('/usr/lib/node_modules/') ? '/usr/lib/node_modules/' : '/usr/local/lib/node_modules/';

		$cmd .= 'export NODE_PATH='.$nodePath.' && ';

		$cmd .= 'node ';
		$cmd .= __SHPD_ROOT_DIR__.'src/_deprecated/lib/screenshot/scRenderer.js ';
		$cmd .= $this->optsFileName;
		$cmd .= ' > '.substr($this->dstFileName, 0, -5) . '.log' . ' 2>&1';
		exec($cmd);
	}

	protected function saveParamsForBrowser()
	{
		$this->dstFileNameInfo = substr($this->dstFileName, 0, -4).'-info.json';

		$opts = [
			'url' => $this->srcURL,
			'dstFileName' => $this->dstFileName,
			'dstFileNameInfo' => $this->dstFileNameInfo,
			'scOptions' => $this->options,
		];

		if (PHP_OS === 'Darwin')
		{
			$opts['browserExecutablePath'] = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
		}
		else
		{
			$browserStatus = utils::loadCfgFile('/var/lib/shipard/shpd/shpd-headless-browser.json');
			if ($browserStatus && isset($browserStatus['webSocketDebuggerUrl']))
				$opts['wsEndpointUrl'] = $browserStatus['webSocketDebuggerUrl'];
			$opts['browserExecutablePath'] = '/usr/bin/google-chrome';
		}

		$this->optsFileName = substr($this->dstFileName, 0, -3).'json';
		file_put_contents($this->optsFileName, json_encode($opts));
	}

	public function finalize()
	{
	}
}
