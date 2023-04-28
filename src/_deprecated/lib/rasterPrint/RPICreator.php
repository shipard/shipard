<?php

namespace lib\rasterPrint;
use e10\Utility, e10\utils;


/**
 * Class RPICreator
 */
class RPICreator extends Utility
{
	var $srcFileName = '';
	var $dstFileName = '';
	var $dstFileNameRaw = '';
	var $srcURL = '';

	var $options = [
		'paperOrientation' => 'landscape',
		'paperFormat' => 'A4',
		'paperMargin' => '1cm',
	];
	var $pdfAttachments = [];
	var $pdfInfo = ['/Producer' => 'shipard.org'];
	var $optsFileName = '';

	var $printerDriverCfg = NULL;
	var $labelsCfg = NULL;


	public function setReport(\Shipard\Report\Report $report)
	{
		$this->srcFileName = $report->reportSrcFileName;
		$this->srcURL = $report->reportSrcURL;
		$this->dstFileName = $report->fullFileName;

		if ($report->printer && $report->printer['labelsType'] && $report->printer['labelsType'] !== '')
		{
			$tablePrinters = new \terminals\base\TablePrinters($this->app());
			$this->printerDriverCfg = $tablePrinters->loadPrinterDriverCfg($report->printer['posPrinterDriver']);
			if ($this->printerDriverCfg && isset($this->printerDriverCfg['labels']))
			{
				$this->labelsCfg = $this->printerDriverCfg['labels'][$report->printer['labelsType']] ?? NULL;
				if ($this->labelsCfg)
				{
					$this->options['vpWidth'] = $this->labelsCfg['pxWidth'] ?? 696;
					$this->options['vpHeight'] = $this->labelsCfg['pxHeight'] ?? 1;
				}
			}
		}

		$this->options['paperOrientation'] = $report->paperOrientation;
		$this->options['paperFormat'] = $report->paperFormat;
		$this->options['paperMarginLeft'] = $report->paperMargin;
		$this->options['paperMarginRight'] = $report->paperMargin;
		$this->options['paperMarginTop'] = $report->paperMargin;
		$this->options['paperMarginBottom'] = $report->paperMargin;

		if (isset($report->info['reportOptions']))
		{
			if (isset($report->info['reportOptions']['marginLeft']))
				$this->options['paperMarginLeft'] = $report->info['reportOptions']['marginLeft'];
			if (isset($report->info['reportOptions']['marginRight']))
				$this->options['paperMarginRight'] = $report->info['reportOptions']['marginRight'];
			if (isset($report->info['reportOptions']['marginTop']))
				$this->options['paperMarginTop'] = $report->info['reportOptions']['marginTop'];
			if (isset($report->info['reportOptions']['marginBottom']))
				$this->options['paperMarginBottom'] = $report->info['reportOptions']['marginBottom'];
		}

		if ($report->pageHeader !== '' || $report->pageFooter !== '')
			$this->options['headerTemplate'] = $report->pageHeader;
		if ($report->pageHeader !== '' || $report->pageFooter !== '')
			$this->options['footerTemplate'] = $report->pageFooter;
	}

	public function setUrl($srcFileName, $srcUrl, $dstFileName)
	{
		$this->srcFileName = $srcFileName;
		$this->srcURL = $srcUrl;
		$this->dstFileName = $dstFileName;
		$this->options['paperOrientation'] = 'portrait';
		$this->options['paperMarginLeft'] = '1.6cm';
		$this->options['paperMarginRight'] = '1.6cm';
		$this->options['paperMarginTop'] = '2cm';
		$this->options['paperMarginBottom'] = '1.6cm';
	}

	public function createImage()
	{
		$this->createImageCore();
	}

	protected function createImageCore()
	{
		$this->saveParamsForBrowser();

		$cmd = '';

		$nodePath = \is_dir('/usr/lib/node_modules/') ? '/usr/lib/node_modules/' : '/usr/local/lib/node_modules/';

		$cmd .= 'export NODE_PATH='.$nodePath.' && ';

		$cmd .= 'node ';
		$cmd .= __SHPD_ROOT_DIR__.'src/_deprecated/lib/rasterPrint/rpiRenderer.js ';
		$cmd .= $this->optsFileName;
		$cmd .= ' > '.substr($this->srcFileName, 0, -5) . '.log' . ' 2>&1';
		exec($cmd);

		$this->finalize();
	}

	protected function saveParamsForBrowser()
	{
		$opts = [
			'url' => $this->srcURL,
			'dstFileName' => $this->dstFileName,
			'pdfOptions' => $this->options,
			'pdfAttachments' => $this->pdfAttachments,
			'pdfInfo' => $this->pdfInfo,
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

	public function setPdfInfo($key, $value)
	{
		$this->pdfInfo['/'.$key] = $value;
	}

	public function addAttachment ($srcFileName, $attFileName)
	{
		$a = ['srcFileName' => $srcFileName, 'attFileName' => $attFileName];
		$this->pdfAttachments[] = $a;
	}

	public function finalize()
	{
		if (!$this->printerDriverCfg || !isset($this->printerDriverCfg['rasterPrintEngine']) || $this->printerDriverCfg['rasterPrintEngine'] === '')
		{
			return;
		}

		/** @var \lib\rasterPrint\RasterPrinterEngine */
		$e = $this->app()->createObject($this->printerDriverCfg['rasterPrintEngine']);
		if (!$e)
		{
			return;
		}

		$this->dstFileNameRaw = substr($this->dstFileName, 0, -3).'bin';

		$e->setCfg($this->printerDriverCfg, $this->labelsCfg);
		$e->createPrinterData($this->dstFileName, $this->dstFileNameRaw);
	}
}
