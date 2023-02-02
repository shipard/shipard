<?php

namespace e10pro\condo\libs;


/**
 * class ReportFlatRecordSheet
 */
class ReportFlatRecordSheet extends \e10doc\core\libs\reports\DocReportBase
{
	var \e10pro\condo\libs\FlatInfo $flatInfo;

	function init ()
	{
		$this->reportId = 'e10pro.condo.flatRecordSheet';
		$this->reportTemplate = 'reports.modern.e10pro.condo.flatrecordsheet';
		$this->paperOrientation = 'portrait';
	}

	public function loadData ()
	{
    $this->sendReportNdx = 2800;

    parent::loadData();
		$this->loadData_DocumentOwner ();

		$this->data['workOrder'] = $this->app()->loadItem($this->recData['workOrder'], 'e10mnf.core.workOrders');
		$this->data['person'] = $this->app()->loadItem($this->data['workOrder']['customer'], 'e10.persons.persons');

    $this->flatInfo = new \e10pro\condo\libs\FlatInfo($this->app());
    $this->flatInfo->setWorkOrder($this->recData['ndx']);
    $this->flatInfo->loadInfo();

    $contentTitle = ['text' => 'Informace o bytové jednotce', 'class' => 'h3'];
    foreach ($this->flatInfo->data['vdsContent'] as $cc)
    {
      $cc['params'] = ['hideHeader' => 1, ];
      $cc['title'] = $contentTitle;
      $this->data['contents'][] = $cc;

      break;
    }

    if ($this->flatInfo->data['personsList'])
    {
      $contentTitlePersons = ['text' => 'Kontaktní údaje', 'class' => 'h3'];
      $cc = $this->flatInfo->data['personsList'];
      unset($cc['pane']);
      $cc['title'] = $contentTitlePersons;
      $this->data['contents'][] = $cc;
    }

    if ($this->flatInfo->data['rowsContent'])
		{
			$contentTitleAdvances = ['text' => 'Výše měsíčních záloh', 'class' => 'h3'];
			$cc = $this->flatInfo->data['rowsContent'];
			unset($cc['pane']);
			$cc['title'] = $contentTitleAdvances;

			$this->data['contents'][] = $cc;
		}
	}
}
