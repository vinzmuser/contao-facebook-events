<?php

// Add facebook id to the event fields
$GLOBALS['TL_DCA']['tl_calendar_events']['fields']['facebookEvents_id'] = [
    'sql' => "varchar(255) NOT NULL default ''",
];
