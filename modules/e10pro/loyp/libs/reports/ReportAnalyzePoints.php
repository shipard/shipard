<?php

namespace e10pro\loyp\libs\reports;
use \Shipard\Utils\Utils, \e10doc\core\libs\E10Utils;


/**
 * class ReportAnalyzePoints
 */
class ReportAnalyzePoints extends \e10doc\core\libs\reports\GlobalReport
{
  var $dateBegin = NULL;
  var $dateEnd = NULL;

	function init()
	{
		$this->addParam ('fiscalPeriod', 'fiscalPeriod', ['flags' => ['quarters', 'halfs', 'years'], 'title' => 'Období']);

		parent::init();

		$fp = $this->reportParams ['fiscalPeriod']['value'];
		$this->dateBegin = isset ($this->reportParams ['fiscalPeriod']['values'][$fp]['dateBegin']) ? $this->reportParams ['fiscalPeriod']['values'][$fp]['dateBegin'] : NULL;
    $this->dateEnd = isset ($this->reportParams ['fiscalPeriod']['values'][$fp]['dateEnd']) ? $this->reportParams ['fiscalPeriod']['values'][$fp]['dateEnd'] : NULL;

		$this->setInfo('icon', 'report/inventoryingReport');
		$this->setInfo('title', 'Analýza věrnostních bodů');
	}

	public function createContent()
	{
		parent::createContent();

    $ape = new \e10pro\loyp\libs\AnalyzePointsEngine($this->app());
    $ape->run();

    $this->addContent($ape->overviewContent);

    $this->addContent($ape->hstContent[1]);

    $this->addContent($ape->hstContent[2]);
	}
}
