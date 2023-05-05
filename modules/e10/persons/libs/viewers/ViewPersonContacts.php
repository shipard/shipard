<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;
use \Shipard\Utils\Utils;

/**
 * class ViewPersonContacts
 */
class ViewPersonContacts extends TableView
{
	var $personNdx = 0;
	var $classification = [];
	var $sendReports;

	public function init ()
	{
		$this->enableDetailSearch = TRUE;
		$this->objectSubType = TableView::vsDetail;

    $this->personNdx = intval($this->queryParam('personNdx'));
		$this->addAddParam('person', $this->personNdx);

		$this->toolbarTitle = ['text' => 'Adresy', 'class' => 'h2 e10-bold'/*, 'icon' => 'system/iconMapMarker'*/];
		$this->setMainQueries();

		parent::init();
	}

	public function selectRows ()
	{
		$fts = $this->fullTextSearch ();

		$q [] = 'SELECT [contacts].* ';
		array_push ($q, ' FROM [e10_persons_personsContacts] AS [contacts]');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND [contacts].[person] = %i', $this->personNdx);

		// -- fulltext
		if ($fts != '')
		{
			array_push ($q, ' AND (');
			array_push ($q, ' [contacts].adrCity LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [contacts].adrStreet LIKE %s', '%'.$fts.'%');
			array_push ($q, ' OR [contacts].adrSpecification LIKE %s', '%'.$fts.'%');
			array_push ($q, ')');
		}

		$this->queryMain ($q, '[contacts].', ['[onTop]', '[systemOrder]', '[adrCity]', '[ndx]']);
		$this->runQuery ($q);

		$this->runQuery ($q);
	}

	public function selectRows2 ()
	{
		if (!count ($this->pks))
			return;

		$this->classification = UtilsBase::loadClassification ($this->table->app(), $this->table->tableId(), $this->pks);

		$this->sendReports = UtilsBase::linkedSendReports($this->app(), $this->table, $this->pks);
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		//$listItem ['icon'] = $this->table->tableIcon ($item);

    $address = '';
    $addressFlags = [];

		if ($item['onTop'] != 99)
			$listItem['i1'] = ['text' => '', 'icon' => 'system/iconPinned', 'class' => 'id'];

    if ($item['flagOffice'] || $item['flagMainAddress'])
    {
			$address = $this->renderRow_addressText ($item);
			$addressFlags = $this->renderRow_addressFlags ($item);

      $listItem['t1'] = ['text' => $address, 'class' => '', 'icon' => 'tables/e10.base.places'];
      if (count($addressFlags))
        $listItem['t2'] = $addressFlags;

			if ($item['flagContact'])
			{
				$cf = $this->renderRow_contactFlags($item);
				$listItem['t3'] = [['text' => $item['contactName'], 'class' => '', 'icon' => 'system/iconUser']];
				if (count($cf))
					$listItem['t3'][] = $cf;
			}
		}
		elseif ($item['flagAddress'] && $item['flagContact'])
    {
			$listItem['t1'] = ['text' => $item['contactName'], 'class' => '', 'icon' => 'system/iconUser'];
			$address = $this->renderRow_addressText ($item);
			$addressFlags = $this->renderRow_addressFlags ($item);
			$cf = $this->renderRow_contactFlags($item);

			if (count($cf))
				$listItem['t2'] = $cf;

			$listItem['t3'] = [['text' => $address, 'class' => '', 'icon' => 'tables/e10.base.places']];

			if (count($addressFlags))
				$listItem['t3'][] = $addressFlags;

		}
		elseif ($item['flagAddress'] && !$item['flagContact'])
    {
			$address = $this->renderRow_addressText ($item);
			$addressFlags = $this->renderRow_addressFlags ($item);

      $listItem['t1'] = ['text' => $address, 'class' => '', 'icon' => 'tables/e10.base.places'];
      if (count($addressFlags))
        $listItem['t2'] = $addressFlags;

		}
		elseif ($item['flagContact'])
    {
			$listItem['t1'] = ['text' => $item['contactName'], 'class' => '', 'icon' => 'system/iconUser'];
			$cf = $this->renderRow_contactFlags($item);
      if (count($cf))
        $listItem['t2'] = $cf;
    }

		return $listItem;
	}

	public function renderRow_addressText ($item)
	{
		$ap = [];

		if ($item['adrSpecification'] != '')
			$ap[] = $item['adrSpecification'];
		if ($item['adrStreet'] != '')
			$ap[] = $item['adrStreet'];
		if ($item['adrCity'] != '')
			$ap[] = $item['adrCity'];
		if ($item['adrZipCode'] != '')
			$ap[] = $item['adrZipCode'];

		$country = World::country($this->app(), $item['adrCountry']);
		$ap[] = /*$country['f'].' '.*/$country['t'];

		$address = implode(', ', $ap);
		return $address;
	}

	public function renderRow_addressFlags ($item)
	{
		$addressFlags = [];
		if ($item['flagMainAddress'])
		$addressFlags[] = ['text' => 'Sídlo', 'class' => 'label label-default'];
		if ($item['flagPostAddress'])
			$addressFlags[] = ['text' => 'Korespondenční', 'class' => 'label label-default'];
		if ($item['flagOffice'])
			$addressFlags[] = ['text' => 'Provozovna', 'class' => 'label label-default'];

		if ($item['id1'] !== '')
			$addressFlags[] = ['text' => 'IČP: '.$item['id1'], 'class' => 'label label-default'];
		if ($item['id2'] !== '')
			$addressFlags[] = ['text' => 'IČZ: '.$item['id2'], 'class' => 'label label-default'];

		if (!Utils::dateIsBlank($item['validTo']))
			$addressFlags[] = ['text' => 'Platné do: '.Utils::datef($item['validTo']), 'class' => 'label label-danger'];

		return $addressFlags;
	}

	public function renderRow_contactFlags ($item)
	{
		$cf = [];
		if ($item['contactRole'] != '')
			$cf[] = ['text' => $item['contactRole'], 'class' => 'label label-default'];
		if ($item['contactEmail'] != '')
			$cf[] = ['text' => $item['contactEmail'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
		if ($item['contactPhone'] != '')
			$cf[] = ['text' => $item['contactPhone'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];

		return $cf;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{

			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}

		if (isset ($this->sendReports [$item ['pk']]))
		{
			$item ['t2'][] = ['text' => '', 'class' => '', 'icon' => 'system/iconPaperPlane'];
			$item ['t2'] = array_merge ($item ['t2'], $this->sendReports [$item ['pk']]);
		}
	}

	public function createToolbar ()
	{
		$toolbar = parent::createToolbar();

		$reg = new \e10\persons\libs\register\PersonRegister($this->app());
		$companyId = $reg->loadPersonOid($this->personNdx);

		if ($companyId !== '')
		{
			$toolbar[] = [
				'text' => 'Provozovny', 'type' => 'action', 'action' => 'addwizard', 'icon' => 'user/wifi',
				'title' => 'Načíst provozovny z registrů',
				'class' => 'pull-right',
				'element' => 'span',
				'btnClass' => 'pull-right',
				'data-class' => 'e10.persons.libs.register.AddOfficesWizard',
				'table' => 'e10.persons.persons',
				'data-addparams' => 'personId='.$companyId.'&personNdx='.$this->personNdx,
				'data-srcobjecttype' => 'viewer', 'data-srcobjectid' => $this->vid,
			];
		}

		return $toolbar;
	}
}
