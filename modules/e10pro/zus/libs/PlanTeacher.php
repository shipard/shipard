<?php

namespace e10pro\zus\libs;
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/zus.php';
require_once __SHPD_MODULES_DIR__ . 'e10pro/zus/WidgetPlan.php';

use \Shipard\Base\Utility;


/**
 * class PlanTeacher
 */
class PlanTeacher extends Utility
{
  var \e10pro\zus\PlanTeacher $plan;
  var $code = '';

  public function getPlan($teacherNdx, $academicYear)
  {
    $this->plan = new \e10pro\zus\PlanTeacher ($this->app);
    $this->plan->setYear($academicYear);
    $this->plan->setteacher($teacherNdx);
    $this->plan->init();
    $this->plan->load();

    $this->code = $this->plan->renderPlan();
  }
}
