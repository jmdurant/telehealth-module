<?php
require_once(__DIR__ . "/../../globals.php");

$_GET["id"] = "test_id";
$_GET["user"] = "test_user";
$_GET["encounter"] = "test_encounter";
$_GET["pid"] = "test_pid";

$form_action = "save.php?mode=update&id=" . attr_url($_GET["id"]) . "&user=" . attr_url($_GET["user"]) . "&encounter=" . attr_url($_GET["encounter"]) . "&pid=" . attr_url($_GET["pid"]);

echo "<h2>Form Action Debug</h2>";
echo "<p><strong>Generated form action:</strong> " . htmlspecialchars($form_action) . "</p>";
echo "<p><strong>Full URL would be:</strong> " . htmlspecialchars($_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) . "</p>";
echo "<p><strong>Directory:</strong> " . htmlspecialchars(dirname($_SERVER['REQUEST_URI'])) . "</p>";
echo "<p><strong>Final action URL:</strong> " . htmlspecialchars(dirname($_SERVER['REQUEST_URI']) . '/' . $form_action) . "</p>";

echo "<h3>Test Form:</h3>";
?>
<form method="post" action="<?php echo htmlspecialchars($form_action); ?>">
    <input type="hidden" name="csrf_token_form" value="test_token" />
    <input type="text" name="visit_type" value="Test" />
    <input type="text" name="evolution_text" value="Test content" />
    <input type="submit" value="Test Submit" />
</form>

<?php
echo "<h3>File Check:</h3>";
echo "<p><strong>save.php exists:</strong> " . (file_exists(__DIR__ . '/save.php') ? 'YES' : 'NO') . "</p>";
echo "<p><strong>save.php path:</strong> " . __DIR__ . '/save.php</p>';
?> 