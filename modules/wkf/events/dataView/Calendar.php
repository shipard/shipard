<?php

namespace wkf\events\dataView;
use \lib\dataView\DataView;
use \Shipard\Utils\Utils;
use \Shipard\Utils\Json;


/**
 * class
 */
class Calendar extends DataView
{
	var $today;
  var \wkf\events\libs\CalendarEngine $calendar;


	protected function init()
	{
		parent::init();


		$this->requestParams['showAs'] = strval($this->app()->requestPath(3));

		if ($this->requestParams['showAs'] === '' && $this->app()->testGetParam('showAs') !== '')
			$this->requestParams['showAs'] = $this->app()->testGetParam('showAs');

		if ($this->requestParams['showAs'] === '')
			$this->requestParams['showAs'] = 'html';

		$this->today = Utils::today('Y-m-d');
    $this->calendar = new \wkf\events\libs\CalendarEngine ($this->app());
    $this->calendar->setAgendaView($this->today);
    $this->calendar->init();
    $this->calendar->loadEvents();

    $this->data['events'] = $this->calendar->events;
	}

	protected function loadData()
	{
	}

	protected function renderDataAs($showAs)
	{
		if ($showAs === 'html')
    	return $this->renderDataAsHtml();
		if ($showAs === 'json')
    	return $this->renderDataAsJson();

		return parent::renderDataAs($showAs);
	}

	protected function renderDataAsHtml()
	{
		$c = '';

    foreach ($this->calendar->events as $event)
    {
      $c .= "<h2 class='eventTitle'>";
      $c .= Utils::es($event['subject']);
      $c .= '</h2>';
      if ($event['multiDays'])
      {
        $c .= "<span class='eventDate'>";
        $c .= Utils::datef($event['dateBegin'], '%d');
        $c .= ' '.$event['timeBegin'];
        $c .= ' - ';
        $c .= Utils::datef($event['dateEnd'], '%d');
        $c .= ' '.$event['timeEnd'];
        $c .= '</span>';
      }
      else
      {
        $c .= "<span class='eventDate'>";
        $c .= Utils::datef($event['dateBegin']);
        $c .= ' '.$event['timeBegin'];
        $c .= ' - '.$event['timeEnd'];
        $c .= '</span>';
      }

      if ($event['placeDesc'] !== '')
      {
        $c .= "<span class='eventPlace'>";
        $c .= ' - ';
        $c .= $event['placeDesc'];
        $c .= '</span>';
      }
    }

    //$c .= '<br><pre>'.Json::lint($this->calendar->events).'</pre>';

		return $c;
	}

	protected function renderDataAsJson()
	{
	}
}
