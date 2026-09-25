<?php

// Só o que difere do Livewire por omissão (o resto vem do pacote).
return [

    // Uploads temporários (os recibos das despesas): até 10 MB, como o recibo, e no máximo 10 por minuto
    // — por omissão eram 12 MB e 60 por minuto, o que deixava encher o disco (notas §52).
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => ['required', 'file', 'max:10240'],
        'directory' => null,
        'middleware' => 'throttle:10,1',
        'preview_mimes' => ['png', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],

    // Barra de progresso ao mudar de página (wire:navigate), no verde da suite.
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#16a34a',
    ],

];
