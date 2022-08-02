<?php

namespace hosting\core\libs;


/**
 * class ViewerProjectsGrid
 */
class ViewerProjectsGrid extends \plans\core\libs\ViewItemsGrid
{
  public function init ()
	{
    $this->fixedMainQuery = 'active';

		parent::init();
  }

  public function createBottomTabs ()
	{
  }

  public function mainQueryId ()
	{
    return $this->fixedMainQuery;
  }

  public function createToolbar ()
	{
		return [];
	}
}
