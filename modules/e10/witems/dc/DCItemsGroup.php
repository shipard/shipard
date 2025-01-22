<?php

namespace e10\witems\dc;


/**
 * Class DCItemsGroup
 */
class DCItemsGroup extends \e10\DocumentCard
{
	public function createContentBody()
	{
		$this->createContentBody_Items();
	}

	public function createContentBody_Items()
	{
		$q = [];
    array_push ($q, 'SELECT [ig].* ');
		array_push ($q, ', [items].[fullName] AS [itemFullName], [items].[id] AS [itemId]');
		array_push ($q, ' FROM [e10_witems_itemsGroupsItems] AS [ig]');
    array_push ($q, ' LEFT JOIN [e10_witems_items] AS [items] ON [ig].[item] = [items].[ndx]');
		array_push ($q, ' WHERE 1');
    array_push ($q, ' AND [ig].docState != %i', 9000);
		array_push ($q, ' AND [itemsGroup] = %i', $this->recData['ndx']);
    array_push ($q, ' ORDER BY [fullName], [ig].[ndx]');

		$t = [];
		$h = ['itemId' => 'ID', 'item' => 'Položka', ];

		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$i = [
				'itemId' => ['text' => $r['itemId'], 'pk' => $r['item'], 'docAction' => 'edit', 'table' => 'e10.witems.items'],
				'item' => $r['itemFullName'],
			];

			$t[] = $i;
		}

		$this->addContent ('body', [
			'pane' => 'e10-pane e10-pane-table', 'paneTitle' => 'Položky',
			'type' => 'table', 'table' => $t, 'header' => $h
		]);
	}

	public function createContent ()
	{
		$this->createContentBody ();
	}
}
