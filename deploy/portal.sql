-- Regista o Nexus Suporte no portal (uma vez): a aplicação com a chave `tempos` e o acesso das
-- pessoas — quem é admin na Nexus IFE fica admin no Suporte, quem é técnico fica técnico.
-- Corre no fim do instalar.sh e pode repetir-se: os acessos só se dão quando a aplicação é registada
-- AGORA (instalação de raiz). Depois disso quem manda é o portal — sem isto, repetir o instalador
-- devolvia o acesso (e o papel de admin) a quem o portal o tinha tirado (notas §52).
-- O URL leva a barra final para não passar pelo 301 do Apache, que perde o :9443.

with nova as (
    insert into aplicacoes (chave, nome, descricao, url, icone, ordem, activa, created_at, updated_at)
    select 'tempos', 'Nexus Suporte', 'Registo de horas dos técnicos',
           'https://infra.nexus-solutions.pt:9443/tempos/', 'grafico', 3, true, now(), now()
    where not exists (select 1 from aplicacoes where chave = 'tempos')
    returning id
)
insert into acessos (utilizador_id, aplicacao_id, papel, created_at, updated_at)
select ac.utilizador_id, nova.id, ac.papel, now(), now()
from nova
join acessos ac on true
join aplicacoes n on n.id = ac.aplicacao_id and n.chave = 'nexus-infra'
where ac.papel in ('admin', 'tecnico');

select u.email, ac.papel
from acessos ac
join aplicacoes a on a.id = ac.aplicacao_id and a.chave = 'tempos'
join utilizadores u on u.id = ac.utilizador_id
order by ac.papel, u.email;
