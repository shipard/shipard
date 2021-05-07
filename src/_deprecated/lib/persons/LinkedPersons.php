<?php

namespace lib\persons;
use e10\Utility, \e10\str;


/**
 * Class LinkedPersons
 * @package lib\persons
 */
class LinkedPersons extends Utility
{
	/** @var \e10\persons\TablePersons */
	var $tablePersons;

	var $tableId;
	var $recId = 0;
	var $recsIds = NULL;
	var $flags = 0;

	var $lp = [];
	var $personsNdxs = [];
	var $usedGroupsNdxs = [];
	var $maxGroupExpandMembers = 12;
	var $groupsMembers = [];

	const lpfHyperlinks       = 0x00000100,
				lpfNicknames				= 0x00000200,
				lpfExpandGroups			= 0x00000400
				;

	public function setFlags ($flags)
	{
		$this->flags = $flags;
	}

	public function setSource ($tableId, $recs)
	{
		$this->tableId = $tableId;
		if (is_array($recs))
			$this->recsIds = $recs;
		else
			$this->recId = $recs;

		$this->tablePersons = $this->app->table ('e10.persons.persons');
	}

	public function load()
	{
		$elementClass = '';

		$links = $this->app()->cfgItem ('e10.base.doclinks', NULL);

		if (!$links)
			return;

		if (!isset($links [$this->tableId]))
			return;

		$allLinks = $links [$this->tableId];


		$q = [];
		if ($this->recsIds && count($this->recsIds) === 0)
			return;

		array_push ($q, '(SELECT links.ndx, links.linkId AS linkId, links.dstTableId, links.srcRecId AS srcRecId, links.dstRecId AS dstRecId,');
		array_push ($q, ' persons.fullName AS fullName, persons.firstName AS firstName, persons.lastName AS lastName, persons.company AS company, persons.gender AS gender');
		array_push ($q, ' FROM e10_base_doclinks AS links');
		array_push ($q, ' LEFT JOIN e10_persons_persons as persons ON links.dstRecId = persons.ndx ');
		array_push ($q, ' WHERE srcTableId = %s', $this->tableId, ' AND dstTableId = %s', 'e10.persons.persons');
		if ($this->recsIds)
			array_push ($q, ' AND links.srcRecId IN %in', $this->recsIds);
		else
			array_push ($q, ' AND links.srcRecId = %i', $this->recId);
		array_push ($q, ')');

		array_push ($q, ' UNION ');

		array_push ($q, '(SELECT links.ndx, links.linkId AS linkId, links.dstTableId, links.srcRecId AS srcRecId, links.dstRecId AS dstRecId,');
		array_push ($q, ' groups.name AS fullName, %s, %s, 3, 0', '', '');
		array_push ($q, ' FROM e10_base_doclinks as links');
		array_push ($q, ' LEFT JOIN e10_persons_groups as groups ON links.dstRecId = groups.ndx ');
		array_push ($q, ' WHERE srcTableId = %s', $this->tableId, ' AND dstTableId = %s', 'e10.persons.groups');
		if ($this->recsIds)
			array_push ($q, ' AND links.srcRecId IN %in', $this->recsIds);
		else
			array_push ($q, ' AND links.srcRecId = %i', $this->recId);
		array_push ($q, ')');

		$query = $this->db()->query ($q);
		foreach ($query as $r)
		{
			$linkId = $r['linkId'];
			if (!isset($this->lp [$r['srcRecId']][$linkId]))
			{
				$this->lp [$r['srcRecId']][$linkId] = [
					'icon' => $allLinks[$linkId]['icon'], 'name' => $allLinks[$linkId]['name'],
					'labels' => [],
					'groupsNdxs' => [], 'personsNdxs' => []
				];
			}

			$personLabel = [
				'text' => $r ['fullName'],
				'class' => $elementClass,
				'ndx' => $r['dstRecId'],
			];

			$this->personsNdxs[] = $r['dstRecId'];

			$icon = 'icon-check';
			if ($r['dstTableId'] === 'e10.persons.persons')
			{
				$icon = $this->tablePersons->tableIcon($r);

				if ($this->flags & self::lpfHyperlinks)
				{
					$personLabel['table'] = 'e10.persons.persons';
					$personLabel['pk'] = $r['dstRecId'];
					$personLabel['docAction'] = 'edit';
				}
			}
			elseif ($r['dstTableId'] === 'e10.persons.groups')
			{
				$icon = 'icon-users';

				if (!in_array($r['dstRecId'], $this->usedGroupsNdxs))
					$this->usedGroupsNdxs[] = $r['dstRecId'];

				$this->lp [$r['srcRecId']][$linkId]['groupsNdxs'][] = $r['dstRecId'];
			}

			$personLabel['icon'] = $icon;


			if ($this->flags & self::lpfNicknames && $r['dstTableId'] === 'e10.persons.persons')
				$personLabel['text'] = $r['firstName'].' '.str::substr($r['lastName'], 0, 1).'.';

			//if ($this->flags & self::lpfExpandGroups && $r['dstTableId'] === 'e10.persons.groups')
			//	continue;

			$this->lp [$r['srcRecId']][$linkId]['labels'][] = $personLabel;
		}

		if ($this->flags & self::lpfExpandGroups)
			$this->expandGroups();
	}

	function expandGroups()
	{
		if (!count($this->usedGroupsNdxs))
			return;

		$q[] = 'SELECT grps.*,';
		array_push ($q, ' persons.fullName AS fullName, persons.firstName AS firstName, persons.lastName AS lastName, persons.company AS company');
		array_push ($q, ' FROM [e10_persons_personsgroups] AS grps');
		array_push ($q, ' LEFT JOIN [e10_persons_persons] AS persons ON grps.person = persons.ndx');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND grps.[group] IN %in', $this->usedGroupsNdxs);
		array_push ($q, ' LIMIT 300');

		$rows = $this->db()->query ($q);
		foreach ($rows as $r)
		{
			$personInfo = [
				'text' => $r['fullName'],
				'ndx' => $r['person'],
				'class' => 'e10-small',
				//'icon' => $this->tablePersons->tableIcon($r),
			];

			if ($this->flags & self::lpfNicknames)
				$personInfo['text'] = $r['firstName'].' '.str::substr($r['lastName'], 0, 1).'.';

			$this->groupsMembers[$r['group']][] = $personInfo;
		}

		$this->appendGroupsMembers();
	}

	function appendGroupsMembers()
	{
		foreach ($this->lp as $srcRecNdx => $lp)
		{
			foreach ($lp as $linkId => $linkMembers)
			{
				$cnt = 0;
				foreach ($linkMembers['groupsNdxs'] as $gndx)
				{
					if (!isset($this->groupsMembers[$gndx]))
						continue;

					foreach ($this->groupsMembers[$gndx] as $gm)
					{
						if (in_array($gm['ndx'], $this->lp [$srcRecNdx][$linkId]['personsNdxs']))
							continue;
						$this->lp [$srcRecNdx][$linkId]['labels'][] = $gm;
						$this->lp [$srcRecNdx][$linkId]['personsNdxs'][] = $gm['ndx'];

						if ($cnt > $this->maxGroupExpandMembers)
						{
							$this->lp [$srcRecNdx][$linkId]['labels'][] = [
								'text' => 'a další...',
								'class' => 'e10-small',
							];
							break;
						}
						$cnt++;
					}
				}
			}
		}
	}
}

