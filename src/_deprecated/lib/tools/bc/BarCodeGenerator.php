<?php

namespace lib\tools\bc;
use \Shipard\Base\Utility, \Shipard\Utils\Utils;
use \Picqer\Barcode\BarcodeGeneratorSVG;
use BigFish\PDF417\PDF417;
use BigFish\PDF417\Renderers\ImageRenderer;
use BigFish\PDF417\Renderers\SvgRenderer;

/**
 * Class BarCodeGenerator
 */
class BarCodeGenerator extends Utility
{
  var $codeType = 'qr';
	var $textData = '';
	var $fileType = 'svg';
	var $fullFileName = '';
	var $url = '';

	public function createQRCode()
	{
		$cmd = "qrencode -lM -m 0 -t ".strtoupper($this->fileType)." -o \"{$this->fullFileName}\" \"{$this->textData}\"";
		exec ($cmd);
	}

  public function createBarCode()
  {
		$generator = new BarcodeGeneratorSVG();
		$bc = $generator->getBarcode($this->textData, $generator::TYPE_CODE_128);
		file_put_contents($this->fullFileName, $bc);

  }

  public function createPDF417()
  {
    $pdf417 = new PDF417();
    $data = $pdf417->encode($this->textData);

    $renderer = new SvgRenderer([
        'color' => '#000000',
        'bgColor' => '#FFFFFF',
        'scale' => 8,
        'ratio' => 8,
    ]);

    $image = $renderer->render($data);
    file_put_contents($this->fullFileName, $image);
  }

  public function create($codeType)
  {
    $this->codeType = strtoupper($codeType);

		$this->fullFileName = Utils::tmpFileName($this->fileType);
		$this->url = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/tmp/'.basename($this->fullFileName);

    if ($this->codeType === 'QR')
    {
      $this->createQRCode();
      return;
    }
    elseif ($this->codeType === 'PDF417')
    {
      $this->createPDF417();
      return;
    }

		/*
 		const TYPE_CODE_32 = 'C32';
    const TYPE_CODE_39 = 'C39';
    const TYPE_CODE_39_CHECKSUM = 'C39+';
    const TYPE_CODE_39E = 'C39E'; // CODE 39 EXTENDED
    const TYPE_CODE_39E_CHECKSUM = 'C39E+'; // CODE 39 EXTENDED + CHECKSUM
    const TYPE_CODE_93 = 'C93';
    const TYPE_STANDARD_2_5 = 'S25';
    const TYPE_STANDARD_2_5_CHECKSUM = 'S25+';
    const TYPE_INTERLEAVED_2_5 = 'I25';
    const TYPE_INTERLEAVED_2_5_CHECKSUM = 'I25+';
    const TYPE_CODE_128 = 'C128';
    const TYPE_CODE_128_A = 'C128A';
    const TYPE_CODE_128_B = 'C128B';
    const TYPE_CODE_128_C = 'C128C';
    const TYPE_EAN_2 = 'EAN2'; // 2-Digits UPC-Based Extention
    const TYPE_EAN_5 = 'EAN5'; // 5-Digits UPC-Based Extention
    const TYPE_EAN_8 = 'EAN8';
    const TYPE_EAN_13 = 'EAN13';
    const TYPE_UPC_A = 'UPCA';
    const TYPE_UPC_E = 'UPCE';
    const TYPE_MSI = 'MSI'; // MSI (Variation of Plessey code)
    const TYPE_MSI_CHECKSUM = 'MSI+'; // MSI + CHECKSUM (modulo 11)
    const TYPE_POSTNET = 'POSTNET';
    const TYPE_PLANET = 'PLANET';
    const TYPE_RMS4CC = 'RMS4CC'; // RMS4CC (Royal Mail 4-state Customer Code) - CBC (Customer Bar Code)
    const TYPE_KIX = 'KIX'; // KIX (Klant index - Customer index)
    const TYPE_IMB = 'IMB'; // IMB - Intelligent Mail Barcode - Onecode - USPS-B-3200
    const TYPE_CODABAR = 'CODABAR';
    const TYPE_CODE_11 = 'CODE11';
    const TYPE_PHARMA_CODE = 'PHARMA';
    const TYPE_PHARMA_CODE_TWO_TRACKS = 'PHARMA2T';
		*/

    $this->createBarCode();
  }
}
