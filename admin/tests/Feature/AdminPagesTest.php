<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Record;
use App\Models\User;
use App\Services\AdaptationService;
use App\Services\StorageSync;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/** Демо-справа сідиться, а всі сторінки адмінки відкриваються без помилок. */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        if (! (new ExecutableFinder)->find('pdftoppm')) {
            $this->markTestSkipped('pdftoppm (poppler-utils) не встановлено');
        }
        config(['filesystems.disks.local.root' => storage_path('framework/testing')]);
        Settings::set('storage.root', $root = storage_path('framework/testing/scans-'.uniqid()));
        $pdfDir = $root.'-pdf';
        File::ensureDirectoryExists($pdfDir);
        file_put_contents($pdfDir.'/3696-2-21а-ark7-10.pdf', Pdf::loadHTML('<p>Нота</p><div style="page-break-after:always"></div><p>2</p>')->output());
        $this->artisan('archive:import-earchive', ['category' => 2, '--ids' => '474', '--pdf-dir' => $pdfDir, '--dpi' => 30])->assertSuccessful();
        // документ, як після розпізнавання
        $book = Book::firstOrFail();
        $scan = $book->scans()->orderBy('page_order')->firstOrFail();
        $record = Record::create(['book_id' => $book->id, 'museum_id' => $book->museum_id, 'fund_id' => $book->fund_id, 'record_no' => '1',
            'doc_type' => 'Нота', 'title' => 'Нота Голови Делегації УНР в Парижі', 'doc_date' => '12 лютого 1919', 'full_text' => 'Monsieur le Président…']);
        $record->pages()->create(['scan_id' => $scan->id, 'position' => 1]);
    }

    public function test_earchive_case_is_imported(): void
    {
        $book = Book::firstOrFail();
        $this->assertSame('Ф. 3696, оп. 2, спр. 21а', $book->archiveCode());
        $this->assertSame(2, $book->scans()->count());
        $this->assertStringContainsString('earchive.mfa.gov.ua/catalog/', $book->notes);
    }

    public function test_admin_pages_open(): void
    {
        $this->actingAs(User::where('email', 'admin@archive.test')->firstOrFail());
        $record = Record::where('record_no', '1')->firstOrFail();
        $book = Book::firstOrFail();
        $urls = [
            '/admin', '/admin/records-by-museum', '/admin/museum-desk', '/admin/tree', '/admin/books',
            '/admin/museums', '/admin/adaptations', '/admin/exports', '/admin/settings', '/admin/registry-schemas', '/admin/adaptation-rules',
            '/admin/recognition-templates', '/admin/dictionaries', '/admin/review-remarks', '/admin/processing-jobs', '/admin/users',
            '/admin/records?search=Président', '/admin/records?filters[museum_id][value]='.$record->museum_id, "/admin/records/{$record->id}", "/admin/records/{$record->id}/review", "/admin/records/{$record->id}/edit",
            "/admin/books/{$book->id}", "/admin/books/{$book->id}/pages", "/admin/books/{$book->id}/edit",
        ];
        foreach ($urls as $url) {
            $r = $this->get($url);
            $this->assertSame(200, $r->status(), $url.' → '.$r->headers->get('Location').' '.mb_substr(strip_tags((string) $r->exception?->getMessage()), 0, 300));
        }
    }

    public function test_folder_numbers(): void
    {
        $this->assertSame('2', StorageSync::number('Опис 2'));
        $this->assertSame('Р-101', StorageSync::number('Ф. Р-101 Вишнівська трудова школа', true));
        $this->assertSame('15а', StorageSync::number('Справа 15а', true));
        $this->assertNull(StorageSync::number('Листування', true));
    }

    public function test_dates_in_words(): void
    {
        $this->assertSame('1927-03-12', AdaptationService::normalizeDate('12 березня 1927 р.')[0]);
        $this->assertSame('1913-05-03', AdaptationService::normalizeDate('3 мая 1913 г.')[0]);
        $this->assertSame('1928', AdaptationService::normalizeDate('1928 р.')[0]);
    }
}
