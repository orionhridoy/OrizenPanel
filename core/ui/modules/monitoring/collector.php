<?php
/*
 * Orizen Monitoring collector - run once a minute by cron (as the web user).
 * Appends one /proc sample to the rolling history. Standalone: no panel session.
 */
if (!defined('DATA_DIR')) define('DATA_DIR', '/opt/orizen/data');
require __DIR__ . '/module.php';   // defines monSample()/monAppend(); registration is skipped in CLI
if (function_exists('monSample') && function_exists('monAppend')) {
    monAppend(monSample());
}
