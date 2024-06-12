<?php

namespace e10doc\slr\dc;
use \Shipard\Utils\Json;


/**
 * class DCEmpRec
 */
class DCEmpRec extends \Shipard\Base\DocumentCard
{
  /** @var \e10doc\core\TableHeads */
  var $tableDocsHeads;

  protected function createContent_accDoc()
  {
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
        'text' => 'Vystavit', 'data-class' => 'e10doc.slr.libs.WizardGenerateAccDoc',
        'icon' => 'system/actionAdd',
        'class' => 'pull-right'
      ];
    }
    else
    {
      $title [] = [
        'type' => 'action', 'action' => 'addwizard',
        'text' => 'Přegenerovat', 'data-class' => 'e10doc.slr.libs.WizardGenerateAccDoc',
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

	protected function addRows()
	{
    $ae = new \e10doc\slr\libs\AccEngine($this->app());
    $ae->setEmpRec($this->recData['ndx']);
    $ae->loadData();

    $this->addContent('body',  [
      'pane' => 'e10-pane e10-pane-table',
      'table' => $ae->detailOverviewTable, 'header' => $ae->detailOverviewHeader,
    ]);
	}

	public function createContentBody ()
	{
    $this->createContent_accDoc();
		$this->addRows();
	}

	public function createContent ()
	{
    $this->tableDocsHeads = $this->app()->table('e10doc.core.heads');

		$this->createContentBody ();
	}
}
