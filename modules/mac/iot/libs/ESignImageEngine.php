<?php

namespace mac\iot\libs;
use \Shipard\Base\Utility, \Shipard\Utils\Json;


/**
 * class ESignImageEngine
 */
class ESignImageEngine extends Utility
{
  /** @var \mac\iot\TableESigns */
  var $tableESigns;
  var $esignNdx = 0;
  var $esignRecData = NULL;
  var $displayInfo = NULL;

  var $templateCss = '';
  var $templateHtml = '';

  var $htmlCode = '';
  var $htmlCodeFileName = '';
  var $htmlCodeURL = '';
  var $htmlCheckSum = '';

  var $esignImgRecData = NULL;
  var $needUpdate = false;

  var \Shipard\Utils\TemplateCore $template;

	var $result = [];

  protected function init()
  {
    $this->tableESigns = $this->app()->table('mac.iot.esigns');
  }

  protected function createHTML()
  {
    $this->template = new \Shipard\Report\TemplateMustache($this->app());
    $this->template->data['displayInfo'] = $this->displayInfo;

    $this->template->data['vds'] = Json::decode($this->esignRecData['vdsData']);

    $this->template->data['cssStyle'] = $this->template->render($this->templateCss);
    $this->htmlCode = $this->template->render($this->templateHtml);
  }

  protected function postProcessHTML()
  {
    $srcHTML = $this->htmlCode;
    $m = [];

    if (preg_match_all("/{__{(.*?)}__}/", $srcHTML, $m))
    {
      foreach ($m[1] as $i => $str)
      {
        $subTmpl = '{{'.$str.'}}';
        $replace = $this->template->render($subTmpl);
        $srcHTML = str_replace($m[0][$i], $replace, $srcHTML);
      }
    }

    $this->htmlCode = $srcHTML;
  }

  protected function createImage()
  {
    if (!$this->displayInfo)
      return;
    $sc = new \Shipard\Base\Screenshot ($this->app());

    $sc->vpWidth = $this->displayInfo['width'] ?? 640;
    $sc->vpHeight = $this->displayInfo['height'] ?? 480;

    $sc->url = $this->htmlCodeURL;
    $sc->run ();

    $pageInfoStr = file_get_contents($sc->scCreator->dstFileNameInfo);
    $pageInfo = Json::decode($pageInfoStr);
    if (!$pageInfo)
      $pageInfo = [];

    $pageInfo['imageUri']  = $this->app()->urlRoot.substr($sc->dstFullFileName, strlen(__APP_DIR__));

    $convertedFileName = $sc->dstFullFileName.'._c.png';

    $didderPalette = implode(' ', $this->displayInfo['colors']);
    $cmd = "didder --palette \"{$didderPalette}\" -i ".$sc->dstFullFileName." -o ".$convertedFileName; // -dither FloydSteinberg -define dither:diffusion-amount=85%
    $cmd .= " -s 0.1 bayer 16x16";
    exec ($cmd);
    $urlImage = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/tmp/'.basename($convertedFileName);
    $this->esignImgRecData['imagePreviewURL'] = $urlImage;

		$ic = new \mac\iot\libs\EPDImageCreator($this->app());
    $ic->setDisplayInfo($this->displayInfo);
		$ic->setSrcImage($convertedFileName);
		$ic->doIt();
    $urlEinkImage = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/tmp/'.basename($ic->destFileName);
    $this->esignImgRecData['imageEinkURL'] = $urlEinkImage;
  }

  public function setESign($esignNdx)
  {
    $this->init();

    $this->esignNdx = $esignNdx;
    $this->esignRecData = $this->tableESigns->loadItem($esignNdx);
    $this->displayInfo = $this->tableESigns->getESignInfo($esignNdx);

    if ($this->esignRecData['esignKind'])
		{
			$esignKindRecData = $this->app()->loadItem($this->esignRecData['esignKind'], 'mac.iot.esignsKinds');
      $this->templateCss = $esignKindRecData['codeStyle'];
      $this->templateHtml = $esignKindRecData['codeTemplate'];
		}
    else
    {
      $this->templateCss = $this->esignRecData['codeStyle'];
      $this->templateHtml = $this->esignRecData['codeTemplate'];
    }
    if (!$this->displayInfo || !($this->displayInfo['ok'] ?? 0))
      return;

    $this->esignImgRecData = $this->app()->loadItem($this->esignNdx, 'mac.iot.esignsImgs');
    if (!$this->esignImgRecData)
    {
      $this->esignImgRecData = ['ndx' => $this->esignNdx];
      $this->db()->query('INSERT INTO [mac_iot_esignsImgs] ', $this->esignImgRecData);
      $this->esignImgRecData = $this->app()->loadItem($this->esignNdx, 'mac.iot.esignsImgs');
    }
  }

  protected function checkImgData()
  {
    $this->htmlCodeFileName = __APP_DIR__ .'/tmp/'.'esign-epdImg-'.$this->esignNdx.'.html';
    $this->htmlCodeURL = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid').'/tmp/'.basename($this->htmlCodeFileName).'?v='.time();
    $htmlFileExist = is_readable($this->htmlCodeFileName);

    $this->htmlCheckSum = sha1($this->htmlCode);
    if ($this->htmlCheckSum !== $this->esignImgRecData['htmlCodeVer'] || $this->esignImgRecData['imagePreviewURL'] === '' || !$htmlFileExist)
    {
      $this->esignImgRecData['htmlCodeVer'] = $this->htmlCheckSum;
      $this->postProcessHTML();

      $this->htmlCodeFileName = __APP_DIR__ .'/tmp/'.'esign-epdImg-'.$this->esignNdx.'.html';
      file_put_contents($this->htmlCodeFileName, $this->htmlCode);

      $this->needUpdate = true;
    }
  }

  public function doIt()
  {
    if (!$this->displayInfo || !($this->displayInfo['ok'] ?? 0))
      return;

    $this->createHTML();
    $this->checkImgData();

    if ($this->needUpdate)
      $this->createImage();

    if ($this->needUpdate)
    {
      $this->esignImgRecData['version']++;
      $this->db()->query('UPDATE [mac_iot_esignsImgs] SET ', $this->esignImgRecData, ' WHERE ndx = %i', $this->esignImgRecData['ndx']);
    }
  }

	public function run ()
	{
	}
}

