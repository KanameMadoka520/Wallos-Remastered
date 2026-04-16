<?php

$db->exec("
    UPDATE payment_methods
    SET icon = REPLACE(icon, 'images/uploads/icons/images/uploads/icons/', 'images/uploads/icons/')
    WHERE icon LIKE 'images/uploads/icons/images/uploads/icons/%'
");
