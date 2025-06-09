<?php
/**
 * Telehealth Notes Form Report
 * This file displays telehealth notes in encounter summaries
 */

require_once("../../globals.php");
require_once("$srcdir/api.inc.php");

function telehealth_notes_report($pid, $encounter, $cols, $id)
{
    $count = 0;
    $sql = "SELECT * FROM form_telehealth_notes WHERE id = ?";
    $res = sqlStatement($sql, array($id));
    
    while ($result = sqlFetchArray($res)) {
        if ($count == 0) {
?>
<style>
.telehealth-content table {
    border-collapse: collapse;
    border: 1px solid #ddd;
    margin: 10px 0;
}
.telehealth-content table th,
.telehealth-content table td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: left;
}
.telehealth-content table th {
    background-color: #f5f5f5;
    font-weight: bold;
}
</style>
<table>
<tr>
<td><span class="bold">Telehealth Visit Notes:</span></td>
<td><span class="text"><div class="telehealth-content"><?php echo $result['evolution_text']; ?></div></span></td>
</tr>
<tr>
<td><span class="bold">Visit Type:</span></td>
<td><span class="text"><?php echo text($result['visit_type']); ?></span></td>
</tr>
<tr>
<td><span class="bold">Date:</span></td>
<td><span class="text"><?php echo text($result['date']); ?></span></td>
</tr>
<tr>
<td><span class="bold">Backend ID:</span></td>
<td><span class="text"><?php echo text($result['backend_id']); ?></span></td>
</tr>
</table>
<?php
        }
        $count++;
    }
    
    return '';
} 