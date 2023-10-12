<?php

namespace e10pro\zus\libs\ezk;
use \Shipard\Utils\Utils;


/**
 * class ViewInvoicesOut
 */
class ViewInvoicesOut extends \e10doc\invoicesOut\libs\apps\ViewInvoicesOut
{
  var $studentNdx = 0;
	var $userContext = NULL;

	public function init ()
	{
		parent::init();

		$this->uiSubTemplate = 'modules/e10pro/zus/libs/ezk/subtemplates/invoiceOutRow';

		$userContexts = $this->app()->uiUserContext ();
		$ac = $userContexts['contexts'][$this->app()->uiUserContextId] ?? NULL;
		if ($ac)
			$this->studentNdx = $ac['studentNdx'] ?? 0;

		$this->userContext = $userContexts['ezk']['students'][$this->studentNdx];
	}

  public function renderRow ($item)
	{
    $listItem = parent::renderRow($item);
    $this->balanceInfo ($item, $listItem);
    $this->addReportsForDownload($item, $listItem);

		return $listItem;
	}

  protected function appQuery(&$q)
  {
    array_push($q, ' AND [heads].[person] = %i', $this->studentNdx);
    //array_push($q, ' AND [heads].[workOrder] = %i', $this->flatNdx);
    array_push($q, ' AND [heads].[docType] = %s', 'invno');
  }
}

