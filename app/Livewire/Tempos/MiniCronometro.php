<?php

namespace App\Livewire\Tempos;

use App\Models\ProjetoTempo;
use App\Services\Tempos\Cronometro as ServicoCronometro;
use App\Services\Tempos\PainelTempos;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Cronómetro pequeno para começar e parar sem ir à página do Cronómetro (notas §69): cartão no canto
 * inferior direito de todas as páginas, e o mesmo numa janela que fica por cima dos outros programas
 * (Document Picture-in-Picture, no Chrome e no Edge). Só as horas de quem está a ver; a escrita passa
 * pelo serviço do cronómetro, como na página.
 */
class MiniCronometro extends Component
{
    public string $descricao = '';

    public string $projeto = '';

    public ?string $erro = null;

    public ?string $aviso = null;

    // Dentro da janela por cima de tudo: ocupa a janela inteira, sem minimizar.
    #[Locked]
    public bool $janela = false;

    public function comecar(): void
    {
        $this->executar(function () {
            app(ServicoCronometro::class)->iniciar(auth()->user(), [
                'projeto_id' => ctype_digit($this->projeto) ? (int) $this->projeto : null,
                'descricao' => trim($this->descricao) ?: null,
            ]);
            $this->reset(['descricao', 'projeto']);
        });
    }

    public function parar(): void
    {
        $this->executar(function () {
            $registo = app(ServicoCronometro::class)->parar(auth()->user());
            $this->aviso = $registo
                ? 'Gravado: '.PainelTempos::hms((int) $registo->duracao_seg).'.'
                : 'Descartado: durou menos de um minuto.';
        });
    }

    public function render()
    {
        $aCorrer = app(ServicoCronometro::class)->aCorrer(auth()->user());
        $aCorrer?->load('projeto:id,nome,cor');

        return view('livewire.tempos.mini-cronometro', [
            'aCorrer' => $aCorrer,
            'projetos' => $aCorrer ? collect() : ProjetoTempo::visiveisPara(auth()->user())->ativos()->orderByRaw('lower(nome)')->pluck('nome', 'id'),
        ]);
    }

    private function executar(callable $acao): void
    {
        $this->erro = null;
        $this->aviso = null;

        try {
            $acao();
            // As outras janelas (a página do Cronómetro, o cartão noutro separador) atualizam-se.
            $this->dispatch('cronometro-mudou');
        } catch (ValidationException $e) {
            $this->erro = collect($e->errors())->flatten()->first();
        } catch (AuthorizationException $e) {
            $this->erro = in_array($e->getMessage(), ['', 'This action is unauthorized.'], true)
                ? 'Não foi possível: o registo está numa semana entregue ou num mês fechado.'
                : $e->getMessage();
        }
    }
}
