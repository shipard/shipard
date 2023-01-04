<?php

namespace e10\persons\libs\viewers;
use \Shipard\Viewer\TableView;
use \e10\base\libs\UtilsBase;
use \Shipard\Utils\World;

/**
 * class ViewPersonContacts
 */
class ViewPersonContacts extends TableView
{
	var $personNdx = 0;
	var $classification = [];

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

		$this->queryMain ($q, '[contacts].', ['[adrCity]', '[ndx]']);
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
		$at = $this->addressTypes[$item['type']];

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

      $listItem['t1'] = $address;

      if (count($addressFlags))
        $listItem['t2'] = $addressFlags;
    }

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

      if (count($addressFlags))
        $listItem['t3'] = $cf;
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
}
