-- Regista o Nexus Suporte no portal (uma vez): a aplicação com a chave `tempos` e o acesso das
-- pessoas — quem é admin na Nexus IFE fica admin no Suporte, quem é técnico fica técnico.
-- Corre no fim do instalar.sh (pode repetir-se; não duplica). O URL leva a barra final para não
-- passar pelo 301 do Apache, que perde o :9443.

insert into aplicacoes (chave, nome, descricao, url, icone, ordem, activa, created_at, updated_at)
select 'tempos', 'Nexus Suporte', 'Registo de horas dos técnicos',
       'https://infra.nexus-solutions.pt:9443/tempos/', 'grafico', 3, true, now(), now()
where not exists (select 1 from aplicacoes where chave = 'tempos');

insert into acessos (utilizador_id, aplicacao_id, papel, created_at, updated_at)
select ac.utilizador_id, t.id, ac.papel, now(), now()
from acessos ac
join aplicacoes n on n.id = ac.aplicacao_id and n.chave = 'nexus-infra'
join aplicacoes t on t.chave = 'tempos'
where ac.papel in ('admin', 'tecnico')
  and not exists (select 1 from acessos x where x.utilizador_id = ac.utilizador_id and x.aplicacao_id = t.id);

select u.email, ac.papel
from acessos ac
join aplicacoes a on a.id = ac.aplicacao_id and a.chave = 'tempos'
join utilizadores u on u.id = ac.utilizador_id
order by ac.papel, u.email;
