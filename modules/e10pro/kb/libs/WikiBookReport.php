<?php

namespace e10pro\kb\libs;

/**
 * class WikiBookReport
 */
class WikiBookReport extends \e10doc\core\libs\reports\DocReportBase
{
  var \e10pro\kb\libs\WikiBookGenerator $wikiBookGenerator;

  public function __construct ($app, \e10pro\kb\libs\WikiBookGenerator $wikiBookGenerator)
  {
    $this->wikiBookGenerator = $wikiBookGenerator;

    $table = $app->table ('e10pro.kb.texts');
    parent::__construct ($table, ['ndx' => 0]);
  }

	function init ()
	{
		$this->reportId = 'e10pro.kb.wikibook';
		$this->reportTemplate = 'reports.modern.e10pro.kb.wikibook';
	}

  public function loadData ()
	{
		parent::loadData();
    $this->loadData_DocumentOwner(4);

    $this->data ['bookContent'] = $this->wikiBookGenerator->pdfContentCode;
    $this->data ['wikiSection'] = $this->wikiBookGenerator->srcSectionRecData;
    $this->data ['bookText'] = $this->wikiBookGenerator->bookText;
    $this->data ['bookVersionId'] = $this->wikiBookGenerator->bookVersionId;
	}
}
