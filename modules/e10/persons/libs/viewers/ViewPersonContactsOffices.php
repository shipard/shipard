<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;

/**
 * class ViewPersonContactsOffices
 */
class ViewPersonContactsOffices extends TableView
{
	var $personNdx = 0;
	var $classification = [];

	public function init ()
	{
    $this->fullWidthToolbar = TRUE;
		$this->rowsPageSize = 500;

		$this->objectSubType = TableView::vsMini;

    $this->personNdx = intval($this->queryParam('personNdx'));
		$this->addAddParam('person', $this->personNdx);

		$this->toolbarTitle = ['text' => 'Provozovny', 'class' => 'h2 e10-bold'/*, 'icon' => 'system/iconMapMarker'*/];
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
    array_push ($q, ' AND [contacts].[flagOffice] = %i', 1);

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
	}

	public function renderRow ($item)
	{
		$listItem ['pk'] = $item ['ndx'];
		//$listItem ['icon'] = $this->table->tableIcon ($item);

    $address = '';
    $addressFlags = [];

    if ($item['flagAddress'])
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

      $listItem['t1'] = $address;

      if (count($addressFlags))
        $listItem['t2'] = $addressFlags;

			if ($item['flagContact'])
			{
				$cf = [];
				if ($item['contactName'] != '')
					$cf[] = ['text' => $item['contactName'], 'class' => 'label label-default'];
				if ($item['contactRole'] != '')
					$cf[] = ['text' => $item['contactRole'], 'class' => 'label label-default'];
				if ($item['contactEmail'] != '')
					$cf[] = ['text' => $item['contactEmail'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
				if ($item['contactPhone'] != '')
					$cf[] = ['text' => $item['contactPhone'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];

				if (count($cf))
					$listItem['t3'] = $cf;
			}
		}
		elseif ($item['flagContact'])
    {
			$listItem['t1'] = $item['contactName'];

			$cf = [];
			if ($item['contactRole'] != '')
        $cf[] = ['text' => $item['contactRole'], 'class' => 'label label-default'];
      if ($item['contactEmail'] != '')
        $cf[] = ['text' => $item['contactEmail'], 'class' => 'label label-default', 'icon' => 'system/iconEmail'];
      if ($item['contactPhone'] != '')
        $cf[] = ['text' => $item['contactPhone'], 'class' => 'label label-default', 'icon' => 'system/iconPhone'];

      if (count($cf))
        $listItem['t2'] = $cf;
    }

		return $listItem;
	}

	function decorateRow (&$item)
	{
		if (isset ($this->classification [$item ['pk']]))
		{
			forEach ($this->classification [$item ['pk']] as $clsfGroup)
				$item ['t2'] = array_merge ($item ['t2'], $clsfGroup);
		}
	}

	public function createToolbar ()
	{
		return [];
	}
}
