<?php
require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

$form_name = "telehealth_notes";

if ($_GET["mode"] == "new") {
    // Insert new record - formSubmit automatically handles ALL standard fields and proper escaping
    $newid = formSubmit("form_" . $form_name, $_POST, $_GET["id"]);
    addForm($encounter, "Telehealth Visit Notes", $newid, "telehealth_notes", $pid, $userauthorized);
} elseif ($_GET["mode"] == "update") {
    // Update existing record - formUpdate handles proper escaping  
    formUpdate("form_" . $form_name, $_POST, $_GET["id"]);
}

formJump(); 