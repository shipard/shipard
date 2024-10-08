<?php

namespace e10pro\bcards\libs;
use \Shipard\Utils\Utils;
use \e10\base\libs\UtilsBase;


/**
 * class BCardEngine
 */
class BCardEngine extends \Shipard\Base\Utility
{
  var $bcardNdx = 0;
  var $bcardRecData = NULL;

  var $orgNdx = 0;
  var $orgRecData = NULL;

  var $webTemplateNdx = 0;
  var $webTemplateRecData = NULL;
  var $webTemplateCodeCss = '';
  var $webTemplateCodeHtml = 'TEMPLATE NOT FOUND';
  var $webCardHtml = '';

  var $bcardData = [];
  var $vcardCode = '';

  var \Shipard\Utils\TemplateCore $template;


  public function setBCard($bcardNdx)
  {
    $this->bcardNdx = $bcardNdx;
    $this->bcardRecData = $this->app()->loadItem($bcardNdx, 'e10pro.bcards.cards');
    if (!$this->bcardRecData)
      return;

    $this->orgNdx = intval($this->bcardRecData['org']);
    $this->orgRecData = $this->app()->loadItem($this->orgNdx, 'e10pro.bcards.orgs');
  }

  protected function loadWebTemplate()
  {
    if (!$this->bcardRecData || !$this->orgRecData)
      return;

    $this->webTemplateNdx = $this->orgRecData['webTemplate'];
    $this->webTemplateRecData = $this->app()->loadItem($this->webTemplateNdx, 'e10pro.bcards.webTemplates');
    if (!$this->webTemplateRecData)
      return;

    $this->webTemplateCodeCss = $this->webTemplateRecData['codeStyle'];
    $this->webTemplateCodeHtml = $this->webTemplateRecData['codeTemplate'];
  }

  public function createData()
  {
    //$this->bcardData['recData'] = $this->bcardRecData;
    if (!$this->bcardRecData)
    {
      return;
    }

    if ($this->bcardRecData['bcardType'] === 0)
      $this->bcardData['isHuman'] = 1;
    else
      $this->bcardData['isCompany'] = 1;

    $this->bcardData['id1'] = $this->bcardRecData['id1'];
    if ($this->bcardRecData['id2'] !== '')
      $this->bcardData['id2'] = $this->bcardRecData['id2'];
    $this->bcardData['name'] = $this->bcardRecData['fullName'];
    if ($this->bcardRecData['title'] !== '')
      $this->bcardData['title'] = $this->bcardRecData['title'];
    if ($this->bcardRecData['email'] !== '')
      $this->bcardData['email'] = $this->bcardRecData['email'];
    if ($this->bcardRecData['phone'] !== '')
      $this->bcardData['phone'] = $this->bcardRecData['phone'];
    if ($this->bcardRecData['web'] !== '')
      $this->bcardData['web'] = $this->bcardRecData['web'];

    $this->bcardData['org'] = $this->orgRecData;
    if ($this->bcardRecData['bcardType'] === 0 && $this->orgRecData['companyBCard'])
    {
      $ce = new BCardEngine($this->app());
      $ce->setBCard($this->orgRecData['companyBCard']);
      $ce->createData();
      $this->bcardData['company'] = $ce->bcardData;
    }

    $this->createVCARD();
    $this->createVCARDQRCode();
    $this->cardAttachments($this->bcardNdx, $this->bcardData);
  }

  public function createWebCard()
  {
    $this->loadWebTemplate();
    $this->createData();

    $this->template = new \Shipard\Report\TemplateMustache($this->app());
    $this->template->data['bcardNdx'] = $this->bcardNdx;
    $this->template->data['bcard'] = $this->bcardData;
    $this->template->data['dataVer'] = md5(json_encode($this->bcardData));
    $this->template->data['cssStyle'] = $this->webTemplateCodeCss;//$this->template->render($this->webTemplateCodeCss);

    $this->webCardHtml = $this->template->render($this->webTemplateCodeHtml);
  }

  public function createVCARD()
  {
		$ld = "\r\n";
		$v = '';
		$v .= 'BEGIN:VCARD'.$ld;
		$v .= 'VERSION:2.1'.$ld;

    /*
		$v .= 'N;CHARSET=UTF-8:'.$this->vcEscape($this->personRecData['lastName']);
		$v .= ';';

		$v .= $this->vcEscape($this->personRecData['firstName']);
		$v .= ';';

		if ($this->personRecData['middleName'] !== '')
			$v .= $this->vcEscape($this->personRecData['middleName']);
		$v .= ';';

		if ($this->personRecData['beforeName'] !== '')
			$v .= $this->vcEscape($this->personRecData['beforeName']);
		$v .= ';';

		if ($this->personRecData['afterName'] !== '')
			$v .= $this->vcEscape($this->personRecData['afterName']);
		$v .= ';';

		$v .= $ld;
    */
 		$v .= 'FN;CHARSET=UTF-8:'.$this->vcEscape($this->bcardData['name']).$ld;

		if (isset($this->bcardData['email']) && $this->bcardData['email'] !== '')
 			$v .= 'EMAIL;TYPE=work:'.$this->bcardData['email'].$ld;
    if (isset($this->bcardData['phone']) && $this->bcardData['phone'] !== '')
 			$v .= 'TEL;TYPE=cell:'.$this->bcardData['phone'].$ld;

		$orgFunction = '';
    if (isset($this->bcardData['title']) && $this->bcardData['title'] !== '')
			$orgFunction = $this->bcardData['title'];

		if (isset($this->bcardData['company']['name']))
			$v .= 'ORG;CHARSET=UTF-8:'.$this->vcEscape($this->bcardData['company']['name']).$ld;
    if (isset($this->bcardData['company']['web']))
			$v .= 'URL:'.$this->vcEscape($this->bcardData['company']['web']).$ld;

		if ($orgFunction !== '')
			$v .= 'TITLE;CHARSET=UTF-8:'.$this->vcEscape($orgFunction).$ld;

    $v .= 'END:VCARD'.$ld;

    $this->bcardData['vcard'] = $v;
  }

	public function createVCARDQRCode()
	{
		$vcHash = 'bc_vcard_'.sha1($this->bcardData['vcard']);
		$dirName = __APP_DIR__.'/imgcache/bcards/';
    if (!is_dir($dirName))
      Utils::mkDir($dirName);

		$vcardBaseFileName = $vcHash.'.vcard';
		$vcardFullFileName = $dirName.$vcardBaseFileName;
    if (!is_readable($vcardFullFileName))
      file_put_contents($vcardFullFileName, $this->bcardData['vcard']);

		$qrBaseFileName = $vcHash.'.svg';
		$qrFullFileName = $dirName.$qrBaseFileName;
    //$this->bcardData ['vcardQRCodeFullFileName'] = $qrFullFileName;
		$this->bcardData ['vcardQRCodeURL'] = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/imgcache/bcards/'.$qrBaseFileName;

		if (is_readable($qrFullFileName))
			return;


		$cmd = "qrencode -lM -m 0 -t SVG --rle -o \"{$qrFullFileName}\" -r \"{$vcardFullFileName}\"";
		exec ($cmd);
	}

	function vcEscape ($str)
	{
		return str_replace([';', ',', ':'], ['\;', '\,', '\:'], $str);
	}

  protected function cardAttachments($cardNdx, &$dest)
  {
    $allAttachments = UtilsBase::loadAttachments ($this->app(), [$cardNdx], 'e10pro.bcards.cards');

    if (!isset($allAttachments[$cardNdx]['images']) || !count($allAttachments[$cardNdx]['images']))
      return NULL;
    foreach ($allAttachments[$cardNdx]['images'] as $img)
    {
      if ($img['defaultImage'])
      {
        $dest['defaultImage'] = [
          'url' => $img['url'], 'path' => $img['path'], 'fileName' => $img['filename'], 'folder' => $img['folder'],
          'svg' => $img['svg']
        ];
        continue;
      }
      $dest['images'][] = [
        'url' => $img['url'], 'path' => $img['path'], 'fileName' => $img['filename'], 'folder' => $img['folder'],
        'svg' => $img['svg']
      ];
    }
  }

  public function url($production = FALSE)
  {
    if ($production)
      $url = $this->app->cfgItem ('options.bcards.urlProduction', '');
    else
      $url = $this->app->cfgItem ('options.bcards.urlTest', '');
    $url .= $this->bcardRecData['id1'];

    return $url;
  }
}
