<?php
/**
 * Telehealth Notes Form - New Entry
 * This file handles creating new telehealth notes
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

formHeader("Form: Telehealth Notes");
$returnurl = 'encounter_top.php';

/* name of this form */
$form_name = "telehealth_notes";
?>

<html><head>
<?php Header::setupHeader(['common', 'ckeditor', 'ckeditor-nation-notes']); ?>
<style>
.telehealth-notes-container {
    max-width: 900px;
    margin: 0 auto;
}
.ck-editor {
    max-width: 100%;
}
.ck-editor__editable {
    min-height: 250px;
    max-height: 500px;
}
</style>
</head>
<body class="body_top">

<div class="telehealth-notes-container">
<form method=post action="<?php echo $rootdir . "/forms/" . $form_name . "/save.php?mode=new";?>" name="my_form" id="my_form">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

<span class="title"><?php echo xlt('Telehealth Notes - New'); ?></span><br /><br />

<div style="margin: 10px;">
<input type="button" class="save" value="    <?php echo xla('Save'); ?>    "> &nbsp;
<input type="button" class="dontsave" value="<?php echo xla('Cancel'); ?>"> &nbsp;
</div>

<table>
<tr><td>
<span class=text><?php echo xlt('Visit Type:'); ?> </span>
<input type="text" name="visit_type" value="Telehealth Consultation" readonly style="background-color: #f5f5f5;" />
</td></tr>
</table>

<br>
<b><?php echo xlt('Clinical Notes:'); ?></b>
<br>
<textarea name="evolution_text" id="evolution_text" cols="67" rows="8" placeholder="Enter clinical notes for the telehealth visit..."></textarea>

</form>
</div>

<script>
$(function () {
    // Initialize CKEditor using OpenEMR's system
    const { ClassicEditor } = CKEDITOR;
    const config = Object.assign({}, window.oeCKEditorConfigs.defaultConfig, {
        initialData: ''
    });
    
    ClassicEditor
        .create(document.querySelector('#evolution_text'), config)
        .then(editor => {
            window.telehealthEditor = editor;
            
            // Form submission handling
            $(".save").click(function() { 
                // Sync CKEditor content to textarea
                document.querySelector('#evolution_text').value = editor.getData();
                top.restoreSession(); 
                $("#my_form").submit(); 
            });
        })
        .catch(error => {
            console.error('CKEditor initialization failed:', error);
            // Fallback form handling if CKEditor fails
            $(".save").click(function() { 
                top.restoreSession(); 
                $("#my_form").submit(); 
            });
        });
    
    $(".dontsave").click(function() { parent.closeTab(window.name, false); });
});
</script>

</body>
</html> 