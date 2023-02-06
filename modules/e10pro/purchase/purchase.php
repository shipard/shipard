<?php

namespace E10Pro\Purchase;
require_once __SHPD_MODULES_DIR__ . 'e10/base/base.php';

use \Shipard\Viewer\TableViewPanel;

require_once __SHPD_MODULES_DIR__ . 'e10/persons/tables/persons.php';
require_once __SHPD_MODULES_DIR__ . 'e10doc/balance/balance.php';


/**
 * Pohled na Dodavatele
 *
 */

class ViewSuppliers extends \e10\persons\ViewPersons
{
	public function init ()
	{
		parent::init();
		$this->linesWidth = 33;
		$this->loadAddresses = TRUE;
		$this->searchInProperties = TRUE;

		$q [] = 'SELECT COUNT(*) AS [cnt] FROM [e10doc_core_heads] AS heads ';
		array_push ($q, 'WHERE  heads.[docType] = %s ', 'purchase');
		array_push ($q, 'AND heads.[docState] = %i', 1001);
		$cntSuspended = $this->db()->query($q)->fetch();
		$suspendedTitle = 'Odložené';
		if ($cntSuspended && $cntSuspended['cnt'])
			$suspendedTitle .= ' ['.$cntSuspended['cnt'].']';

		$panels = [];
		$panels [] = ['id' => 'inprogress', 'title' => 'Rozpracované'];
		$panels [] = ['id' => 'suspended', 'title' => $suspendedTitle];
		//$panels [] = ['id' => 'inbatchmode', 'title' => 'Sběrné'];
		$this->setPanels($panels);
	}

	function decorateRow (&$item)
	{
//		if (isset ($this->properties [$item ['pk']]['groups']))
//			$item ['i2'] = $this->properties [$item ['pk']]['groups'];

		if (isset($this->addresses [$item ['pk']]))
			$item ['t2'] = $this->addresses [$item ['pk']][0];
		//else
		if (isset ($this->properties [$item ['pk']]['ids']))
			$item ['i2'] = $this->properties [$item ['pk']]['ids'];
	}

	public function createPanelContent (TableViewPanel $panel)
	{
		switch ($panel->panelId)
		{
			case 'inprogress'		: $this->createPanelContentInProgress ($panel); break;
			case 'suspended'	: $this->createPanelContentInProgress ($panel, TRUE); break;
//			case 'inbatchmode'	: $this->createPanelContentInBatchMode ($panel); break;
			default							: parent::createPanelContent ($panel); break;
		}
	}

	public function createPanelContentInProgress (TableViewPanel $panel, $suspended = FALSE)
	{
		$q [] = 'SELECT heads.[ndx] as ndx, [docNumber], [title], [sumPrice], [sumBase], [sumTotal], [toPay],
							[dateIssue], [person], [activateTimeFirst], [activateTimeLast],[weightIn], [weightOut],
							heads.[docType] as docType, heads.[docState] as docState, heads.[docStateMain] as docStateMain,
							heads.[taxPayer] as taxPayer, heads.currency as currency, heads.homeCurrency as homeCurrency,
							persons.fullName as personFullName
              FROM
              e10_persons_persons AS persons
              RIGHT JOIN [e10doc_core_heads] as heads ON (heads.person = persons.ndx)
              WHERE ';
		array_push ($q, 'heads.[docType] = %s ', 'purchase');
		if ($suspended)
			array_push ($q, 'AND heads.[docState] = %i', 1001);
		else
			array_push ($q, 'AND heads.[docState] IN %in', [1000, 1205]);
		array_push ($q, 'ORDER BY activateTimeFirst');

		$rows =	$this->table->db()->query ($q);
		$tiles = array ();
		forEach ($rows as $row)
		{
			$attFiles = \E10\Base\getAttachmentsThumbnails ($this->table->app (), 'e10doc.core.heads', $row ['ndx'], 0, 480);
			if (isset ($attFiles [0]))
				$coverImage = $attFiles [0];
			else
				$coverImage = "{$this->table->app ()->urlRoot}/e10-modules/e10pro/themes/icons/glyphish/x-dummy-image.png";

			$t1 = array ();
			$t1 [] = array ('i' => 'pencil', 'text' => \E10\df ($row['activateTimeFirst']));

			$t2 = array ();
			if ($row['weightIn'] != 0.0)
				$t2 [] = array ('i' => 'download', 'text' => \E10\nf ($row['weightIn']));
			if ($row['weightOut'] != 0.0)
				$t2 [] = array ('i' => 'upload', 'text' => \E10\nf ($row['weightOut']));

			if ($row['personFullName'] != '')
				$t2 [] = array ('i' => 'user', 'text' => $row['personFullName']);

			$tiles[] = array ('t1' => $t1, 't2' => $t2,
												'docAction' => 'edit', 'table' => 'e10doc.core.heads', 'pk' => $row['ndx'], 'addParams' => '__weightOut=-1',
												'coverImage' => $coverImage, 'badge-lt' => $row ['title']
												);
		}

		$panel->addContent(array ('type' => 'tiles', 'tiles' => $tiles, 'class' => 'coverImages'));
	}

	public function createPanelContentInBatchMode (TableViewPanel $panel)
	{
		$panel->addContentViewer ('e10.persons.persons', 'e10pro.purchase.BatchPurchaseDisposal',
															array ('recid' => $this->item ['ndx'], 'tableid' => $this->tableId()));

	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();
		if ($this->objectSubType !== self::vsMain)
			return $toolbar;

		$addParams = '__docType=purchase&__person={pk}';
		$toolbar [] = [
			'type' => 'document', 'action' => 'new', 'table' => 'e10doc.core.heads', 'doubleClick' => 1,
			'data-addparams' => $addParams, 'text' => 'Nový výkup2'
		];
		return $toolbar;
	}
}


/**
 * Detail Dodavatele
 *
 */

class ViewDetailSupplier extends \E10\Persons\ViewDetailPersons
{
	public function createToolbar ()
	{
		$toolbar = parent::createToolbar ();

		$addParams = '__docType=purchase&__person='.$this->item['ndx'];
		$toolbar [] = [
			'type' => 'document', 'action' => 'new', 'data-table' => 'e10doc.core.heads',
			'data-addparams' => $addParams, 'text' => 'Nový výkup'
		];

		if (isset ($this->app()->workplace['cashBox']))
		{
			$cbNdx = $this->app()->workplace['cashBox'];
			$addParams = '__docType=cashreg&__person='.$this->item['ndx'].'&__cashBox='.$cbNdx;
			$toolbar [] = [
				'type' => 'document', 'action' => 'new', 'data-table' => 'e10doc.core.heads',
				'data-addparams' => $addParams, 'text' => 'Nová prodejka'
			];
		}

		return $toolbar;
	} // createToolbar
} // class ViewDetailSupplier


/**
 * Seznam sběrných lístků
 */


class BatchPurchaseDisposal extends \E10Doc\Balance\BalanceDisposalViewer
{
	public function init ()
	{
		$this->balance = 7000;
		$this->docCheckBoxes = 2;
		parent::init();
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$buttons = array ();
		$buttons [] = array ('text' => 'Uhradit v hotovosti', 'docAction' => 'wizard', 'table' => 'e10doc.core.heads', 'data-class' => 'e10pro.purchase.addWizard');
		$item['buttons'] = $buttons;

		$item['docActionData']['fillDocRows'] = 'default';
	}
} // BatchPurchaseDisposal


/**
 * Seznam lístků placených fakturou
 */

class InvoicePurchaseDisposal extends \E10Doc\Balance\BalanceDisposalViewer
{
	public function init ()
	{
		$this->balance = 7100;
		$this->docCheckBoxes = 1;
		$this->searchDocsByPaymentSymbols = 1;
		parent::init();
	}

	function decorateRow (&$item)
	{
		parent::decorateRow ($item);

		$buttons = array ();
		$buttons [] = array ('text' => 'Vystavit fakturu', 'docAction' => 'new', 'table' => 'e10doc.core.heads',
												 'addParams' => '__docType=invni');
		$item['buttons'] = $buttons;

		$item['docActionData']['operation'] = '1040003';
		$item['docActionData']['title'] = 'Likvidace výkupu';

		// -- dbCounter: TODO: settings in sales options
		$dbCounters = $this->table->app()->cfgItem ('e10.docs.dbCounters.invni', FALSE);
		$item['docActionData']['dbCounter'] = key($dbCounters);
	}

	public function checkDocumentTile ($document, &$tile)
	{
		$tile['docActionData']['text'] = 'Likvidace výkupu '.$document['docNumber'];
		$tile['docActionData']['taxCode'] = 'EUCZ117'; // TODO: settings?
		$tile['docActionData']['weightNet'] = $document['weightNet'];
	}
} // InvoicePurchaseDisposal


/**
 * AddWizard
 *
 */

class AddWizard extends \e10doc\core\libs\CreateDocumentWizard
{
	protected function saveDocument ()
	{
		parent::saveDocument ();

		$newDoc = new \E10Doc\Core\CreateDocumentUtility ($this->app);
		$newDoc->createDocumentHead('cash');
		$newDoc->docHead['person'] = $this->docActionInfo ['person'];
		$newDoc->docHead['cashBoxDir'] = 2;
		$newDoc->docHead['taxCalc'] = 0;
		if (isset ($this->app->workplace['cashBox']))
			$newDoc->docHead['cashBox'] = $this->app->workplace['cashBox'];
		$newDoc->docHead['roundMethod'] = 1;

		$dt = '';

		forEach ($this->rows as $r)
		{
			$newRow = $newDoc->createDocumentRow($r);
			$newRow['symbol1'] = $r['symbol1'];
			$newRow['priceItem'] = $r['price'];
			$newRow['operation'] = '1040002';
			$newRow['text'] = 'Úhrada výkupu č. '.$r['symbol1'].' ze dne '.$r['date'];

			if ($dt !== '')
				$dt .= ', ';
			$dt .= $r['symbol1'];

			$newDoc->addDocumentRow ($newRow);
		}
		$newDoc->docHead['title'] = 'Úhrada výkupů '.$dt;

		$newDoc->saveDocument();
	}

	protected function welcomeHeader ()
	{
		$c = 'Zaplatit v hotovosti:';
		$c .= ' '.\E10\nf ($this->docActionInfo ['toPay']).' ';
		return $c;
	}

}

/**
 * reportBalancePurchasesCash
 *
 */

class reportBalancePurchasesCash extends \E10Doc\Balance\reportBalance
{
	function init ()
	{
		$this->balance = 7000;
		parent::init();
	}
}

/**
 * reportBalancePurchasesInvoice
 *
 */

class reportBalancePurchasesInvoice extends \E10Doc\Balance\reportBalance
{
	function init ()
	{
		$this->balance = 7100;
		parent::init();
	}
}
