<?php


$config->parentPath('~NEARD_LIN_PATH~/svnrepos');

$config->addTemplatePath($locwebsvnreal.'/templates/calm/');
$config->addTemplatePath($locwebsvnreal.'/templates/BlueGrey/');
$config->addTemplatePath($locwebsvnreal.'/templates/Elegant/');

$config->addInlineMimeType('text/plain');

$config->setMinDownloadLevel(2);

$config->useGeshi();

$config->setRssCachingEnabled(false);

set_time_limit(0);

$config->expandTabsBy(8);

$config->setTempDir('~NEARD_LIN_PATH~/tmp');

