<?php

namespace services\subjects;

require_once __APP_DIR__ . '/e10-modules/e10/base/tables/nomencItems.php';


/**
 * Class ViewerNomencItemsNACE
 * @package services\subjects
 */
class ViewerNomencItemsNACE extends \e10\base\ViewNomencItemsCombo
{
	function qryDefault (&$q)
	{
		array_push($q, ' AND [nomencType] = %i', 2);
	}
}
