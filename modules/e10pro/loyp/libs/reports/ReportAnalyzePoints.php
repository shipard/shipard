<?php

namespace e10pro\loyp\libs\reports;
use \Shipard\Utils\Utils, \e10doc\core\libs\E10Utils;


/**
 * class ReportAnalyzePoints
 */
class ReportAnalyzePoints extends \e10doc\core\libs\reports\GlobalReport
{
	function init()
	{
    $enumLoyps = $this->loypsEnum();
    $this->addParam('switch', 'loyp', ['title' => 'VP', 'switch' => $enumLoyps]);

		parent::init();

		$loypNdx = intval($this->reportParams['loyp']['value']);

		$this->setInfo('icon', 'report/inventoryingReport');
		$this->setInfo('title', 'Analýza věrnostních bodů: ' . $enumLoyps[$loypNdx]);
	}

	public function createContent()
	{
		parent::createContent();

    $ape = new \e10pro\loyp\libs\AnalyzePointsEngine($this->app());
		$ape->loypNdx = intval($this->reportParams['loyp']['value']);
    $ape->run();

    $this->addContent($ape->overviewContent);
    $this->addContent($ape->hstContent[1]);
    $this->addContent($ape->hstContent[2]);
	}

  protected function loypsEnum()
  {
    $enum = [];

    $q = [];
    array_push($q, 'SELECT * FROM [e10pro_loyp_loyps]');
    array_push($q, ' WHERE 1');
    array_push($q, ' AND [docState] = %i', 4000);
    array_push($q, ' ORDER BY [validFrom] DESC');

    $rows = $this->db()->query($q);
    foreach ($rows as $r)
    {
      $enum[$r['ndx']] = $r['shortName'];
    }

    return $enum;
  }
}
