<?php

namespace e10doc\core\libs\dc;


/**
 * class DCWitemAnalysis
 */
class DCWitemAnalysis extends \Shipard\Base\DocumentCard
{
	public function createContentBody ()
	{
		if ($this->recData['itemKind'] == 1)
		{
			$this->addContent ('body', [
				'sumTable' => [
					'objectId' => 'e10doc.inventory.libs.SumTableItemAnalysis',
					'queryParams' => ['item_ndx' => $this->recData['ndx']]
				]
			]);
		}

    $this->addContent ('body', [
      'sumTable' => [
        'objectId' => 'e10doc.core.libs.SumTableItemAnalysis',
        'queryParams' => ['item_ndx' => $this->recData['ndx']]
      ]
    ]);


		if ($this->recData['isSet'])
		{
      $q = [];
      array_push($q, 'SELECT itemsSets.*,');
      array_push($q, ' witems.fullName AS itemName');
      array_push($q, ' FROM [e10_witems_itemsets] AS itemsSets');
      array_push($q, ' LEFT JOIN [e10_witems_items] AS witems ON itemsSets.item = witems.ndx');
      array_push($q, ' WHERE [setItemType] = %i', 0);
      array_push($q, ' AND [itemOwner] = %i', $this->recData['ndx']);
			$invItems = $this->db()->query($q);
      foreach ($invItems as $setInvItem)
      {
        $this->addContent ('body', [
          'sumTable' => [
            'objectId' => 'e10doc.inventory.libs.SumTableItemAnalysis',
            'queryParams' => ['item_ndx' => $setInvItem['item']],
            'options' => [
              'headerTitle' => [
                ['text' => $setInvItem['itemName'], 'class' => 'h2'],
                ['text' => 'Pohyby zásob položky ze sady', 'class' => 'e10-small break'],
              ]
            ]
          ]
        ]);
      }
		}
	}

	public function createContent ()
	{
    $this->createContentBody ();
	}
}
