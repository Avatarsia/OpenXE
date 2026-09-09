<?php

use Xentral\Modules\SuperSearch\Scheduler\SuperSearchDiffIndexTask;

$supersearchDiffIndexTask = null;
try {
  /** @var SuperSearchDiffIndexTask $supersearchDiffIndexTask */
  $supersearchDiffIndexTask = $app->Container->get('SuperSearchDiffIndexTask');
  $supersearchDiffIndexTask->execute();
  $supersearchDiffIndexTask->cleanup();

} catch (\Exception $exception) {
  if ($supersearchDiffIndexTask !== null) {
    try {
      $supersearchDiffIndexTask->cleanup();
    } catch (\Throwable $cleanupError) {
      error_log('SuperSearchDiffIndexTask cleanup failed: ' . $cleanupError->getMessage());
    }
  }
  throw $exception;
}
