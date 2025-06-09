<?php
require_once("../../globals.php");

echo "<h2>Debug Information</h2>";
echo "<p><strong>GLOBALS['webroot']:</strong> " . ($GLOBALS['webroot'] ?? 'NOT SET') . "</p>";
echo "<p><strong>\$rootdir:</strong> " . ($rootdir ?? 'NOT SET') . "</p>";
echo "<p><strong>\$srcdir:</strong> " . ($srcdir ?? 'NOT SET') . "</p>";
echo "<p><strong>__DIR__:</strong> " . __DIR__ . "</p>";
echo "<p><strong>Current working directory:</strong> " . getcwd() . "</p>";

echo "<h3>Form Action URLs that would be generated:</h3>";
echo "<p><strong>Using \$rootdir . '/forms/':</strong> " . ($rootdir ?? '') . "/forms/telehealth_notes/save.php</p>";
echo "<p><strong>Using \$rootdir . '/interface/forms/':</strong> " . ($rootdir ?? '') . "/interface/forms/telehealth_notes/save.php</p>";
echo "<p><strong>Using relative path:</strong> ./save.php</p>";
echo "<p><strong>Using absolute from webroot:</strong> " . ($GLOBALS['webroot'] ?? '') . "/interface/forms/telehealth_notes/save.php</p>";

echo "<h3>File existence check:</h3>";
$save_path = __DIR__ . "/save.php";
echo "<p><strong>save.php exists at " . $save_path . ":</strong> " . (file_exists($save_path) ? 'YES' : 'NO') . "</p>";

echo "<h3>Sample form action - let's see what gets rendered:</h3>";
$_GET["id"] = "123";
$_GET["user"] = "testuser";
$_GET["encounter"] = "456";
$_GET["pid"] = "789";

$form_action1 = $rootdir . "/forms/telehealth_notes/save.php?mode=update&id=" . attr_url($_GET["id"]) . "&user=" . attr_url($_GET["user"]) . "&encounter=" . attr_url($_GET["encounter"]) . "&pid=" . attr_url($_GET["pid"]);
echo "<p><strong>Generated action URL 1:</strong> " . htmlspecialchars($form_action1) . "</p>";

$form_action2 = "./save.php?mode=update&id=" . attr_url($_GET["id"]) . "&user=" . attr_url($_GET["user"]) . "&encounter=" . attr_url($_GET["encounter"]) . "&pid=" . attr_url($_GET["pid"]);
echo "<p><strong>Generated action URL 2 (relative):</strong> " . htmlspecialchars($form_action2) . "</p>";

$form_action3 = $GLOBALS['webroot'] . "/interface/forms/telehealth_notes/save.php?mode=update&id=" . attr_url($_GET["id"]) . "&user=" . attr_url($_GET["user"]) . "&encounter=" . attr_url($_GET["encounter"]) . "&pid=" . attr_url($_GET["pid"]);
echo "<p><strong>Generated action URL 3 (webroot):</strong> " . htmlspecialchars($form_action3) . "</p>";

echo "<h3>What do existing forms use?</h3>";
$note_path = "/var/www/localhost/htdocs/openemr/interface/forms/note/view.php";
if (file_exists($note_path)) {
    $note_content = file_get_contents($note_path);
    preg_match('/action="([^"]*)"/', $note_content, $matches);
    if ($matches) {
        echo "<p><strong>Note form uses:</strong> " . htmlspecialchars($matches[1]) . "</p>";
    }
}
?> 