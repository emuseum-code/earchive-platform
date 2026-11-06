<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\RecognitionResult;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/** Готові результати розпізнавання справжніх PDF еАрхіву проходять шлях відповіді Gemini. */
class EarchiveRecognizedTest extends TestCase
{
    use RefreshDatabase;

    public function test_recognized_results_create_documents_with_continuations(): void
    {
        if (! (new ExecutableFinder)->find('pdftoppm')) {
            $this->markTestSkipped('pdftoppm (poppler-utils) не встановлено');
        }
        $this->seed();
        Settings::set('storage.root', storage_path('framework/testing/scans-'.uniqid()));
        $this->artisan('archive:import-earchive', ['category' => 2, '--ids' => '648', '--dpi' => 40, '--recognized' => true])->assertSuccessful();

        $book = Book::firstOrFail();
        $this->assertSame('Ф. 1, оп. 16, спр. 4497', $book->archiveCode());
        $this->assertSame(3, RecognitionResult::where('schema_valid', true)->count());
        // постанова (арк. 95) і спільна заява на двох аркушах (96–97)
        $records = $book->records()->orderBy('sequence')->get();
        $this->assertCount(2, $records);
        $this->assertSame('№ 495-ХІІ', $records[0]->doc_index);
        $this->assertSame(2, $records[1]->pages()->count());
        $this->assertStringContainsString('Б.ЄЛЬЦИН', $records[1]->full_text);

        // пошук по слову знаходить документ і підсвічує збіг
        $this->actingAs(User::where('email', 'admin@archive.test')->firstOrFail())
            ->get('/admin/search?q='.urlencode('Єльцин'))
            ->assertOk()
            ->assertSee('Пошук по архіву')
            ->assertSee('<mark>', false)
            ->assertSee('Ф. 1, оп. 16, спр. 4497');
    }
}
