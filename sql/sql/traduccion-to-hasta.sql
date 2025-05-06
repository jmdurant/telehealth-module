-- agregar to to para traducirlo a hasta
insert into lang_constants (cons_id,constant_name) values( (select (MAX(cons_id)+1) from lang_constants as lc),' to ');
-- agregar definicion to to - hasta 
insert into lang_definitions (cons_id, lang_id, definition)
values (
(
select cons_id
from lang_constants
where constant_name like ' to ' limit 1
)
,
4, 
' hasta '
)
;
-- consulta 
select * from lang_definitions where definition =' hasta ';
-- fix 
select cons_id
from lang_constants
where constant_name like ' to ' limit 1;