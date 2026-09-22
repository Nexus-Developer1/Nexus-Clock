{{-- Janela de acrescentar/alterar um registo de tempo (trait App\Livewire\Concerns\FormularioRegisto).
     Espera: $editarId, $formulario, $membrosDoNovo, $clientesFiltrados, $contratos, $projetosDoFormulario. --}}
@if ($editarId !== null)
    <div class="janela-fundo fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 px-4 py-10" wire:keydown.escape="fecharFormulario" role="dialog" aria-modal="true" aria-labelledby="titulo-registo">
        <div class="absolute inset-0" wire:click="fecharFormulario"></div>
        <form wire:submit="guardar" class="janela relative w-full max-w-xl rounded-2xl bg-white shadow-2xl">
            <header class="flex items-center justify-between border-b border-borda px-6 py-4">
                <h2 id="titulo-registo" class="text-lg font-semibold text-texto-forte">{{ $editarId === 0 ? 'Acrescentar tempo' : 'Alterar registo' }}</h2>
                <button type="button" wire:click="fecharFormulario" class="botao-icone" aria-label="Fechar"><x-icone nome="fechar" /></button>
            </header>

            <div class="space-y-4 px-6 py-5">
                @error('formulario.geral') <div class="rounded-xl border border-perigo-200 bg-perigo-100 px-4 py-3 text-sm text-perigo-600">{{ $message }}</div> @enderror

                @if ($editarId === 0 && count($membrosDoNovo) > 1)
                    <div>
                        <label class="campo-label" for="registo-membro">Membro</label>
                        <select id="registo-membro" wire:model="formulario.tecnico_id" class="campo-select">
                            @foreach ($membrosDoNovo as $id => $nome)
                                <option value="{{ $id }}">{{ $nome }}</option>
                            @endforeach
                        </select>
                        @error('formulario.tecnico_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label class="campo-label" for="registo-descricao">Descrição</label>
                    <input id="registo-descricao" type="text" wire:model="formulario.descricao" class="campo-input" placeholder="Em que trabalhou?">
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="campo-label" for="registo-cliente">Cliente <span class="text-perigo-500">*</span></label>
                        @include('livewire.partials.combobox-cliente', ['idCampo' => 'registo-cliente', 'placeholder' => 'Pesquisar cliente'])
                        @error('formulario.cliente_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="campo-label" for="registo-contrato">Contrato</label>
                        <select id="registo-contrato" wire:model="formulario.contrato_id" class="campo-select" @disabled(! ($formulario['cliente_id'] ?? null))>
                            <option value="">Sem contrato</option>
                            @foreach ($contratos as $id => $numero)
                                <option value="{{ $id }}">{{ $numero }}</option>
                            @endforeach
                        </select>
                        @error('formulario.contrato_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="campo-label" for="registo-projeto">Projeto</label>
                    <select id="registo-projeto" wire:model="formulario.projeto_id" class="campo-select">
                        <option value="">Sem projeto</option>
                        @foreach ($projetosDoFormulario as $p)
                            <option value="{{ $p->id }}">{{ $p->nome }}</option>
                        @endforeach
                    </select>
                    @error('formulario.projeto_id') <p class="mt-1.5 text-xs text-perigo-500">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-[1.4fr_1fr_1fr_1fr]">
                    <div class="col-span-2 sm:col-span-1">
                        <label class="campo-label" for="registo-dia">Dia</label>
                        <input id="registo-dia" type="date" wire:model="formulario.dia" class="campo-input">
                    </div>
                    <div>
                        <label class="campo-label" for="registo-inicio">Início</label>
                        <input id="registo-inicio" type="time" wire:model="formulario.hora_inicio" class="campo-input tabular-nums">
                    </div>
                    <div>
                        <label class="campo-label" for="registo-fim">Fim</label>
                        <input id="registo-fim" type="time" wire:model="formulario.hora_fim" class="campo-input tabular-nums">
                    </div>
                    <div class="col-span-2 sm:col-span-1">
                        <label class="campo-label" for="registo-duracao">ou Duração</label>
                        <input id="registo-duracao" type="text" wire:model="formulario.duracao" class="campo-input tabular-nums" placeholder="1:30">
                    </div>
                </div>
                @foreach (['dia', 'hora', 'duracao', 'inicio', 'fim', 'duracao_seg'] as $campo)
                    @error('formulario.'.$campo) <p class="-mt-2 text-xs text-perigo-500">{{ $message }}</p> @enderror
                @endforeach

                <div class="grid grid-cols-1 items-end gap-4 sm:grid-cols-[1fr_auto]">
                    <div>
                        <label class="campo-label" for="registo-etiquetas">Etiquetas</label>
                        <input id="registo-etiquetas" type="text" wire:model="formulario.etiquetas" class="campo-input" placeholder="Separadas por vírgulas">
                    </div>
                    <label class="inline-flex h-11 items-center gap-2 text-sm font-medium text-texto-forte">
                        <input type="checkbox" wire:model="formulario.faturavel" class="h-4 w-4 rounded border-borda text-verde-600 focus:ring-verde-500"> Faturável
                    </label>
                </div>
            </div>

            <footer class="flex items-center justify-end gap-3 rounded-b-2xl border-t border-borda bg-fundo/50 px-6 py-4">
                <button type="button" wire:click="fecharFormulario" class="botao-secundario">Cancelar</button>
                <button type="submit" class="botao-primario"><x-icone nome="visto" traco="2" /> {{ $editarId === 0 ? 'Acrescentar' : 'Guardar' }}</button>
            </footer>
        </form>
    </div>
@endif
