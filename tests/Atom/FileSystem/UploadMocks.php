<?php

declare(strict_types=1);

namespace Atom\FileSytem;

$GLOBALS['mock_upload'] = true;

function is_uploaded_file($filename) {
    if (isset($GLOBALS['mock_upload']) && $GLOBALS['mock_upload']) {
        return file_exists($filename);
    }
    return \is_uploaded_file($filename);
}

function move_uploaded_file($filename, $destination) {
    if (isset($GLOBALS['mock_upload']) && $GLOBALS['mock_upload']) {
        return copy($filename, $destination);
    }
    return \move_uploaded_file($filename, $destination);
}
