<?php

namespace ui\mobile;


/**
 * Class Camera
 * @package mobileui
 */
class Camera extends \Shipard\UI\OldMobile\PageObject
{
	public function createContentCodeInside ()
	{
		$c = '';

		$c = "<div class='e10-att-input-upload' data-table='e10.persons.persons' data-pk='684'>
						<h2>Vyfoťte něco </h2>
						<input class='e10-att-input-file' name='add-files' data-name='add-files' type='file' accept='image/*' capture onchange='e10AttWidgetFileSelected(this)' multiple='multiple'/>
						<div class='e10-att-input-files'>až to bude, dejte odeslat...</div>
						<div class='e10-att-input-send'><input type='button' onclick='e10AttWidgetUploadFile($(this))' value='Odeslat'/></div>
				 </div>";

		return $c;
	}

	public function title1 ()
	{
		return 'Fotoaparát';
	}

	public function leftPageHeaderButton ()
	{
		$parts = explode ('.', $this->definition['itemId']);
		$lmb = ['icon' => PageObject::backIcon, 'path' => '#'.$parts['0']];
		return $lmb;
	}
}
