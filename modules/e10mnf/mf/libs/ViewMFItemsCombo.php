<?php

namespace e10mnf\mf\libs;


/**
 * class ViewMFItemsCombo
 */
class ViewMFItemsCombo extends \E10\Witems\ViewItems
{
  public function qryCommon (array &$q)
	{
    array_push ($q, ' AND [items].[itemKind] = %i', 1);
	}
}
