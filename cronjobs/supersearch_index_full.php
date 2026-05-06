<?php

use Xentral\Modules\SuperSearch\Scheduler\SuperSearchFullIndexTask;

$supersearchFullIndexTask = null;
try {
  /** @var SuperSearchFullIndexTask $supersearchFullIndexTask */
  $supersearchFullIndexTask = $app->Container->get('SuperSearchFullIndexTask');
  $supersearchFullIndexTask->execute();
  $supersearchFullIndexTask->cleanup();

} catch (\Exception $exception) {
  if ($supersearchFullIndexTask !== null) {
    $supersearchFullIndexTask->cleanup();
  }
  throw $exception;
}
