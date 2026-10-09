<?php

return [

    // Fuso em que as datas são APRESENTADAS e em que se decide a que dia pertence um registo.
    // Na base de dados fica tudo em UTC (timestamptz); o dia de um registo da timesheet é a
    // meia-noite deste fuso.
    'fuso' => env('TEMPOS_FUSO', 'Europe/Lisbon'),

    // Quem pode reabrir um mês fechado ou mexer num registo fechado (permissão explícita, além de
    // ser administrador nesta aplicação). Lista de emails separados por vírgula.
    'pode_reabrir' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('TEMPOS_PODE_REABRIR', 'suporte@nxs.pt')),
    ))),

    // Quem aprova as despesas do Suporte — aprovar, rejeitar e voltar a pendente — e recebe por email
    // cada despesa por aprovar. A pedido, só o Paulo Gouveia (notas §44); ser admin não chega.
    // Lista de emails separados por vírgula.
    'aprovam_despesas' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('TEMPOS_APROVAM_DESPESAS', 'pgouveia@nxs.pt')),
    ))),

    // Horas por semana abaixo das quais o resumo semanal de quem gere avisa (0 = não avisa). Só aviso:
    // férias e ausências não estão nos tempos.
    'horas_semana_minimas' => (float) env('TEMPOS_HORAS_SEMANA_MINIMAS', 35),

    // Capacidade diária de quem não a tem definida na página Equipa (relatório Presenças), em horas.
    'capacidade_diaria_horas' => (float) env('TEMPOS_CAPACIDADE_DIARIA_HORAS', 8),

    // Dia em que a equipa começou a usar o Suporte (AAAA-MM-DD). Antes dele não há horas a contar: as
    // Presenças não mostram esses dias e os lembretes não avisam por eles (notas §66). Vazio = sem início.
    'inicio' => env('TEMPOS_INICIO') ?: null,

    // Feriados (notas §72): aparecem sempre no Calendário e não contam como horas em falta; com isto
    // ligado, também não se registam horas num dia de feriado. Desligar volta a deixar registar.
    'bloquear_feriados' => (bool) env('TEMPOS_BLOQUEAR_FERIADOS', true),

    // Um cronómetro a correr há mais destas horas manda um email à própria pessoa, uma vez (notas §61).
    'aviso_cronometro_horas' => (int) env('TEMPOS_AVISO_CRONOMETRO_HORAS', 10),

    // Cronómetro esquecido para sozinho a esta hora (Lisboa), no dia em que começou; os começados a
    // esta hora ou depois param às 23:59 (notas §78). Vazio desliga.
    'parar_cronometro_as' => env('TEMPOS_PARAR_CRONOMETRO_AS', '19:00'),

    // Teto do ZIP dos recibos (página Despesas), em MB: acima disto pede-se um período mais curto.
    'zip_recibos_max_mb' => (int) env('TEMPOS_ZIP_RECIBOS_MAX_MB', 500),

    // As tabelas da Nexus Infra (clientes, contratos, intervenções…) são só de leitura aqui. Só os
    // testes e o seeder da base de desenvolvimento ligam isto, para criarem dados de exemplo.
    // NUNCA ligar em produção.
    'escrever_tabelas_da_nexus_infra' => false,

];
