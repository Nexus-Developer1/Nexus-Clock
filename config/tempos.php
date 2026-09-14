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

    // Duração máxima de um registo (um dia inteiro). Igual à constraint da tabela.
    'duracao_maxima_seg' => 86400,

    // As tabelas da Nexus Infra (clientes, contratos, intervenções…) são só de leitura aqui. Só os
    // testes e o seeder da base de desenvolvimento ligam isto, para criarem dados de exemplo.
    // NUNCA ligar em produção.
    'escrever_tabelas_da_nexus_infra' => false,

];
