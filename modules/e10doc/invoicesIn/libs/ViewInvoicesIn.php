<?php

namespace e10doc\invoicesIn\libs;


class ViewInvoicesIn extends \E10Doc\Core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'invni';
		parent::init();

		if ($this->app()->cfgItem ('options.e10doc-commerce.useWorkOrders', 0))
			$this->showWorkOrders = TRUE;
	}

	function addPanels(&$panels)
	{
		$vid = $this->app()->testGetParam ('ownerViewerId');
		if ($vid === '')
			$vid = $this->vid;

		$panels[] = [
				'id' => 'inbox',
				'title' => 'Pošta', 'type' => 'viewer', 'table' => 'wkf.core.issues',
				'class' => 'wkf.core.viewers.WkfDocsFromInbox',
				'params' => ['docType' => 'invni', 'mainViewerId' => $vid],
			];
	}
}

