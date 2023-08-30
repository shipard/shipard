<?php

namespace integrations\sendmail;
use \Shipard\Viewer\TableView, \Shipard\Viewer\TableViewDetail, \Shipard\Form\TableForm, \Shipard\Table\DbTable;


/**
 * Class TableSendmails
 */
class TableSendmails extends DbTable
{
	public function __construct ($dbmodel)
	{
		parent::__construct ($dbmodel);
		$this->setName ('integrations.sendmail.sendmails', 'integrations_sendmail_sendmails', 'Odesílání pošty');
	}

	public function createHeader ($recData, $options)
	{
		$hdr = parent::createHeader ($recData, $options);

		$hdr ['info'][] = ['class' => 'title', 'value' => $recData ['emailFrom']];

		return $hdr;
	}
}


/**
 * class ViewSendmails
 */
class ViewSendmails extends TableView
{
	public function init ()
	{
		parent::init();

		$this->objectSubType = TableView::vsDetail;
		$this->enableDetailSearch = TRUE;

		$this->setMainQueries ();
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		$listItem ['t1'] = $item['emailFrom'];
    $listItem ['t2'] = $item['smtpServer'];
		$listItem ['icon'] = $this->table->tableIcon ($item);

		return $listItem;
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q = [];
    array_push($q, 'SELECT * FROM [integrations_sendmail_sendmails]');
		array_push($q, ' WHERE 1');

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [emailFrom] LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '', ['[emailFrom]', '[ndx]']);
		$this->runQuery ($q);
	}
}


/**
 * class FormSendmail
 */
class FormSendmail extends TableForm
{
	public function renderForm ()
	{
		$this->setFlag ('formStyle', 'e10-formStyleSimple');
		$this->setFlag ('sidebarPos', TableForm::SIDEBAR_POS_RIGHT);

		$this->openForm ();
      $this->addColumnInput ('emailFrom');
      $this->addColumnInput ('smtpServer');
      $this->addColumnInput ('password');
		$this->closeForm ();
	}
}


/**
 * Class ViewDetailSendmail
 */
class ViewDetailSendmail extends TableViewDetail
{
}


