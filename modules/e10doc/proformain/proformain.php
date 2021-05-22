<?php

namespace E10Doc\ProformaIn {


use \E10\TableView, \E10\TableViewDetail;
use \E10\TableForm;
use \E10\Application;
use \E10\FormReport;
use \E10Doc\Core\ViewDetailHead;

/**
 * Pohled na Zálohovou fakturu přijatou
 *
 */

class ViewProformaInDocs extends \E10Doc\Core\ViewHeads
{
	public function init ()
	{
		$this->docType = 'prfmin';
		parent::init();
	}
} // class ViewProformaInDocs


/**
 * Základní detail Zálohové faktury přijaté
 *
 */

class ViewDetailProformaInDocs extends ViewDetailHead
{
}


/**
 * Editační formulář Zálohové faktury přijaté
 *
 */

class FormProformaInDocs extends \E10Doc\Core\FormHeads
{
	public function renderForm ()
	{
		$taxPayer = $this->recData['taxPayer'];

		$this->setFlag ('maximize', 1);
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);
		
		$this->openForm (TableForm::ltNone);
			$tabs ['tabs'][] = array ('text' => 'Doklad', 'icon' => 'x-content');
			$tabs ['tabs'][] = array ('text' => 'Přílohy', 'icon' => 'x-attachments');
			$tabs ['tabs'][] = array ('text' => 'Nastavení', 'icon' => 'x-wrench');
			$this->openTabs ($tabs);

			$this->openTab (TableForm::ltNone);		
			$this->layoutOpen (TableForm::ltDocMain);
				$this->layoutOpen (TableForm::ltVertical);
					$this->addColumnInput ("person");
				$this->layoutClose ();

				$this->layoutOpen (TableForm::ltForm);
					$this->addColumnInput ("symbol1");
					$this->addColumnInput ("dateIssue");
					$this->addColumnInput ("dateDue");
					if ($taxPayer)
					{
						$this->addColumnInput ("taxCalc");
						$this->addColumnInput ("taxType");
					}
					$this->addColumnInput ("currency");

          $this->layoutClose ();
				$this->layoutOpen (TableForm::ltVertical);
					$this->addColumnInput ("title");
				$this->layoutClose ();

			$this->layoutClose ();

			$this->layoutOpen (TableForm::ltDocRows);
				$this->addList ('rows');
			$this->layoutClose ();

      $this->closeTab ();

			$this->openTab (TableForm::ltNone);
				$this->addAttachmentsViewer();
			$this->closeTab ();

			$this->openTab ();
				$this->addColumnInput ("author");
			$this->closeTab ();
      
      $this->closeTabs ();
      
    $this->closeForm ();
	}

	public function checkNewRec ()
	{
		parent::checkNewRec ();
		$this->recData ['dateDue'] = new \DateTime ();
		$this->recData ['dateDue']->add (new \DateInterval('P' . Application::cfgItem ('e10.options.dueDays', 14) . 'D'));
	}
} // class FormProformaInDocs


/**
 * Editační formulář Řádku Zálohové faktury přijaté
 *
 */

class FormProformaInDocsRows extends TableForm
{
	public function renderForm ()
	{
		$ownerRecData = $this->option ('ownerRecData');

		$this->openForm (TableForm::ltVertical);
			$this->layoutOpen (TableForm::ltHorizontal);
				$this->addColumnInput ("item");
				$this->addColumnInput ("text");
			$this->layoutClose ();

			$this->layoutOpen (TableForm::ltHorizontal);
				$this->addColumnInput ("quantity");
				$this->addColumnInput ("priceItem");
				$this->addColumnInput ("unit");
				if ($ownerRecData && $ownerRecData ['taxPayer'])
				{
					//$this->addColumnInput ("taxRate");
					$this->addColumnInput ("taxCode");
				}
			$this->layoutClose ();
			
		$this->closeForm ();
	}
} // class FormProformaInDocsRows


} // namespace E10Doc\ProformaIn

