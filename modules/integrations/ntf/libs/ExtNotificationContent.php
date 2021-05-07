<?php

namespace integrations\ntf\libs;
use \e10\Utility, e10\utils;


/**
 * Class ExtNotificationContent
 * @package integrations\ntf\libs
 */
class ExtNotificationContent extends Utility
{
	CONST msDefault = 0, msSuccess = 1, msWarning = 2, msError = 3, msInfo = 4;

	var $content = [];
	var $code = '';

	var $statesBgColors = ['#fefefe', '#9fe28f', '#ffe0e0', '#ffb0b0', '#e0fffa'];
	var $statesColors = ['#333333', '#000000', '#991010', '#000000', '#0000A0'];

	public function setContent($content)
	{
		$this->content = $content;
	}

	public function setTitle($title)
	{
		$this->content['title'] = $title;
	}

	public function setText($text)
	{
		$this->content['text'] = $text;
	}

	public function setMsgTextPlain($text)
	{
		$this->content['msgTextPlain'] = $text;
	}

	public function setState($state)
	{
		$this->content['state'] = $state;
	}

	public function addProperty($name, $text)
	{
		$this->content['properties'][] = ['n' => $name, 't' => $text];
	}

	public function createCode()
	{
		if (!isset($this->content['state']))
			$this->content['state'] = 0;

 		$this->code .= "<b><span data-mx-color='".$this->statesColors[$this->content['state']]."' data-mx-bg-color='{$this->statesBgColors[$this->content['state']]}'>".utils::es($this->content['title'])."</span></b><br>";
		$this->code .= "<i>".utils::es($this->app->cfgItem ('options.core.ownerFullName')).'</i><br>';

		if (isset($this->content['text']) && $this->content['text'] !== '')
			$this->code .= '<code><pre>'.$this->content['text'].'</pre></code>';

		if (isset($this->content['properties']) && count($this->content['properties']))
		{
			$this->code .= "<ul>";
			foreach ($this->content['properties'] as $p)
				$this->code .= '<li><b>'.utils::es($p['n']).':</b> '.utils::es($p['t']).'</li>';
			$this->code .= "</ul>";
		}
	}
}
