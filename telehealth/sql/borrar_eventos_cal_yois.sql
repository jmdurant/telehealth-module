-- DELETE FROM openemr_postcalendar_events 
-- -- WHERE
-- --     pc_pid = 10
--     ;
-- SELECT 
--     *
-- FROM
--     openemr_postcalendar_events
-- WHERE
--     pc_pid = 10
-- ORDER BY pc_eid DESC
-- LIMIT 10
-- ;
-- BORRAR TODOS LOS EVENTOS DE CALENDARIO
truncate TABLE `openemr_postcalendar_events`;
truncate TABLE `telehealth_vc`;
