<?php

namespace App\Services\Tempos;

use App\Models\CategoriaDespesaTempo;
use App\Models\DespesaTempo;
use App\Models\ProjetoTempo;
use App\Models\User;
use App\Services\Auditor;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Despesas dos Tempos: cada pessoa lança e altera as suas (enquanto não estiverem aprovadas); quem gere
 * as despesas (`tempos-gerir-despesas`) lança para outros, altera qualquer uma, aprova e rejeita (com
 * motivo) e gere as categorias. Uma despesa rejeitada que o dono altera volta a pendente. Recibos
 * (PDF ou imagem, até 10 MB) ficam no disco privado. Tudo fica na auditoria.
 */
class GestorDespesas
{
    public const TIPOS_RECIBO = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic'];

    public const RECIBO_MAX_KB = 10240;

    /** @param array<string, mixed> $dados */
    public function criar(User $autor, array $dados, ?UploadedFile $recibo = null): DespesaTempo
    {
        $d = new DespesaTempo;
        $d->utilizador_id = $autor->id;
        $this->preencher($autor, $d, $dados + ['data' => '', 'valor' => '', 'categoria_id' => null]);
        $this->guardarRecibo($d, $recibo);
        $d->criado_por = $autor->id;
        $d->alterado_por = $autor->id;
        $d->save();

        Auditor::registar('tempo_despesa_criada', $d, $this->resumo($d));

        return $d;
    }

    /** @param array<string, mixed> $dados */
    public function atualizar(User $autor, DespesaTempo $d, array $dados, ?UploadedFile $recibo = null, bool $retirarRecibo = false): DespesaTempo
    {
        $this->autorizarAlteracao($autor, $d);

        $this->preencher($autor, $d, $dados);
        if ($retirarRecibo && ! $recibo) {
            $d->recibo_caminho = null;
            $d->recibo_nome = null;
        }
        $this->guardarRecibo($d, $recibo);

        // O dono corrigiu uma despesa rejeitada: volta a ser avaliada.
        if ($d->estado === 'rejeitada' && ! $this->gere($autor) && $d->isDirty()) {
            $d->forceFill(['estado' => 'pendente', 'decidido_por' => null, 'decidido_em' => null, 'motivo_rejeicao' => null]);
        }

        $campos = array_keys($d->getDirty());
        $d->alterado_por = $autor->id;
        $d->save();

        if ($campos !== []) {
            Auditor::registar('tempo_despesa_alterada', $d, $this->resumo($d) + ['campos' => $campos]);
        }

        return $d;
    }

    public function apagar(User $autor, DespesaTempo $d): void
    {
        $this->autorizarAlteracao($autor, $d);

        $d->forceFill(['alterado_por' => $autor->id])->save();
        $d->delete();
        Auditor::registar('tempo_despesa_apagada', $d, $this->resumo($d));
    }

    /** Aprovar ou rejeitar (com motivo), ou voltar a pendente. */
    public function decidir(User $autor, DespesaTempo $d, string $estado, ?string $motivo = null): DespesaTempo
    {
        $this->autorizarGestao($autor);

        if (! isset(DespesaTempo::ESTADOS[$estado])) {
            throw ValidationException::withMessages(['estado' => 'Estado inválido.']);
        }
        $motivo = trim((string) $motivo);
        if ($estado === 'rejeitada' && $motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique o motivo da rejeição.']);
        }

        $d->forceFill([
            'estado' => $estado,
            'decidido_por' => $estado === 'pendente' ? null : $autor->id,
            'decidido_em' => $estado === 'pendente' ? null : now(),
            'motivo_rejeicao' => $estado === 'rejeitada' ? mb_substr($motivo, 0, 500) : null,
            'alterado_por' => $autor->id,
        ])->save();

        Auditor::registar('tempo_despesa_'.match ($estado) {
            'aprovada' => 'aprovada',
            'rejeitada' => 'rejeitada',
            default => 'reaberta',
        }, $d, $this->resumo($d) + ($estado === 'rejeitada' ? ['motivo' => $d->motivo_rejeicao] : []));

        return $d;
    }

    public function criarCategoria(User $autor, string $nome): CategoriaDespesaTempo
    {
        $this->autorizarGestao($autor);

        $nome = trim(preg_replace('/\s+/u', ' ', $nome));
        if ($nome === '' || mb_strlen($nome) > 100) {
            throw ValidationException::withMessages(['categoria' => 'Indique o nome da categoria (até 100 caracteres).']);
        }
        if (CategoriaDespesaTempo::whereRaw('lower(nome) = lower(?)', [$nome])->exists()) {
            throw ValidationException::withMessages(['categoria' => 'Já existe a categoria «'.$nome.'».']);
        }

        $c = CategoriaDespesaTempo::create(['nome' => $nome]);
        Auditor::registar('tempo_categoria_despesa_criada', $c, ['nome' => $nome]);

        return $c;
    }

    public function alternarCategoria(User $autor, CategoriaDespesaTempo $c): CategoriaDespesaTempo
    {
        $this->autorizarGestao($autor);

        $c->forceFill(['arquivada_em' => $c->arquivada_em ? null : now()])->save();
        Auditor::registar($c->arquivada_em ? 'tempo_categoria_despesa_arquivada' : 'tempo_categoria_despesa_restaurada', $c, ['nome' => $c->nome]);

        return $c;
    }

    public function gere(User $autor): bool
    {
        return Gate::forUser($autor)->allows('tempos-gerir-despesas');
    }

    public function podeAlterar(User $autor, DespesaTempo $d): bool
    {
        return $this->gere($autor) || ((int) $d->utilizador_id === $autor->id && $d->estado !== 'aprovada');
    }

    /** Vê as despesas de toda a equipa (lista, totais, detalhe, recibos, exportações) — notas §41. */
    public function veTodas(User $autor): bool
    {
        return $this->gere($autor) || Gate::forUser($autor)->allows('tempos-ver-despesas');
    }

    public function podeVer(User $autor, DespesaTempo $d): bool
    {
        return $this->veTodas($autor) || (int) $d->utilizador_id === $autor->id;
    }

    private function autorizarGestao(User $autor): void
    {
        if (! $this->gere($autor)) {
            throw new AuthorizationException('Só quem gere as despesas pode fazer isto.');
        }
    }

    private function autorizarAlteracao(User $autor, DespesaTempo $d): void
    {
        if (! $this->podeAlterar($autor, $d)) {
            throw new AuthorizationException($d->estado === 'aprovada'
                ? 'Esta despesa já foi aprovada: só quem gere as despesas a pode alterar.'
                : 'Só pode alterar as suas despesas.');
        }
    }

    /** @param array<string, mixed> $dados */
    private function preencher(User $autor, DespesaTempo $d, array $dados): void
    {
        $erros = [];

        if (array_key_exists('utilizador_id', $dados) && (int) $dados['utilizador_id'] !== (int) $d->utilizador_id) {
            if (! $this->gere($autor)) {
                throw new AuthorizationException('Só quem gere as despesas as lança para outras pessoas.');
            }
            $id = (int) $dados['utilizador_id'];
            if (! User::comAcessoAosTempos()->whereKey($id)->exists()) {
                $erros['utilizador_id'] = 'Escolha um membro da equipa.';
            }
            $d->utilizador_id = $id;
        }

        if (array_key_exists('data', $dados)) {
            $v = (string) $dados['data'];
            $data = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? CarbonImmutable::createFromFormat('!Y-m-d', $v) : null;
            if (! $data || $data->toDateString() !== $v) {
                $erros['data'] = 'Indique a data.';
            } elseif ($data->gt(CarbonImmutable::now(config('tempos.fuso'))->addDay())) {
                $erros['data'] = 'A data não pode ser no futuro.';
            } else {
                $d->data = $data;
            }
        }

        if (array_key_exists('projeto_id', $dados)) {
            $id = (int) $dados['projeto_id'];
            if ($id) {
                $projeto = ProjetoTempo::find($id);
                // Um privado de que quem lança não é membro conta como inexistente (notas §42).
                $mudou = $id !== (int) $d->getOriginal('projeto_id');
                if (! $projeto || ($mudou && ! ProjetoTempo::visiveisPara($autor)->whereKey($id)->exists())) {
                    $erros['projeto_id'] = 'O projeto não existe.';
                } elseif ($projeto->estaArquivado() && $id !== (int) $d->getOriginal('projeto_id')) {
                    $erros['projeto_id'] = 'O projeto «'.$projeto->nome.'» está arquivado.';
                }
            }
            $d->projeto_id = $id ?: null;
        }

        if (array_key_exists('categoria_id', $dados)) {
            $id = (int) $dados['categoria_id'];
            $categoria = $id ? CategoriaDespesaTempo::find($id) : null;
            if (! $categoria) {
                $erros['categoria_id'] = 'Escolha a categoria.';
            } elseif ($categoria->arquivada_em && $id !== (int) $d->getOriginal('categoria_id')) {
                $erros['categoria_id'] = 'A categoria «'.$categoria->nome.'» está arquivada.';
            }
            $d->categoria_id = $id ?: null;
        }

        if (array_key_exists('valor', $dados)) {
            try {
                $cent = Dinheiro::paraCentimos((string) $dados['valor']);
                if (! $cent) {
                    $erros['valor'] = 'Indique o valor.';
                } else {
                    $d->valor_cent = $cent;
                }
            } catch (InvalidArgumentException $e) {
                $erros['valor'] = $e->getMessage();
            }
        }

        if (array_key_exists('faturavel', $dados)) {
            $d->faturavel = (bool) $dados['faturavel'];
        }

        if (array_key_exists('nota', $dados)) {
            $nota = trim((string) $dados['nota']);
            if (mb_strlen($nota) > 1000) {
                $erros['nota'] = 'A nota não pode passar de 1000 caracteres.';
            }
            $d->nota = $nota ?: null;
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }

    private function guardarRecibo(DespesaTempo $d, ?UploadedFile $recibo): void
    {
        if (! $recibo) {
            return;
        }

        $extensao = mb_strtolower($recibo->getClientOriginalExtension() ?: (string) $recibo->extension());
        if (! in_array($extensao, self::TIPOS_RECIBO, true)) {
            throw ValidationException::withMessages(['recibo' => 'O recibo tem de ser PDF ou imagem (JPG, PNG, WEBP, HEIC).']);
        }
        if ($recibo->getSize() > self::RECIBO_MAX_KB * 1024) {
            throw ValidationException::withMessages(['recibo' => 'O recibo não pode passar de 10 MB.']);
        }

        $d->recibo_caminho = $recibo->store(DespesaTempo::PASTA_RECIBOS, DespesaTempo::DISCO);
        $d->recibo_nome = mb_substr($recibo->getClientOriginalName() ?: 'recibo.'.$extensao, 0, 255);
    }

    /** Conteúdo do recibo, para descarregar. */
    public function caminhoRecibo(DespesaTempo $d): ?string
    {
        return $d->recibo_caminho && Storage::disk(DespesaTempo::DISCO)->exists($d->recibo_caminho) ? $d->recibo_caminho : null;
    }

    /** @return array<string, mixed> */
    private function resumo(DespesaTempo $d): array
    {
        return [
            'utilizador_id' => $d->utilizador_id,
            'data' => $d->data?->toDateString(),
            'valor_cent' => $d->valor_cent,
            'estado' => $d->estado,
        ];
    }
}
