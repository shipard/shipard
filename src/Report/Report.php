<?php

namespace Shipard\Report;
use \Shipard\Utils\Utils;



function http_post_file ($url, $fileName)
{
	$data = file_get_contents ($fileName);
	$data_len = filesize ($fileName);

	return array ('content'=>file_get_contents ($url, false, stream_context_create (array ('http'=>array ('method'=>'POST'
					, 'header'=>"Content-type: application/octet-stream\r\nConnection: close\r\nContent-Length: $data_len\r\n"
					, 'content'=>$data, 'timeout' => 10
					))))
			, 'headers'=>$http_response_header
			);
}




class Report extends \Shipard\Base\BaseObject
{
	const rmDefault = 0, rmPOS = 1, rmLabels = 2;

	public $fullFileName;
	public $saveFileName;
	public $mimeType;
	public $srcFileExtension;
	var $reportSrcFileName = '';
	var $reportSrcFileNameRelative = '';
	var $reportSrcURL = '';
	public $format;
	public $objectData = [];
	public $ok = false;
	public $data = [];
	public $content = [];
	public $lang = FALSE;

	var $pageHeader = '';
	var $pageFooter = '';

	var $reportMode = self::rmDefault;

	var $rasterPrint = 0;
	var $rasterPrintRawDataFileName = '';

	var $printer = NULL;
	var $printerDriver = NULL;
	public $openCashdrawer = FALSE;

	public $reportId;
	public $reportTemplate;

	public $paperFormat = 'A4';
	public $paperOrientation = 'portrait';
	public $paperMargin = '1cm';

	var $outboxLinkId = '';
	var $sendReportNdx = 0;

	protected $registeredImages = [];

	public $info = [];

	public $saveAs = FALSE;
	public $mobile = FALSE;

	public function __construct ($app = NULL)
	{
		parent::__construct($app);

		$this->format = $this->app->requestPath (3);
		$this->srcFileExtension = 'html';
	}

	public function init ()
	{
		$printerNdx = intval($this->app()->testGetParam('printer'));
		if ($printerNdx)
		{
			$this->setPrinter($printerNdx);
		}
	}

	function setPrinter($printerNdx)
	{
		$printer = $this->app->cfgItem('e10.terminals.printers.'.$printerNdx, FALSE);
		if ($printer)
		{
			$this->printer = $printer;

			$this->data['printerType'] = 'normal';
			if (isset($printer['receiptsPrinterType']))
				$this->data['printerType'] = $printer['receiptsPrinterType'];

			$this->loadPosPrinterDriver();
		}
	}

	public function app() {return $this->app;}

	protected function addAttachment ($downloadFileName, $fileExt, $data, $options = NULL)
	{
		$tmpFileName = '/tmp/ratt-' . time() . '-' . mt_rand (1000000, 999999999) . '.' . $fileExt;
		$tmpFullFileName = __APP_DIR__ . $tmpFileName;

		if (is_string($data))
		{
			file_put_contents ($tmpFullFileName, $data);
		}

		$attInfo = array ('downloadFileName' => $downloadFileName.'.'.$fileExt, 'fullFileName' => $tmpFullFileName, 'relFileName' => $tmpFileName);
		$this->objectData['attachments'][] = $attInfo;
	}

	protected function createAttachments ($mainTemplate)
	{
		$this->objectData['attachments'] = array ();
	}

	public function createReport ()
	{
		$this->createReportHeaderFooter();

		if ($this->saveAs !== FALSE)
		{
			$this->saveReportAs ();
		}
		elseif ($this->mimeType == 'application/pdf')
		{
			$this->reportSrcFileNameRelative = "tmp/rpdf-" . time() . '-' . mt_rand () . '.' . $this->srcFileExtension;
			$this->reportSrcFileName = __APP_DIR__ . '/' . $this->reportSrcFileNameRelative;
			$this->reportSrcURL = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/'. $this->reportSrcFileNameRelative;
			file_put_contents($this->reportSrcFileName, $this->objectData ['mainCode']);

			$this->fullFileName = substr($this->reportSrcFileName, 0, -(strlen($this->srcFileExtension) + 1)) . '.pdf';
			$pdfCreator = new \lib\pdf\PdfCreator($this->app());
			$pdfCreator->setReport($this);
			$this->addAttachments($pdfCreator);
			$this->addFilesToAppend($pdfCreator);

			$ownerName = $this->app->cfgItem ('options.core.ownerFullName', '');
			if ($ownerName !== '')
				$pdfCreator->setPdfInfo('Author', $ownerName);

			$pdfCreator->createPdf();
		}
		elseif ($this->rasterPrint)
		{
			$this->reportSrcFileNameRelative = "tmp/rrp-" . time() . '-' . mt_rand () . '.' . $this->srcFileExtension;
			$this->reportSrcFileName = __APP_DIR__ . '/' . $this->reportSrcFileNameRelative;
			$this->reportSrcURL = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/'. $this->reportSrcFileNameRelative;
			file_put_contents($this->reportSrcFileName, $this->objectData ['mainCode']);

			$this->fullFileName = substr($this->reportSrcFileName, 0, -(strlen($this->srcFileExtension) + 1)) . '.png';
			$rpiCreator = new \lib\rasterPrint\RPICreator($this->app());
			$rpiCreator->setReport($this);

			$rpiCreator->createImage();

			$this->rasterPrintRawDataFileName = $rpiCreator->dstFileNameRaw;
		}
		else
		{
			$this->fullFileName = __APP_DIR__ . "/tmp/r-" . time() . '-' . mt_rand () . '.' . $this->srcFileExtension;
			file_put_contents($this->fullFileName, $this->objectData ['mainCode']);

			switch ($this->srcFileExtension)
			{
				case 'rawprint':
								{
									$printCfg = [];
									$this->printReport($printCfg, 1, $this->app->testGetParam('printer'));
								}
								break;
			}
		}
	}

	protected function createReportHeaderFooter()
	{
	}

	public function addAttachments(\lib\pdf\PdfCreator $pdfCreator)
	{
	}

	public function addFilesToAppend(\lib\pdf\PdfCreator $pdfCreator)
	{
	}

	public function createToolbarSaveAs (&$printButton){}
	public function saveReportAs (){}

	public function createToolbarCode ()
	{
		return '';
	}

	public function renderReport ()
	{
		$this->objectData ['mainCode'] = $this->renderTemplate ($this->reportTemplate);
		$this->mimeType = 'application/pdf';
	}

	public function renderTemplate ($templateName, $partId = 'page')
	{
		$this->info['reportFormat']['pdf'] = TRUE;
		$t = new TemplateMustache ($this->app);
		if (!$t->loadTemplate($templateName, $partId.'.mustache'))
			return '';


		$optionsFullFileName = $t->templateRoot . 'options.json';
		if (is_file($optionsFullFileName))
		{
			$this->info['reportOptions'] = Utils::loadCfgFile($optionsFullFileName);
			if (!$this->info['reportOptions'])
				unset($this->info['reportOptions']);
		}


		$t->setData ($this);

		if (isset($this->data ['_subtemplatesItems']))
		{
			foreach ($this->data ['_subtemplatesItems'] as $stId)
			{
				foreach ($t->data [$stId] as $textId => $textData)
				{
					if ($textId === 'emailBody' || $textId === 'emailSubject')
						$t->data [$stId][$textId] = $t->render($textData);
					else
						$t->data [$stId][$textId] = trim($t->render($textData));
				}
			}
		}
		if (isset($this->data ['_textRenderItems']))
		{
			$texy = new \Texy();
			//$texy->allowed['link/url'] = FALSE;
			$texy->allowed['link/email'] = FALSE;

			foreach ($this->data ['_subtemplatesItems'] as $stId)
			{
				foreach ($t->data [$stId] as $textId => $textData)
				{
					if ($textId === 'emailBody' || $textId === 'emailSubject')
						continue;
					if ($t->data [$stId][$textId] !== '')
						$t->data [$stId][$textId] = $texy->processLine($t->data [$stId][$textId]);
				}
			}
		}

		$this->createAttachments ($t);
		$res = $t->renderTemplate ();
		return $res;
	}

	public function createReportPart ($partId)
	{
		return $this->renderTemplate ($this->reportTemplate, $partId);
	}

	public function setCfg ($printCfg)
	{
		$this->data['printMode'] = '1';
		if (isset($printCfg['printMode']))
			$this->data['printMode'] = $printCfg['printMode'];

		$this->data['printerType'] = 'normal';
		if (isset($printCfg['printerType']))
			$this->data['printerType'] = $printCfg['printerType'];

		if ($this->data['printMode'] == '1' && isset($printCfg['printerNdx']))
		{
			/*
			$printer = $this->app->cfgItem('e10.terminals.printers.'.$printCfg['printerNdx'], FALSE);
			if ($printer)
			{
				$this->printer = $printer;

				$this->data['printerType'] = 'normal';
				if (isset($printer['receiptsPrinterType']))
					$this->data['printerType'] = $printer['receiptsPrinterType'];

				$this->loadPosPrinterDriver();
			}
			*/

			$this->setPrinter($printCfg['printerNdx']);
		}

		if ($this->data['printerType'] === 'thin')
			$this->data['printerTypeThin'] = 1;
		else
			$this->data['printerTypeNormal'] = 1;
	}

	function loadPosPrinterDriver()
	{
		$printerDriverId = 'generic-escp';
		if (isset($this->printer['posPrinterDriver']) && $this->printer['posPrinterDriver'] !== '')
			$printerDriverId = $this->printer['posPrinterDriver'];

		$printerDriverCfg = $this->app()->cfgItem('terminals.postPrinterDrivers.'.$printerDriverId, NULL);
		if (!$printerDriverCfg)
			return;

		$pdfn = __SHPD_MODULES_DIR__ . $printerDriverCfg['driver'];
		$this->printerDriver = Utils::loadCfgFile($pdfn);
		if (!$this->printerDriver)
		{
			$this->printerDriver = NULL;
			return;
		}

		foreach ($this->printerDriver['commands'] as $commandId => $commandChars)
		{
			$cmdChars = '';
			if ($commandChars)
			{
				foreach ($commandChars as $cc)
				{
					if (is_string($cc))
						$cmdChars .= $cc;
					else
						$cmdChars .= chr($cc);
				}
			}
			$this->printerDriver['rawCmd'][$commandId] = $cmdChars;
		}

		if (!isset($this->printerDriver['htmlFormatCodes']))
			return;

		$this->printerDriver['htmlCmd'] = [];

		foreach ($this->printerDriver['htmlFormatCodes'] as $htmlTag => $printerCommand)
		{
			if (isset($this->printerDriver['rawCmd'][$printerCommand]))
				$this->printerDriver['htmlCmd'][$htmlTag] = $this->printerDriver['rawCmd'][$printerCommand];
		}
	}

	public function printReport (&$printCfg, $copies = 1, $printerNdx = '')
	{
		if (isset($this->data['printMode']) && $this->data['printMode'] == '2')
		{ // -- local print on device
			if ($this->reportMode == FormReport::rmPOS || $this->reportMode == FormReport::rmLabels)
			{
				$printCfg['posReports'][] = bin2hex($this->objectData ['mainCode']);
			}

			return;
		}

		if ($printerNdx === '' || $printerNdx == 0)
		{
			error_log ("ERROR: printer not defined `$printerNdx`...");
			return;
		}
		$printer = $this->app->cfgItem('e10.terminals.printers.'.$printerNdx, FALSE);
		if (!$printer)
		{
			error_log ("ERROR: printer `$printerNdx` not found...");
			return;
		}
		if ($printer['printMethod'] == '0')
		{
			$remotePrinter = $printer['printURL'];

			$url = $remotePrinter . "?printer={$printer['networkQueueId']}&copies=$copies";
			if ($this->openCashdrawer !== FALSE)
				$url .= '&openCashdrawer=' . $this->openCashdrawer;

			http_post_file($url, $this->fullFileName);
		}
		elseif ($printer['printMethod'] == '5')
		{
			$port = 9100;
			$parts = explode (':', $printer['printerAddress']);
			$addr = (isset($parts[0])) ? $parts[0] : '';
			if (isset($parts[1]))
				$port = intval($parts[1]);

			$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			$result = socket_connect($socket, $addr, $port);
			if ($result === false)
			{
				error_log("PRINT ERROR: socket_connect() to `{$addr}:{$port}` failed.\nReason: ($result) " . socket_strerror(socket_last_error($socket)));
			}

			if ($this->reportMode == FormReport::rmPOS || $this->reportMode == FormReport::rmLabels)
			{
				if ($this->rasterPrintRawDataFileName !== '')
				{
					$rpData = file_get_contents($this->rasterPrintRawDataFileName);
					$tst = socket_write($socket, $rpData, strlen($rpData));
				}
				else
				{
					$tst = socket_write($socket, $this->objectData ['mainCode'], strlen($this->objectData ['mainCode']));
				}
			}
			else
			{

			}

			socket_close($socket);
		}
	}

	public function setOutsideParam ($param, $value)
	{
	}

	public function registerImage ($src, $dest)
	{
		$this->registeredImages[] = ['src' => $src, 'dest' => $dest];
	}

	public function setCode ($main, $format = 'html')
	{
		$this->objectData ['mainCode'] = $main;
	}

	public function setInfo ($infoId, $p1, $p2 = '')
	{
		if ($p2 === '')
			$this->info [$infoId] = $p1;
		else
			$this->info [$infoId][$p1] = $p2;
	}

	public function setSaveFileName ($saveFileName, $mimeType = 'application/pdf')
	{
		$this->saveFileName = Utils::safeChars($saveFileName, TRUE);
		$this->mimeType = $mimeType;
	}

	public function loadData ()
	{
	}

	public function loadData2 ()
	{
	}

	public function reportWasSent(\Shipard\Report\MailMessage $msg)
	{
	}
}

