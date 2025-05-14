#IfNotTable telehealth_vc
CREATE TABLE IF NOT EXISTS `telehealth_vc` (
    `id` INT AUTO_INCREMENT PRIMARY KEY, 
    `encounter_id` INT UNIQUE, 
    `meeting_url` VARCHAR(255), 
    `medic_url` VARCHAR(255), 
    `patient_url` VARCHAR(255),
    `backend_id` VARCHAR(255) NULL,
    `created` DATETIME DEFAULT NOW()
);
#EndIf

#IfNotRow openemr_postcalendar_categories pc_constant_id telehealth_new_patient
INSERT INTO `openemr_postcalendar_categories` (
    `pc_constant_id`, `pc_catname`, `pc_catcolor`, `pc_catdesc`,
    `pc_recurrtype`, `pc_enddate`, `pc_recurrspec`, `pc_recurrfreq`, `pc_duration`,
    `pc_end_date_flag`, `pc_end_date_type`, `pc_end_date_freq`, `pc_end_all_day`,
    `pc_dailylimit`, `pc_cattype`, `pc_active`, `pc_seq`, `aco_spec`
)
VALUES (
    'telehealth_new_patient', 'Telehealth New Patient', '#a2d9e2'
    , 'New Patient Telehealth appointments', '0', NULL
    , 'a:5:{s:17:"event_repeat_freq";s:1:"0";s:22:"event_repeat_freq_type";s:1:"0";s:19:"event_repeat_on_num";s:1:"1";s:19:"event_repeat_on_day";s:1:"0";s:20:"event_repeat_on_freq";s:1:"0";}'
    , '0', '1800', '0', NULL, '0', '0', '0', 0, '1', '10', 'encounters|notes'
);
#EndIf

#IfNotRow openemr_postcalendar_categories pc_constant_id telehealth_established_patient
INSERT INTO `openemr_postcalendar_categories` (
    `pc_constant_id`, `pc_catname`, `pc_catcolor`, `pc_catdesc`,
    `pc_recurrtype`, `pc_enddate`, `pc_recurrspec`, `pc_recurrfreq`, `pc_duration`,
    `pc_end_date_flag`, `pc_end_date_type`, `pc_end_date_freq`, `pc_end_all_day`,
    `pc_dailylimit`, `pc_cattype`, `pc_active`, `pc_seq`, `aco_spec`
)
VALUES (
   'telehealth_established_patient', 'TeleHealth Established Patient', '#93d3a2'
    , 'TeleHealth Established Patient appointment', '0', NULL
    , 'a:5:{s:17:"event_repeat_freq";s:1:"0";s:22:"event_repeat_freq_type";s:1:"0";s:19:"event_repeat_on_num";s:1:"1";s:19:"event_repeat_on_day";s:1:"0";s:20:"event_repeat_on_freq";s:1:"0";}'
    , '0', '900', '0', NULL, '0', '0', '0', 0, '1', '9', 'encounters|notes'
);
#EndIf 