<?php

// Add Module for debuging purposes
$GLOBALS['FE_MOD']['events']['facebook_events_synchronizer'] = 'Oneup\FacebookEvents\Module\ModuleFacebookEvents';

// Register Cron job
$GLOBALS['TL_CRON']['hourly'][] = array('FacebookEventsAutomator', 'synchronizeCalendars');