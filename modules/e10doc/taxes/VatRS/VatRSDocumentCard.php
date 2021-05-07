<?php

namespace e10doc\taxes\VatRS;

use \e10\utils, \e10\Utility;

require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';


/**
 * Class VatRSDocumentCard
 * @package e10doc\taxes\VatRS
 */
class VatRSDocumentCard extends \e10doc\taxes\TaxReportDocumentCard
{
    public function createContentErrors ()
    {
        $reportContent = new \e10doc\taxes\VatCS\VatCSReportAll ($this->app());
        $reportContent->taxReportNdx = $this->recData['ndx'];
        $reportContent->filingNdx = 0;
        $reportContent->subReportId = 'preview';

        $reportContent->init();
        $reportContent->createContent();

        if ($reportContent->cntErrors)
        {
            $msg = [['text' => 'Souhrnné hlášení patrně obsahuje chyby', 'class' => 'h1', 'icon' => 'icon-exclamation-triangle']];

            if (count($reportContent->invalidDocs))
                $msg[] = ['text' => 'Nesrovnalosti v evidenci Dokladů ('.count($reportContent->invalidDocs).')', 'class' => 'block', 'icon' => 'icon-chevron-right'];
            //if (count($reportContent->badVatIds))
            //    $msg[] = ['text' => 'U některých dokladů nesouhlasí DIČ dokladu s evidencí Osob ('.count($reportContent->badVatIds).')', 'class' => 'block', 'icon' => 'icon-chevron-right'];
            if (count($reportContent->invalidPersons))
                $msg[] = ['text' => 'Nesrovnalosti v evidenci Osob ('.count($reportContent->invalidPersons).')', 'class' => 'block', 'icon' => 'icon-chevron-right'];

            $this->addContent('body', ['pane' => 'e10-pane e10-pane-table e10-warning1', 'type' => 'line', 'line' => $msg]);
        }
    }

	public function createContentBody ()
	{
		$this->createContentErrors();
		$this->createContentFilings();
	}

	public function createContent ()
	{
		$this->init();
		$this->createContentBody ();
	}
}
