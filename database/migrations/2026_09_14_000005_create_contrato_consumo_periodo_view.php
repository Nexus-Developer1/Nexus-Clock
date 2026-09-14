<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Consumo por contrato e período, para os relatórios (nunca calcular consumo anual on-the-fly em
// listagens). Refrescada de noite pelo job AtualizarConsumoContratos e a pedido após o fecho.
//
// Uma linha por período de cada conjunto de horas incluídas (períodos civis cortados pela
// validade) + uma linha por mês para registos de contratos que não caem em nenhumas horas
// incluídas (contrato_horas_incluidas_id null, horas incluídas 0).
//
// O que NÃO está aqui: o transporte de horas (rollover). É sequencial período a período e fica
// no CalculadorHorasIncluidas; `excedente_sem_transporte_seg` é só consumo − incluídas.
// O dia de um registo é a data de `inicio` em Europe/Lisbon (uma view não recebe parâmetros —
// se o fuso da aplicação mudar, recriar a view).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create materialized view contrato_consumo_periodo as
            with registos as (
                select r.contrato_id,
                       (r.inicio at time zone 'Europe/Lisbon')::date as dia,
                       r.faturavel,
                       r.duracao_seg
                from registos_tempo r
                where r.deleted_at is null
                  and r.contrato_id is not null
                  and r.duracao_seg is not null
            ),
            pacotes as (
                select h.id, h.contrato_id, h.periodo, h.horas_incluidas, h.valido_de,
                       coalesce(h.valido_ate, greatest(current_date, h.valido_de,
                           (select max(x.dia) from registos x where x.contrato_id = h.contrato_id))) as valido_ate
                from contrato_horas_incluidas h
                where h.deleted_at is null
                  and h.contrato_id is not null
            ),
            periodos as (
                select p.id as contrato_horas_incluidas_id, p.contrato_id, p.horas_incluidas,
                       greatest(s.inicio::date, p.valido_de) as periodo_inicio,
                       least((s.inicio + p.passo - interval '1 day')::date, p.valido_ate) as periodo_fim
                from (
                    select pacotes.*,
                           case periodo when 'mensal' then interval '1 month'
                                        when 'trimestral' then interval '3 months'
                                        else interval '1 year' end as passo,
                           case periodo when 'mensal' then 'month'
                                        when 'trimestral' then 'quarter'
                                        else 'year' end as unidade
                    from pacotes
                    where periodo <> 'total'
                ) p
                cross join lateral generate_series(
                    date_trunc(p.unidade, p.valido_de::timestamp), p.valido_ate::timestamp, p.passo
                ) as s(inicio)
                union all
                select id, contrato_id, horas_incluidas, valido_de, valido_ate
                from pacotes
                where periodo = 'total'
            ),
            sem_pacote as (
                select r.*
                from registos r
                where not exists (
                    select 1 from pacotes p
                    where p.contrato_id = r.contrato_id and r.dia between p.valido_de and p.valido_ate
                )
            )
            select pe.contrato_id,
                   pe.contrato_horas_incluidas_id,
                   pe.periodo_inicio,
                   pe.periodo_fim,
                   round(pe.horas_incluidas * 3600)::bigint as incluidas_seg,
                   coalesce(sum(r.duracao_seg) filter (where r.faturavel), 0)::bigint as faturavel_seg,
                   coalesce(sum(r.duracao_seg) filter (where not r.faturavel), 0)::bigint as nao_faturavel_seg,
                   greatest(0, coalesce(sum(r.duracao_seg) filter (where r.faturavel), 0)
                               - round(pe.horas_incluidas * 3600))::bigint as excedente_sem_transporte_seg
            from periodos pe
            left join registos r
                   on r.contrato_id = pe.contrato_id
                  and r.dia between pe.periodo_inicio and pe.periodo_fim
            group by pe.contrato_id, pe.contrato_horas_incluidas_id, pe.periodo_inicio, pe.periodo_fim, pe.horas_incluidas
            union all
            select s.contrato_id,
                   null,
                   date_trunc('month', s.dia::timestamp)::date,
                   (date_trunc('month', s.dia::timestamp) + interval '1 month' - interval '1 day')::date,
                   0,
                   coalesce(sum(s.duracao_seg) filter (where s.faturavel), 0)::bigint,
                   coalesce(sum(s.duracao_seg) filter (where not s.faturavel), 0)::bigint,
                   coalesce(sum(s.duracao_seg) filter (where s.faturavel), 0)::bigint
            from sem_pacote s
            group by s.contrato_id, date_trunc('month', s.dia::timestamp)
            SQL);

        // Índice único obrigatório para REFRESH ... CONCURRENTLY (não bloqueia leituras).
        DB::statement('create unique index contrato_consumo_periodo_chave
            on contrato_consumo_periodo (contrato_id, periodo_inicio, periodo_fim)');
    }

    public function down(): void
    {
        DB::statement('drop materialized view if exists contrato_consumo_periodo');
    }
};
