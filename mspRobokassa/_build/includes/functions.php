<?php

function getSnippetContent($filename)
{
    $o = file_get_contents($filename);
    return trim(str_replace(array('<?php', '?>'), '', $o));
}
