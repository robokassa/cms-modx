<?php

if (!defined('MSP_ROBOKASSA_BUILD_CONTEXT')) {
    header('HTTP/1.1 403 Forbidden');
    exit('Forbidden');
}

function getSnippetContent($filename)
{
    $o = file_get_contents($filename);
    return trim(str_replace(array('<?php', '?>'), '', $o));
}
