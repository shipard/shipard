<?php

namespace e10pro\bume\dataView;
use \lib\dataView\DataView;


/**
 * class Vcard
 */
class Vcard extends DataView
{
  var $personNdx = 0;
	var $personCompanyRecData = NULL;
	var $contactsListNdx = 0;
	var $contactsListRecData = NULL;
	var $persFuncPropertyRecData = NULL;
  var ?\e10\persons\libs\Vcard $vcard = NULL;

	protected function init()
	{
		parent::init();

		if (intval($this->requestParams['companyNdx'] ?? 0))
		{
			$this->personCompanyRecData = $this->app()->loadItem(intval($this->requestParams['companyNdx'] ?? 0), 'e10.persons.persons');
		}
		if (intval($this->requestParams['extVcardList'] ?? 0))
		{
			$this->contactsListNdx = intval($this->requestParams['extVcardList'] ?? 0);
			$this->contactsListRecData = $this->app()->loadItem($this->contactsListNdx, 'e10pro.bume.lists');

			if ($this->contactsListRecData && $this->contactsListRecData['vcardPersFuncProperty'] !== 0)
			{
				$this->persFuncPropertyRecData = $this->app()->loadItem($this->contactsListRecData['vcardPersFuncProperty'], 'e10.base.propdefs');
			}
		}

		if (isset($this->requestParams['personId']))
		{
			if ($this->requestParams['personId'] === 'URL')
      {
				$this->requestParams['personId'] = $this->app()->requestPath(count($this->app()->requestPath) - 1);
        if (str_ends_with($this->requestParams['personId'], '.vcf'))
          $this->requestParams['personId'] = substr($this->requestParams['personId'], 0, -4);
      }

			$rows = $this->db()->query('SELECT ndx FROM [e10_persons_persons] WHERE [id] = %s', $this->requestParams['personId'],
                                  ' AND [docState] IN %in', [4000, 8000]);
			if ($rows)
			{
				foreach ($rows as $r)
        {
					$this->personNdx = $r['ndx'];
          break;
        }
			}
		}
	}

	protected function loadData()
	{
    $this->vcard = new \e10\persons\libs\Vcard($this->app());
    $this->vcard->setPerson($this->personNdx);
		if ($this->personCompanyRecData)
			$this->vcard->setOrganization($this->personCompanyRecData['fullName']);
		if ($this->contactsListRecData)
			$this->vcard->setExtension($this->contactsListRecData['vcardExt']);
		$this->vcard->setFunctionProperty($this->persFuncPropertyRecData);
    $this->vcard->run();
	}

	protected function renderDataAs($showAs)
	{
    return $this->renderDataAsVcard();
	}

	protected function renderDataAsVcard()
	{
    if ($this->personNdx)
    {
      $this->template->data['forceCode'] = $this->vcard->info['vcard'];
      $this->template->data['forceMimeType'] = 'text/vcard';

      return;
    }

    $this->template->data['forceCode'] = 'kontakt neexistuje / contact not found';
    $this->template->data['forceMimeType'] = 'text/plain';
    $this->template->data['forceStatus'] = 404;
	}
}
