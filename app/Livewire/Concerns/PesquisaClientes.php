<?php

namespace App\Livewire\Concerns;

use App\Models\Cliente;
use Illuminate\Support\Collection;

// Pesquisa de clientes para o combobox (partial livewire.partials.combobox-cliente), como no editor
// de contratos da Nexus Infra: nome sem acentos, NIF ou nº de cliente no ERP, até 30 resultados.
// O componente define `clienteBusca` e o método `selecionarCliente(int $id)`.
trait PesquisaClientes
{
    private const NOME_SEM_ACENTOS = "translate(lower(nome), 'áàâãäçéèêëíìîïóòôõöúùûü', 'aaaaaceeeeiiiiooooouuuu')";

    /** @return Collection<int, Cliente> */
    protected function pesquisarClientes(string $termo): Collection
    {
        $termo = trim($termo);

        return Cliente::query()
            ->where('ativo', true)
            ->when($termo !== '', function ($q) use ($termo) {
                $q->where(fn ($q) => $q->whereRaw(self::NOME_SEM_ACENTOS.' like ?', ['%'.$this->normalizarBusca($termo).'%'])
                    ->orWhere('nif', 'ilike', '%'.$termo.'%')
                    ->orWhere('id_erp', 'ilike', '%'.$termo.'%'));
            })
            ->orderBy('nome')
            ->limit(30)
            ->get(['id', 'nome', 'nif', 'id_erp']);
    }

    // Minúsculas e sem acentos, para casar com a expressão translate() aplicada ao nome.
    private function normalizarBusca(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $de = ['á', 'à', 'â', 'ã', 'ä', 'ç', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü'];
        $para = ['a', 'a', 'a', 'a', 'a', 'c', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u'];

        return str_replace($de, $para, $valor);
    }
}
