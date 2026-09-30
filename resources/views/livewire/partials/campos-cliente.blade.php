{{-- Campos de um cliente do Suporte, partilhados pela página «Novo cliente» e pelas janelas de
     alterar. Espera: $formulario (nome, email, nif, emails_cc, morada, nota, moeda) e $moedas.
     O primeiro campo tem x-ref="nome", para quem inclui isto lhe dar o foco. Os campos obrigatórios
     vêm de Equipa › Regras (notas §59). --}}
@php $obrigatorios = app(\App\Services\Tempos\CamposObrigatorios::class)->ativos('clientes'); @endphp
<div>
    <label class="campo-label" for="cliente-nome">Nome <span class="text-perigo-500">*</span></label>
    <input id="cliente-nome" x-ref="nome" type="text" wire:model="formulario.nome" class="campo-input" placeholder="Nome do cliente">
    @error('formulario.nome') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="campo-label" for="cliente-email">Email @if (in_array('email', $obrigatorios, true))<span class="text-perigo-500">*</span>@endif</label>
    <input id="cliente-email" type="email" wire:model="formulario.email" class="campo-input" placeholder="geral@cliente.pt">
    @error('formulario.email') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="campo-label" for="cliente-nif">NIF @if (in_array('nif', $obrigatorios, true))<span class="text-perigo-500">*</span>@endif</label>
    <input id="cliente-nif" type="text" wire:model="formulario.nif" class="campo-input tabular-nums" placeholder="501964843, ou com o país: GB123456789">
    @error('formulario.nif') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="campo-label" for="cliente-cc">Emails em cópia <span class="font-normal normal-case text-texto-fraco">(até 3)</span></label>
    <input id="cliente-cc" type="text" wire:model="formulario.emails_cc" class="campo-input" placeholder="Separados por vírgulas">
    @error('formulario.emails_cc') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="campo-label" for="cliente-morada">Morada @if (in_array('morada', $obrigatorios, true))<span class="text-perigo-500">*</span>@endif</label>
    <textarea id="cliente-morada" wire:model="formulario.morada" rows="3" class="campo-input" placeholder="Rua, código postal, localidade"></textarea>
    @error('formulario.morada') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
<div>
    <label class="campo-label" for="cliente-nota">Nota</label>
    <textarea id="cliente-nota" wire:model="formulario.nota" rows="3" class="campo-input"></textarea>
</div>
<div>
    <label class="campo-label" for="cliente-moeda">Moeda</label>
    <select id="cliente-moeda" wire:model="formulario.moeda" class="campo-select">
        @foreach ($moedas as $m)
            <option value="{{ $m }}">{{ $m }}</option>
        @endforeach
    </select>
    @error('formulario.moeda') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
</div>
