<?php
require_once __DIR__ . '/api/security.php';
if (!isAllowedAdminIp()) denyAdminIpAccess(false);
$adminInitialSection = 'disclosures';
require __DIR__ . '/index.php';
