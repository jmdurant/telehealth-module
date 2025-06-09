<?php
/**
 * Telehealth Notes Encounter Form View
 * Displays telehealth visit notes in OpenEMR encounter view
 */

require_once("../../interface/globals.php");

function telehealth_notes_report($pid, $encounter, $cols, $id) {
    $count = 0;
    $sql = "SELECT * FROM form_telehealth_notes WHERE id = ? AND pid = ? AND encounter = ?";
    $res = sqlStatement($sql, array($id, $pid, $encounter));
    
    if ($data = sqlFetchArray($res)) {
        // Sanitize HTML content while preserving formatting
        $evolution_html = $data['evolution_text'];
        
        // Basic HTML sanitization - allow common formatting tags
        $allowed_tags = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><h5><h6><table><tr><td><th><thead><tbody><blockquote><a>';
        $evolution_html = strip_tags($evolution_html, $allowed_tags);
        
        // Remove any script tags and javascript for security
        $evolution_html = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $evolution_html);
        $evolution_html = preg_replace('/javascript:/i', '', $evolution_html);
        $evolution_html = preg_replace('/on\w+\s*=/i', '', $evolution_html);
        
        ?>
        <table border="0" cellpadding="0" cellspacing="0" class="table table-striped">
            <tr>
                <td class="forms_header">
                    <b><?php echo xlt('Telehealth Visit Notes'); ?></b>
                </td>
            </tr>
            <tr>
                <td>
                    <table border="0" cellpadding="2" cellspacing="0" width="100%">
                        <tr>
                            <td nowrap style="width: 120px;"><b><?php echo xlt('Visit Date'); ?>:</b></td>
                            <td><?php echo text(oeFormatDateTime($data['date'])); ?></td>
                        </tr>
                        <tr>
                            <td nowrap><b><?php echo xlt('Visit Type'); ?>:</b></td>
                            <td><?php echo text($data['visit_type']); ?></td>
                        </tr>
                        <?php if (!empty($data['backend_id'])) { ?>
                        <tr>
                            <td nowrap><b><?php echo xlt('Backend ID'); ?>:</b></td>
                            <td><?php echo text($data['backend_id']); ?></td>
                        </tr>
                        <?php } ?>
                        <tr>
                            <td nowrap valign="top"><b><?php echo xlt('Clinical Notes'); ?>:</b></td>
                            <td style="padding: 10px; background-color: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                                <div class="telehealth-notes-content">
                                    <?php 
                                    if (empty($evolution_html) || $evolution_html === '<p>&nbsp;</p>' || trim(strip_tags($evolution_html)) === '') {
                                        echo '<em>' . xlt('No clinical notes recorded') . '</em>';
                                    } else {
                                        // Display the sanitized HTML content
                                        echo $evolution_html;
                                    }
                                    ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
        
        <style>
        .telehealth-notes-content {
            line-height: 1.6;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .telehealth-notes-content h1,
        .telehealth-notes-content h2,
        .telehealth-notes-content h3,
        .telehealth-notes-content h4,
        .telehealth-notes-content h5,
        .telehealth-notes-content h6 {
            margin-top: 15px;
            margin-bottom: 10px;
            color: #333;
        }
        .telehealth-notes-content ul,
        .telehealth-notes-content ol {
            margin: 10px 0;
            padding-left: 20px;
        }
        .telehealth-notes-content table {
            border-collapse: collapse;
            width: 100%;
            margin: 10px 0;
        }
        .telehealth-notes-content table td,
        .telehealth-notes-content table th {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        .telehealth-notes-content table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .telehealth-notes-content blockquote {
            margin: 10px 0;
            padding: 10px;
            background-color: #f0f0f0;
            border-left: 4px solid #ccc;
            font-style: italic;
        }
        .telehealth-notes-content p {
            margin: 8px 0;
        }
        </style>
        
        <?php
        $count++;
    }
    
    return $count;
}
?> 