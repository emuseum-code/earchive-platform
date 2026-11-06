<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Museum;
use App\Models\Record;
use App\Support\ArchiveSearch;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Каталог еАрхіву МЗС (1014 документів) стає справами й документами без сканів; повторний запуск нічого не дублює. */
class EarchiveCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_import_creates_documents_once(): void
    {
        $this->seed();
        Settings::set('storage.root', storage_path('framework/testing/catalog-'.uniqid()));
        $this->artisan('archive:import-earchive-catalog')->assertSuccessful();

        // 10 документів з PDF імпортує archive:import-earchive, решта — тут
        $this->assertSame(1004, Record::count());
        $this->assertSame('м. Київ/ЦДАВО України', Museum::where('name', 'ЦДАВО України')->value('folder_path'));
        $this->assertSame('За кордоном/ЦДА Болгарії', Museum::where('name', 'ЦДА Болгарії')->value('folder_path'));
        $record = Record::where('title', 'Третій Універсал Української Центральної Ради')->firstOrFail();
        $this->assertStringStartsWith('Ф. 1115, оп. 1, спр. 4', $record->archive_code);
        $this->assertNotNull($record->extra_fields['earchive_url'] ?? null);
        $this->assertGreaterThan(0, ArchiveSearch::query('Універсалу')->count());

        $books = Book::count();
        $this->artisan('archive:import-earchive-catalog')->assertSuccessful();
        $this->assertSame(1004, Record::count());
        $this->assertSame($books, Book::count());

        // PDF зі старого сайту (documents-earchive) → аркуші справи, прив'язані до документа, без розпізнавання
        $file = preg_replace('#^/files/#', '', $record->extra_fields['earchive_file']);
        $pdfDir = storage_path('framework/testing/pdfs-'.uniqid());
        File::ensureDirectoryExists(dirname($pdfDir.'/'.$file));
        File::copy(collect(File::files(database_path('data/earchive/pdfs')))->first()->getPathname(), $pdfDir.'/'.$file);
        $this->artisan('archive:attach-earchive-scans', ['--pdf-dir' => $pdfDir, '--dpi' => 40])->assertSuccessful();
        $record->refresh();
        $this->assertGreaterThan(0, $record->scans()->count());
        $this->assertStringStartsWith('арк. ', $record->scans()->first()->page_label);
        $this->assertNull($record->recognition_result_id);
        $pages = $record->scans()->count();
        $this->artisan('archive:attach-earchive-scans', ['--pdf-dir' => $pdfDir, '--dpi' => 40])->assertSuccessful();
        $this->assertSame($pages, $record->scans()->count());
    }
}
