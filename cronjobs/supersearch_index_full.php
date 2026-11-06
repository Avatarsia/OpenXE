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
    try {
      $supersearchFullIndexTask->cleanup();
    } catch (\Throwable $cleanupError) {
      error_log('SuperSearchFullIndexTask cleanup failed: ' . $cleanupError->getMessage());
    }
  }
  throw $exception;
}
