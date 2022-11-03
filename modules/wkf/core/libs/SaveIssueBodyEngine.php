<?php
namespace wkf\core\libs;

use \Shipard\Base\Utility;
use \Shipard\Utils\Utils;


/**
 * class SaveIssueBodyEngine
 */
class SaveIssueBodyEngine extends Utility
{
  /** @var \wkf\core\TableIssues $tableIssues */
	var $tableIssues;
	var $issueNdx = 0;
	var $issueRecData;

  public function setIssueNdx($issueNdx)
  {
		$this->tableIssues = $this->app()->table('wkf.core.issues');
		$this->issueNdx = $issueNdx;
		$this->issueRecData = $this->tableIssues->loadItem($this->issueNdx);
  }

  public function run()
  {
    $srcBaseFileName = Utils::tmpFileName('html', 'email', 1);
    $srcFullFileName = __APP_DIR__ .'/'.$srcBaseFileName;
    $dstFullFileName = __APP_DIR__ .'/'.$srcBaseFileName.'.pdf';
    file_put_contents($srcFullFileName, $this->issueRecData['body']);

    $url = 'https://'.$this->app()->cfgItem('hostingCfg.serverDomain').'/'.$this->app->cfgItem('dsid') . '/'. $srcBaseFileName;

		$pdfCreator = new \lib\pdf\PdfCreator($this->app());
		$pdfCreator->setUrl($srcBaseFileName, $url, $dstFullFileName);
		$pdfCreator->createPdf();

    \E10\Base\addAttachments ($this->app, 'wkf.core.issues', $this->issueNdx, $dstFullFileName, '', FALSE, 0, $this->issueRecData['subject']);
  }
}
