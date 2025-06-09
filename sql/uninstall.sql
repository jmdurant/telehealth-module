# Remove form registration from registry
DELETE FROM `registry` WHERE `directory` = 'telehealth_notes';

# Drop form table
DROP TABLE IF EXISTS `form_telehealth_notes`;

# Drop telehealth tables (optional - comment out if you want to preserve data)
DROP TABLE IF EXISTS `telehealth_vc_log`;
DROP TABLE IF EXISTS `telehealth_vc_topic`;
DROP TABLE IF EXISTS `telehealth_vc`; 