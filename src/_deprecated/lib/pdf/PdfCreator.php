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
	var $srcURL = '';

	var $options = [
		'paperOrientation' => 'landscape',
		'paperFormat' => 'A4',
		'paperMargin' => '1cm',
	];
	var $pdfAttachments = [];
	var $filesToAppend = [];
	var $pdfInfo = ['/Producer' => 'shipard.org'];
	var $optsFileName = '';


	public function setReport(\Shipard\Report\Report $report)
	{
		$this->srcFileName = $report->reportSrcFileName;
		$this->srcURL = $report->reportSrcURL;
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
			$cmd = '';

			$nodePath = \is_dir('/usr/lib/node_modules/') ? '/usr/lib/node_modules/' : '/usr/local/lib/node_modules/';

			$cmd .= 'export NODE_PATH='.$nodePath.' && ';

			$cmd .= 'node ';
			$cmd .= __SHPD_ROOT_DIR__.'src/_deprecated/lib/pdf/pdfRenderer.js ';
			$cmd .= $this->optsFileName;
			$cmd .= ' > '.substr($this->srcFileName, 0, -5) . '.log' . ' 2>&1';
			exec($cmd);

			$this->finalize();
		}
		elseif ($fileExt === '.fo')
		{
			exec ("fop -c " . __SHPD_ROOT_DIR__ . "/etc/fop/fop-config-e10.xml $this->srcFileName {$this->dstFileName}");
		}
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

	public function addFileToAppend ($srcFullFileName)
	{
		$this->filesToAppend[] = $srcFullFileName;
	}

	public function finalize()
	{
		$this->appendFiles();

		$cmd = __SHPD_ROOT_DIR__.'src/_deprecated/lib/pdf/pdfFinalizer.py '.$this->optsFileName.' > '.substr($this->srcFileName, 0, -5) . '.fin.log' . ' 2>&1';
		exec($cmd);

		$ffn = $this->dstFileName.'.finalized.pdf';
		if (is_readable($ffn))
		{
			rename($this->dstFileName, $this->dstFileName.'.original.pdf');
			rename($ffn, $this->dstFileName);
		}
	}

	protected function appendFiles()
	{
		if (!count($this->filesToAppend))
			return;

		$ffn = $this->dstFileName.'.beforeAppend.pdf';
		rename($this->dstFileName, $ffn);

		$cmd = 'pdfunite ';
		$cmd .= '"'.$ffn.'" ';

		foreach ($this->filesToAppend as $oneFileName)
		{
			$cmd .= "\"{$oneFileName}\" ";
		}
		$cmd .= "\"{$this->dstFileName}\"";
		exec ($cmd);

		unlink($ffn);
	}
}
