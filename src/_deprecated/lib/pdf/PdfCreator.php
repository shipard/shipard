<?php

namespace lib\pdf;
use e10\Utility, e10\utils;


/**
 * Class PdfCreator
 * @package lib\pdf
 */
class PdfCreator extends Utility
{
	var $srcFileName = '';
	var $dstFileName = '';

	var $renderViaChrome = 0;

	var $options = [
		'paperOrientation' => 'landscape',
		'paperFormat' => 'A4',
		'paperMargin' => '1cm',
	];
	var $pdfAttachments = [];
	var $pdfInfo = ['/Producer' => 'shipard.cz'];
	var $optsFileName = '';


	public function setReport(\Shipard\Report\Report $report)
	{
		$this->renderViaChrome = 1;//intval($this->app()->cfgItem('options.experimental.testNewPdfRender', 0));

		$this->srcFileName = $report->reportSrcFileName;
		$this->dstFileName = $report->fullFileName;

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

	public function createPdf()
	{
		$this->createPdfCore();
	}

	protected function createPdfCore()
	{
		$fileExt = strrchr($this->srcFileName, '.');
		$this->saveParamsForBrowser();

		if ($fileExt === '.html')
		{
			if ($this->renderViaChrome)
			{
				$cmd = '';

				$cmd .= 'export NODE_PATH=/usr/local/lib/node_modules && ';

				$cmd .= 'node ';
				$cmd .= __SHPD_ROOT_DIR__.'src/_deprecated/lib/pdf/pdfRenderer.js ';
				$cmd .= $this->optsFileName;
				$cmd .= ' > '.substr($this->srcFileName, 0, -5) . '.log' . ' 2>&1';
				exec($cmd);

				$this->finalize();
			}
			else
			{
				exec ('phantomjs '.__APP_DIR__ . '/e10-modules/e10/server/utils/createpdf.js '.$this->srcFileName.' '.
					$this->dstFileName.' '.$this->options['paperFormat'].' '.$this->options['paperOrientation'].' '.$this->options['paperMargin']);
			}

			$this->finalize();
		}
		elseif ($fileExt === '.fo')
		{
			exec ("fop -c " . __APP_DIR__ . "/e10-modules/e10/server/etc/fop/fop-config-e10.xml $this->srcFileName {$this->dstFileName}");
		}
	}

	protected function saveParamsForBrowser()
	{
		$opts = [
			'url' => 'file://'.$this->srcFileName,
			'dstFileName' => $this->dstFileName,
			'pdfOptions' => $this->options,
			'pdfAttachments' => $this->pdfAttachments,
			'pdfInfo' => $this->pdfInfo,
		];

		if ($this->renderViaChrome)
		{
			if (PHP_OS === 'Darwin')
			{
				$opts['browserExecutablePath'] = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
			}
			else
			{
				$browserStatus = utils::loadCfgFile('/run/shpd-headless-browser.json');
				if ($browserStatus && isset($browserStatus['webSocketDebuggerUrl']))
					$opts['wsEndpointUrl'] = $browserStatus['webSocketDebuggerUrl'];
				$opts['browserExecutablePath'] = '/usr/bin/google-chrome';
			}
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
		$cmd = __SHPD_ROOT_DIR__.'src/_deprecated/lib/pdf/pdfFinalizer.py '.$this->optsFileName.' > '.substr($this->srcFileName, 0, -5) . '.fin.log' . ' 2>&1';
		exec($cmd);

		$ffn = $this->dstFileName.'.finalized.pdf';
		if (is_readable($ffn))
		{
			rename($this->dstFileName, $this->dstFileName.'.original.pdf');
			rename($ffn, $this->dstFileName);
		}
	}
}
