<?php
echo "TEST FILE IS ACCESSIBLE!";
echo "<br>Current directory: " . __DIR__;
echo "<br>Files in directory: ";
print_r(scandir(__DIR__));
echo "<br>save.php exists: " . (file_exists(__DIR__ . '/save.php') ? 'YES' : 'NO');
?> 