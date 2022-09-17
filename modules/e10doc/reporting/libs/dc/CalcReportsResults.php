<?php

namespace e10doc\reporting\libs\dc;
use \Shipard\Base\DocumentCard;
use \Shipard\Utils\Json;


/**
 * class CalcReportsResults
 */
class CalcReportsResults extends DocumentCard
{
  var $calcReportRecData;
  var $calcReportTypeCfg;

  /** @var \e10doc\core\TableHeads */
  var $tableDocsHeads;

  public function createContentBody ()
	{
    $resContent = Json::decode($this->recData['resContent']);

    // -- documents
    $this->createContent_invoiceOut();
    $this->createContent_accDoc();

    // -- visualize contents
    $this->addContent('body', ['type' => 'line', 'line' => ['code' => "<div class='e10-pane e10-pane-table'>"]]);
    foreach ($resContent as $cc)
    {
      $this->addContent('body', $cc);
    }
    $this->addContent('body', ['type' => 'line', 'line' => ['code' => "</div>"]]);

    //$resData = Json::decode($this->recData['resData']);
    //$this->addContent('body', ['type' => 'text', 'text' => $this->recData['resData']]);
    //$this->addContent('body', ['type' => 'text', 'text' => $this->recData['resContent']]);
    //$this->addContent('body', ['type' => 'text', 'text' => json_encode($this->calcReportTypeCfg)]);
  }

  protected function createContent_invoiceOut()
  {
    if (!$this->calcReportTypeCfg['useRowInvoiceOut'] ?? 0)
      return;

    $docNdx = $this->recData['docInvoiceOut'];
    $title = [];
    $title[] = ['text' => 'Faktura s vyúčtováním', 'class' => 'h2', 'icon' => 'docType/invoicesOut'];

    $docStateStyle = '';
    $body = [];
    if ($docNdx === 0)
    {
      $body[] = ['text' => 'Doklad zatím není vystaven', 'class' => 'e10-error'];

      $title [] = [
				'type' => 'action', 'action' => 'addwizard',
				'text' => 'Vystavit', 'data-class' => 'e10doc.reporting.libs.CalcReportGenerateRowInvoiceOutWizard',
        'icon' => 'system/actionAdd',
        'class' => 'pull-right'
		  ];
    }
    else
    {
      $title [] = [
				'type' => 'action', 'action' => 'addwizard',
				'text' => 'Přegenerovat', 'data-class' => 'e10doc.reporting.libs.CalcReportGenerateRowInvoiceOutWizard',
        'icon' => 'cmnbkpRegenerateOpenedPeriod',
        'class' => 'pull-right'
		  ];

      $docRecData = $this->tableDocsHeads->loadItem($docNdx);
      $title [] = [
				'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $docNdx,
				'text' => $docRecData['docNumber'],
        'icon' => $this->tableDocsHeads->tableIcon($docRecData),
        'class' => 'pull-right', 'actionClass' => 'btn btn-primary', 'type' => 'button'
		  ];

      $docState = $this->tableDocsHeads->getDocumentState ($docRecData);
			$docStateStyle = ' e10-ds '.$this->tableDocsHeads->getDocumentStateInfo ($docState ['states'], $docRecData, 'styleClass');
    }
    $title[] = ['text' => '', 'class' => 'block'];

    $this->addContent('body', ['pane' => 'e10-pane e10-pane-table'.$docStateStyle, 'paneTitle' => $title, 'type' => 'line', 'line' => $body]);
  }

  protected function createContent_accDoc()
  {
    if (!$this->calcReportTypeCfg['useRowAccDoc'] ?? 0)
      return;

      $docNdx = $this->recData['docAcc'];
      $title = [];
      $title[] = ['text' => 'Účetní doklad', 'class' => 'h2', 'icon' => 'docType/accDocs'];

      $docStateStyle = '';
      $body = [];
      if ($docNdx === 0)
      {
        $body[] = ['text' => 'Doklad zatím není vystaven', 'class' => 'e10-error'];

        $title [] = [
          'type' => 'action', 'action' => 'addwizard',
          'text' => 'Vystavit', 'data-class' => 'e10doc.reporting.libs.CalcReportGenerateRowAccDocWizard',
          'icon' => 'system/actionAdd',
          'class' => 'pull-right'
        ];
      }
      else
      {
        $title [] = [
          'type' => 'action', 'action' => 'addwizard',
          'text' => 'Přegenerovat', 'data-class' => 'e10doc.reporting.libs.CalcReportGenerateRowAccDocWizard',
          'icon' => 'cmnbkpRegenerateOpenedPeriod',
          'class' => 'pull-right'
        ];

        $docRecData = $this->tableDocsHeads->loadItem($docNdx);
        $title [] = [
          'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $docNdx,
          'text' => $docRecData['docNumber'],
          'icon' => $this->tableDocsHeads->tableIcon($docRecData),
          'class' => 'pull-right', 'actionClass' => 'btn btn-primary', 'type' => 'button'
        ];

        $docState = $this->tableDocsHeads->getDocumentState ($docRecData);
        $docStateStyle = ' e10-ds '.$this->tableDocsHeads->getDocumentStateInfo ($docState ['states'], $docRecData, 'styleClass');
      }
      $title[] = ['text' => '', 'class' => 'block'];

      $this->addContent('body', ['pane' => 'e10-pane e10-pane-table'.$docStateStyle, 'paneTitle' => $title, 'type' => 'line', 'line' => $body]);
  }

  public function createContent ()
	{
    $this->tableDocsHeads = $this->app()->table('e10doc.core.heads');

    $this->calcReportRecData = $this->app()->loadItem($this->recData['report'], 'e10doc.reporting.calcReports');
    $this->calcReportTypeCfg = $this->app()->cfgItem('e10doc.reporting.calcReports.'.$this->calcReportRecData['calcReportType'], NULL);

		$this->createContentBody ();
	}
}
