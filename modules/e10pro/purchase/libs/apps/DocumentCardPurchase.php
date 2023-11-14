<?php

namespace e10pro\purchase\libs\apps;
use \Shipard\Base\DocumentCard;



class DocumentCardPurchase extends DocumentCard
{
  public function createContentBody ()
	{
    $report = new \e10doc\purchase\libs\PurchaseReport($this->table, $this->recData);
    $report->loadData2();

    foreach ($report->data as $key => $value)
      $this->uiTemplate->data[$key] = $value;

		$templateStr = $this->uiTemplate->subTemplateStr('modules/e10pro/purchase/libs/apps/subtemplates/purchaseDetail');
		$code = $this->uiTemplate->render($templateStr);

		$this->addContent ('body', ['type' => 'text', 'subtype' => 'rawhtml', 'text' => $code]);
  }

  public function createContent ()
	{
		$this->createContentBody ();
  }
}

