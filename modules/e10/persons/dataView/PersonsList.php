<?php

namespace e10\persons\dataView;

use \lib\dataView\DataView, \e10\utils;


/**
 * Class personsList
 * @package e10\persons\dataView
 */
class PersonsList extends DataView
{
	var $tablePersons;
	var $mainGroup = 0;
	var $enablePhone = FALSE;
	var $enableEmail = TRUE;

	protected function init()
	{
		parent::init();
		$this->tablePersons = $this->app()->table('e10.persons.persons');

		$this->checkRequestParamsList('id', TRUE);
		$this->checkRequestParamsList('contacts', TRUE);

		if (isset($this->requestParams['contacts']))
		{
			$this->enablePhone = in_array('phone', $this->requestParams['contacts']);
			$this->enableEmail = in_array('email', $this->requestParams['contacts']);
		}
	}

	protected function loadData()
	{
		$q [] = 'SELECT persons.*';
		array_push ($q, ' FROM [e10_persons_persons] AS persons');
		array_push ($q, ' WHERE 1');
		array_push ($q, ' AND persons.docStateMain < %i', 3);

		// -- mainGroup
		if ($this->mainGroup)
			array_push ($q, ' AND EXISTS ',
				'(SELECT ndx FROM e10_persons_personsgroups WHERE persons.ndx = e10_persons_personsgroups.person and [group] = %i)', $this->mainGroup);

		if (isset($this->requestParams['id']))
			array_push ($q, ' AND persons.[id] IN %in', $this->requestParams['id']);

		$this->extendQuery($q);

		array_push ($q, ' ORDER BY persons.[lastName], persons.[firstName], persons.[ndx]');
		array_push ($q, ' LIMIT 0, 128');

		$t = [];
		$pks = [];

		// -- persons
		$rows = $this->db()->query($q);
		foreach ($rows as $r)
		{
			$item = ['ndx' => $r['ndx'], 'id' => $r['id'], 'fullName' => $r['fullName']];

			$t[$r['ndx']] = $item;
			$pks[] = $r['ndx'];
		}

		// -- properties
		$personsProperties = \E10\Base\getPropertiesTable ($this->app, 'e10.persons.persons', $pks);
		foreach ($personsProperties as $personNdx => $pp)
		{
			$t[$personNdx]['properties'] = $pp;

			if (isset($pp['contacts']['email'][0]))
				$t[$personNdx]['email'] = $pp['contacts']['email'][0]['value'];
			if (isset($pp['contacts']['phone'][0]))
				$t[$personNdx]['phone'] = $pp['contacts']['phone'][0]['value'];

			foreach ($pp as $pgId => $pgPp)
			{
				foreach ($pgPp as $ppId => $ppItem)
				{
					$t[$personNdx]['pp'][$ppId] = $ppItem[0]['value'];
				}
			}
		}

		// -- images
		$images = \e10\base\getDefaultImages ($this->app(), 'e10.persons.persons', $pks, ['-q76']);
		foreach ($images as $personNdx => $personsImage)
		{
			$t[$personNdx]['image'] = $personsImage;
		}

		// -- check order by param `id`
		if (isset($this->requestParams['id']))
		{
			$newTable = [];
			foreach ($this->requestParams['id'] as $personId)
			{
				$p = \e10\base\searchArrayItem($t, 'id', $personId);
				if ($p)
					$newTable[] = $p;
			}
			$t = $newTable;
		}

		$this->data['header'] = ['#' => '#', 'id' => 'id', 'fullName' => 'Jméno', 'email' => 'E-mail', 'phone' => 'Telefon'];
		$this->data['table'] = $t;
	}

	public function setMainGroup ($group)
	{
		$groupsMap = $this->tablePersons->app()->cfgItem ('e10.persons.groupsToSG', FALSE);
		if ($groupsMap && isset ($groupsMap [$group]))
			$this->mainGroup = $groupsMap [$group];
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'contactCards')
			return $this->renderDataAsContactCards();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsContactCards()
	{
		$c = '';

		$subtitlePropertyId = $this->requestParam('subtitleProperty', '');

		$cardClass = utils::es($this->requestParam('cardClass', 'contact-cards-b'));

		$c .= "<div class='card-deck $cardClass'>";
		foreach ($this->data['table'] as $person)
		{
			$imgSrc = $this->app->dsRoot.'/e10-modules/e10/server/css/user-gray.svg';
			if (isset($person['image']))
				$imgSrc = $person['image']['smallImage'];

			$c .= "<div class='card'>";
				$c .= "<div class='card-header'></div>";
				$c .= "<div class='card-picture' style='background-image:url($imgSrc);'></div>";
        $c .= "<div class='card-body'>";
					$c .= '<h5>'.utils::es($person['fullName']).'</h5>';
					if ($subtitlePropertyId !== '' && isset($person['pp'][$subtitlePropertyId]))
						$c .= "<p class='card-text'>".utils::es($person['pp'][$subtitlePropertyId])."</p>";
					$c .= "<p class='card-text'>";
					if (isset($person['email']) && $this->enableEmail)
						$c .= "<span class='text-nowrap'><i class='fa fa-fw fa-envelope-o'></i> <a href='mailto:".utils::es($person['email'])."'>".utils::es($person['email']).'</a></span><br>';
					if (isset($person['phone']) && $this->enablePhone)
						$c .= "<i class='fa fa-fw fa-phone'></i> ".utils::es($person['phone']).'<br>';
					$c .= "</p>";
				$c .= "</div>";
      $c .= "</div>";
		}
		$c .= '</div>';

		return $c;
	}
}


