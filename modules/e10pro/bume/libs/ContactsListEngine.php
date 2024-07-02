<?php

namespace e10pro\bume\libs;

use Shipard\Base\Utility;
use \Shipard\Utils\Utils, \Shipard\Utils\Json, \e10doc\core\libs\E10Utils;
use \lib\persons\PersonsVirtualGroup;


/**
 * class ContactsListEngine
 */
class ContactsListEngine extends Utility
{
  var $contactsListNdx = 0;
	var $contactsListRecData = NULL;

	var $personCompanyRecData = NULL;
	var $persFuncPropertyRecData = NULL;

	var $data = [];


	public function setList($listNdx)
	{
		$this->contactsListNdx = $listNdx;

		$this->contactsListRecData = $this->app()->loadItem($listNdx, 'e10pro.bume.lists');

		if ($this->contactsListRecData && $this->contactsListRecData['bcCompany'] !== 0)
		{
			$this->personCompanyRecData = $this->app()->loadItem($this->contactsListRecData['bcCompany'], 'e10.persons.persons');
		}

		if ($this->contactsListRecData && $this->contactsListRecData['vcardPersFuncProperty'] !== 0)
		{
			$this->persFuncPropertyRecData = $this->app()->loadItem($this->contactsListRecData['vcardPersFuncProperty'], 'e10.base.propdefs');
		}
	}

	function createRecipients ()
	{
		$tableListPersons = $this->app()->table ('e10pro.bume.listPersons');

		// -- delete old
		$this->db()->query ('DELETE FROM [e10pro_wkf_listPersons] WHERE [list] = %i', $this->contactsListNdx);

		// -- add new
		$q[] = 'SELECT * FROM [e10pro_bume_listRecipients]';
		array_push ($q, ' WHERE [list] = %i', $this->contactsListNdx);
		array_push ($q, ' ORDER BY [ndx]');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$virtualGroup = $this->app()->cfgItem ('e10.persons.virtualGroups.'.$r['virtualGroup'], NULL);
			if (!$virtualGroup)
			{
				error_log("###WRONG virtualGroup ".json_encode($r['virtualGroup']));
				continue;
			}
			/** @var \lib\persons\PersonsVirtualGroup */
			$vgObject = $this->app()->createObject($virtualGroup['classId']);
			if (!$vgObject)
			{
				error_log("###WRONG vgObject ".json_encode($virtualGroup['classId']));
				continue;
			}

			$vgObject->recipientsMode = PersonsVirtualGroup::rmPersonsMsgs;
			$vgObject->addPosts($tableListPersons, 'list', $this->contactsListNdx, $r);

			unset ($vgObject);
		}
	}

	function createData ()
	{
		$data = [];

		$q = [];
		array_push ($q, 'SELECT listPersons.*,');
		array_push ($q, ' persons.fullName AS personFullName, persons.firstName AS personFirstName, persons.lastName AS personLastName, persons.id AS personId, persons.company AS personCompany');
		array_push ($q, ' FROM e10pro_wkf_listPersons AS listPersons');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON listPersons.person = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [listPersons].[list] = %i', $this->contactsListNdx);
		array_push ($q, ' ORDER BY persons.fullName');

		$rows = $this->app->db()->query($q);
		forEach ($rows as $r)
		{
			$pndx = $r['person'];

			if (!isset ($data[$pndx]))
			{
				$data[$pndx] = [
					'firstName' => $r['personFirstName'], 'lastName' => $r['personLastName'],
					'fullName' => $r['personFullName'], 'company' => $r['personCompany'],
					'personId' => $r['personId'],
				];
				if ($r['personCompany'])
					$data[$pndx]['firstName'] = '';

				$vcard = new \e10\persons\libs\Vcard($this->app());
				$vcard->setPerson($pndx);
				if ($this->personCompanyRecData)
					$vcard->setOrganization($this->personCompanyRecData['fullName']);
				$vcard->setExtension($this->contactsListRecData['vcardExt']);
				$vcard->setFunctionProperty($this->persFuncPropertyRecData);
				$vcard->run();

				$qrBtn = "<span class='pull-right' data-toggle='popover' data-trigger='hover' data-html='true' data-placement='left'";
				$qrBtn .= " data-content=\"<img style='max-width: 100%;' src='{$vcard->info['vcardQRCodeURL']}'>\"";
				$qrBtn .= " onmouseover='$(this).popover(\"show\")'";
				$qrBtn .= '>';
				$qrBtn .= $this->app()->ui()->icon('user/addressBook');
				$qrBtn .= '</span>';

				$data[$pndx]['qrBtn'] = ['code' => $qrBtn];
			}
		}

		$qp = [];
		array_push ($qp, 'SELECT props.recid as personNdx, props.valueString, props.property');
		array_push ($qp, ' FROM e10_base_properties AS props');
		array_push ($qp, ' WHERE tableid = %s', 'e10.persons.persons');
		array_push ($qp, ' AND EXISTS (SELECT ndx FROM e10pro_wkf_listPersons WHERE props.recid = e10pro_wkf_listPersons.person AND e10pro_wkf_listPersons.[list] = %i', $this->contactsListNdx, ')');

		$rows = $this->app->db()->query($qp);
		foreach ($rows as $r)
		{
			$pndx = $r['personNdx'];
			if ($r['property'] === 'email')
				$data[$pndx]['email'] = $r['valueString'];
			else
			if ($r['property'] === 'phone')
				$data[$pndx]['phone'] = $r['valueString'];

			if ($this->persFuncPropertyRecData && $this->persFuncPropertyRecData['id'] === $r['property'])
				$data[$pndx]['function'] = $r['valueString'];
		}

		$this->data = $data;
	}

	public function createCSV($fileName)
	{
		$h = [
			'personId' => 'id',
			'fullName' => 'Úplné jméno', 'firstName' => 'Jméno', 'lastName' => 'Příjmení',
			'function' => 'Funkce',
			'email' => 'E-mail', 'phone' => 'Telefon',
			/*, 'street' => 'Ulice', 'city' => 'Město', 'zipcode' => 'PSČ'*/];

		$params ['colSeparator'] = ',';
		$data = utils::renderTableFromArrayCsv($this->data, $h, $params);
		$BOM = chr(0xEF).chr(0xBB).chr(0xBF);
		file_put_contents($fileName, $BOM.$data);
	}

	public function createVCF($fileName, $saveSinglesPath = '')
	{
		//$BOM = chr(0xEF).chr(0xBB).chr(0xBF);
		//file_put_contents($fileName, $BOM);

		foreach ($this->data as $personNdx => $person)
		{
			$vcard = new \e10\persons\libs\Vcard($this->app());
			$vcard->setPerson($personNdx);
			if ($this->personCompanyRecData)
				$vcard->setOrganization($this->personCompanyRecData['fullName']);
			$vcard->setExtension($this->contactsListRecData['vcardExt']);
			$vcard->setFunctionProperty($this->persFuncPropertyRecData);
			$vcard->run();

			file_put_contents($fileName, $vcard->info['vcard'], FILE_APPEND);

			if ($saveSinglesPath !== '')
			{
				$ffn = $saveSinglesPath.'/'.$person['personId'];
				file_put_contents($ffn.'.vcf', $vcard->info['vcard']);

				if ($this->contactsListRecData && $this->contactsListRecData['bcQRCodeLinkMask'] !== '')
				{
					$qrLinkFN = $ffn.'-link.svg';
					$qrLink = $this->contactsListRecData['bcQRCodeLinkMask'] . $person['personId'];
					$cmd = "qrencode -8 -t SVG -m 0 --rle -o \"{$qrLinkFN}\" \"{$qrLink}\"";
					exec ($cmd);
				}

				copy($vcard->info['vcardQRCodeFullFileName'], $ffn.'.svg');
			}
		}
	}

	public function createZIP($fileName)
	{
		$tmpFolderName = __APP_DIR__.'/tmp/contactsList-'.time().'-'.mt_rand(100000,999999);
		mkdir ($tmpFolderName);

		$fnCSV = $tmpFolderName.'/'.'_contacts.csv';
		$this->createCSV($fnCSV);

		$fnVCF = $tmpFolderName.'/'.'_contacts.vcf';
		$this->createVCF($fnVCF, $tmpFolderName);

		exec ("cd $tmpFolderName && zip -r -9 $fileName *");
		exec ('rm -rf '.$tmpFolderName);
	}
}
