<?php

namespace e10mnf\mf\libs;


/**
 * class ViewMFItems
 */
class ViewMFItems extends \e10\witems\libs\ViewerItemsByCategories
{
  public function qryCommon (array &$q)
	{
    array_push ($q, " AND [items].[itemKind] = %i", 1);

	}
}


