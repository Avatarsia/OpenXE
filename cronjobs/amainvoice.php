<?php
use Xentral\Modules\AmaInvoice\Scheduler\AmaInvoiceTask;
if(!$app->erp->ModulVorhanden('amainvoice')) {
  return;
}
$amaInvoiceTask = null;
try {
  /** @var AmaInvoiceTask $amaInvoiceTask */
  $amaInvoiceTask = $app->Container->get('AmaInvoiceTask');
  $amaInvoiceTask->execute();
  $amaInvoiceTask->cleanup();

} catch (\Exception $exception) {
  if ($amaInvoiceTask !== null) {
    try {
      $amaInvoiceTask->cleanup();
    } catch (\Throwable $cleanupError) {
      error_log('AmaInvoiceTask cleanup failed: ' . $cleanupError->getMessage());
    }
  }
  throw $exception;
}
