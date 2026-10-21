<?php
require_once __DIR__ . '/api/security.php';
startAppSession();
if (!hasAdminEntryAuthorization()) denyAdminEntryAccess(false);
$adminInitialSection = 'requests';
require __DIR__ . '/index.php';
