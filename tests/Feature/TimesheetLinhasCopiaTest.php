<?php

namespace Tests\Feature;

use App\Enums\EstadoSemanaTempo;
use App\Models\Cliente;
use App\Models\RegistoTempo;
use App\Models\SemanaTempo;
use App\Models\User;
use App\Services\Tempos\FolhaSemanal;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Fase 2 — linhas persistentes (as linhas com horas na semana anterior aparecem vazias na semana
// seguinte, e podem ser retiradas) e "Copiar semana anterior" (linhas e horas).
class TimesheetLinhasCopiaTest extends TestCase
{
    use RefreshDatabase;

    private FolhaSemanal $folha;

    private User $tecnico;

    private Cliente $cliente;

    private CarbonImmutable $anterior;

    private CarbonImmutable $atual;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-16 10:00:00');
        $this->folha = app(FolhaSemanal::class);
        $this->tecnico = $this->tecnico();
        $this->cliente = $this->cliente('Hospital Exemplo');
        $this->anterior = CarbonImmutable::parse('2026-09-07');
        $this->atual = CarbonImmutable::parse('2026-09-14');
    }

    // --- Linhas persistentes ---

    public function test_linha_com_horas_na_semana_anterior_aparece_vazia_na_seguinte(): void
    {
        $contrato = $this->contrato($this->cliente);
        $this->registo($this->tecnico, $this->cliente, '2026-09-08', 3600, ['contrato_id' => $contrato->id, 'descricao' => 'Manutenção', 'faturavel' => false]);

        $linhas = $this->folha->linhas($this->tecnico, $this->atual);

        $this->assertCount(1, $linhas);
        $this->assertSame('persistente', $linhas[0]['origem']);
        $this->assertSame($contrato->id, $linhas[0]['contrato_id']);
        $this->assertSame(0, $linhas[0]['total']);
        $this->assertSame('Manutenção', $linhas[0]['descricao']); // traz a descrição e o faturável
        $this->assertFalse($linhas[0]['faturavel']);
    }

    public function test_linha_sem_horas_na_semana_anterior_nao_persiste(): void
    {
        $this->folha->adicionarLinha($this->tecnico, $this->tecnico, $this->anterior, $this->cliente->id, null, null);
        $this->registo($this->tecnico, $this->cliente('Outro'), '2026-08-31', 3600); // há duas semanas

        $this->assertSame([], $this->folha->linhas($this->tecnico, $this->atual));
    }

    public function test_linha_persistente_so_do_proprio_tecnico(): void
    {
        $this->registo($this->tecnico(), $this->cliente, '2026-09-08', 3600);

        $this->assertSame([], $this->folha->linhas($this->tecnico, $this->atual));
    }

    public function test_linha_persistente_retirada_nao_volta(): void
    {
        $this->registo($this->tecnico, $this->cliente, '2026-09-08', 3600);
        $chave = FolhaSemanal::chave($this->cliente->id, null, null);

        $this->folha->removerLinha($this->tecnico, $this->tecnico, $this->atual, $chave);

        $this->assertSame([], $this->folha->linhas($this->tecnico, $this->atual));
        // …mas continua na semana onde tem horas.
        $this->assertCount(1, $this->folha->linhas($this->tecnico, $this->anterior));
    }

    public function test_horas_registadas_aparecem_mesmo_numa_linha_retirada(): void
    {
        $chave = FolhaSemanal::chave($this->cliente->id, null, null);
        $this->folha->adicionarLinha($this->tecnico, $this->tecnico, $this->atual, $this->cliente->id, null, null);
        $this->folha->removerLinha($this->tecnico, $this->tecnico, $this->atual, $chave);

        $this->registo($this->tecnico, $this->cliente, '2026-09-15', 1800); // ex.: vindas do cronómetro

        $this->assertSame(1800, $this->folha->linhas($this->tecnico, $this->atual)[0]['total']);
    }

    // --- Copiar semana anterior ---

    public function test_copiar_semana_anterior_traz_linhas_e_horas_dia_a_dia(): void
    {
        $contrato = $this->contrato($this->cliente);
        $outro = $this->cliente('Banco Exemplo');
        $this->registo($this->tecnico, $this->cliente, '2026-09-07', 2 * 3600, ['contrato_id' => $contrato->id, 'descricao' => 'Preventiva']);
        $this->registo($this->tecnico, $this->cliente, '2026-09-11', 3 * 3600, ['contrato_id' => $contrato->id, 'descricao' => 'Preventiva']);
        $this->registo($this->tecnico, $outro, '2026-09-09', 1800, ['faturavel' => false]);
        $this->folha->adicionarLinha($this->tecnico, $this->tecnico, $this->anterior, $this->cliente('Sem horas')->id, null, null);

        $contagem = $this->folha->copiarSemanaAnterior($this->tecnico, $this->tecnico, $this->atual);

        $this->assertSame(['linhas' => 3, 'registos' => 3], $contagem);
        $linhas = collect($this->folha->linhas($this->tecnico, $this->atual))->keyBy('chave');
        $comContrato = $linhas[FolhaSemanal::chave($this->cliente->id, $contrato->id, null)];
        $this->assertSame([7200, null, null, null, 10800, null, null], array_column($comContrato['dias'], 'segundos'));
        $this->assertSame('Preventiva', $comContrato['descricao']);
        $this->assertFalse($linhas[FolhaSemanal::chave($outro->id, null, null)]['faturavel']);
        $this->assertSame(0, $linhas->firstWhere('cliente', 'Sem horas')['total']); // linha vazia também vem

        foreach (RegistoTempo::where('inicio', '>=', RegistoTempo::inicioDoDia('2026-09-14'))->get() as $copia) {
            $this->assertSame($this->tecnico->id, $copia->criado_por);
            $this->assertSame('timesheet', $copia->origem->value);
        }
    }

    public function test_copiar_nao_escreve_por_cima_de_horas_que_ja_existem(): void
    {
        $this->registo($this->tecnico, $this->cliente, '2026-09-07', 2 * 3600);
        $this->registo($this->tecnico, $this->cliente, '2026-09-08', 2 * 3600);
        $this->registo($this->tecnico, $this->cliente, '2026-09-14', 5 * 3600); // segunda desta semana já preenchida

        $this->assertSame(['linhas' => 0, 'registos' => 1], $this->folha->copiarSemanaAnterior($this->tecnico, $this->tecnico, $this->atual));

        $dias = array_column($this->folha->linhas($this->tecnico, $this->atual)[0]['dias'], 'segundos');
        $this->assertSame([5 * 3600, 2 * 3600], array_slice($dias, 0, 2));
    }

    public function test_copiar_duas_vezes_nao_duplica(): void
    {
        $this->registo($this->tecnico, $this->cliente, '2026-09-07', 3600);

        $this->folha->copiarSemanaAnterior($this->tecnico, $this->tecnico, $this->atual);
        $this->folha->copiarSemanaAnterior($this->tecnico, $this->tecnico, $this->atual);

        $this->assertSame(2, RegistoTempo::count());
    }

    public function test_copiar_semana_anterior_vazia_explica(): void
    {
        $this->expectException(ValidationException::class);
        $this->folha->copiarSemanaAnterior($this->tecnico, $this->tecnico, $this->atual);
    }

    public function test_copiar_para_semana_submetida_nao_deixa(): void
    {
        $this->registo($this->tecnico, $this->cliente, '2026-09-07', 3600);
        SemanaTempo::create(['tecnico_id' => $this->tecnico->id, 'semana_inicio' => '2026-09-14', 'estado' => EstadoSemanaTempo::Submetida, 'submetida_em' => now()]);

        $this->expectException(AuthorizationException::class);
        $this->folha->copiarSemanaAnterior($this->tecnico, $this->tecnico, $this->atual);
    }
}
