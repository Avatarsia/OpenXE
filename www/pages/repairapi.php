<?php
// www/pages/repairapi.php
// Stateless API -- keine Session

class Repairapi
{
    /** @var \ApplicationCore */
    public $app;

    function __construct($app, $intern = false)
    {
        $this->app = $app;
        if ($intern) return;

        $this->app->ActionHandlerInit($this);
        $this->app->ActionHandler('push_details', 'HandlePushDetails');
        $this->app->ActionHandlerListen($app);
    }

    function HandlePushDetails()
    {
        $controller = $this->app->Container->get('RepairApiController');
        $controller->handlePushDetails();
        $this->app->ExitStandard();
    }
}
