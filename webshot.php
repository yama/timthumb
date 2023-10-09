<?php
// ini_set('display_errors', 1);
include './timthumb.php';

class webshot extends timthumb
{
    public $version = '0.1.0';
}

$webshot = new webshot();
$webshot->start();

// Under development
