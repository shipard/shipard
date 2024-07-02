<?php

namespace e10pro\bume\libs;
use \Shipard\Utils\Utils;
use \Shipard\UI\Core\UIUtils;


/**
 * class ReportListContacts
 */
class ReportListContacts extends \Shipard\Report\GlobalReport
{
	var $data = [];
	var $contactsList = NULL;
	var $contactsListNdx = 0;

	var \e10pro\bume\libs\ContactsListEngine $contactsListEngine;

	function init ()
	{
		$this->addParamContactsList ();

		parent::init();

		$this->contactsListNdx = intval($this->reportParams ['contactsList']['value']);

		$this->setInfo('icon', 'reportContacts');
	}

	function createContent ()
	{
		$this->createContent_Contacts();

		$h = [
			'personId' => 'id',
			'fullName' => 'Celé jméno',
			'firstName' => 'Jméno', 'lastName' => 'Příjmení',
			'function' => 'Funkce',
			'email' => 'E-mail', 'phone' => 'Telefon',
			'qrBtn' => 'QR'
		];
		$this->addContent (['type' => 'table', 'header' => $h, 'table' => $this->data]);

		$this->setInfo('title', 'Kontaktní údaje');
	}

	function createContent_Contacts ()
	{
		$this->data = [];

		$this->contactsListEngine = new \e10pro\bume\libs\ContactsListEngine($this->app());
		$this->contactsListEngine->setList($this->contactsListNdx);
		$this->contactsListEngine->createRecipients();
		$this->contactsListEngine->createData();

		$this->data = $this->contactsListEngine->data;
	}

	public function createToolbarSaveAs (&$printButton)
	{
		parent::createToolbarSaveAs ($printButton);
		$printButton['dropdownMenu'][] = ['text' => 'CSV (.csv)', 'icon' => 'user/fileText', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'csv'];
		$printButton['dropdownMenu'][] = ['text' => 'vCard (.vcf)', 'icon' => 'system/iconIdBadge', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'vcf'];
		$printButton['dropdownMenu'][] = ['text' => 'ZIP (.zip)', 'icon' => 'user/folder', 'type' => 'reportaction', 'action' => 'print', 'class' => 'e10-print', 'data-format' => 'zip'];
	}

	public function saveReportAs ()
	{
		if ($this->format === 'csv')
		{
			$this->createContent_Contacts();

			$fileName = utils::tmpFileName('csv');
			$this->contactsListEngine->createCSV($fileName);
			$this->fullFileName = $fileName;

			return;
		}

		if ($this->format === 'vcf')
		{
			$this->createContent_Contacts();

			$fileName = utils::tmpFileName('vcf');
			$this->contactsListEngine->createVCF($fileName);
			$this->fullFileName = $fileName;

			return;
		}

		if ($this->format === 'zip')
		{
			$this->createContent_Contacts();

			$fileName = utils::tmpFileName('zip');
			$this->contactsListEngine->createZIP($fileName);
			$this->fullFileName = $fileName;

			return;
		}

		parent::saveReportAs();
	}

	protected function addParamContactsList ()
	{
		$contactsList = UIUtils::detectParamValue('contactsList', 0);

		$q = [];
		array_push($q, 'SELECT *');
		array_push($q, ' FROM e10pro_bume_lists AS lists');
		array_push($q, ' WHERE 1');
		array_push($q, ' AND lists.docState != %i', 9800);
		array_push($q, ' ORDER BY lists.fullName, lists.ndx');

		$rows = $this->app->db()->query($q);
		foreach ($rows as $r)
		{
			$this->contactsList [$r['ndx']] = $r['fullName'];
		}

		$this->addParam('switch', 'contactsList', ['title' => 'Seznam', 'switch' => $this->contactsList]);
	}
}


