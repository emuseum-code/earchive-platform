<?php

namespace Tests\Feature;

use App\Models\Adaptation;
use App\Models\Book;
use App\Models\Record;
use App\Models\User;
use App\Services\AdaptationService;
use App\Services\StorageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Демо-справа сідиться, а всі сторінки адмінки відкриваються без помилок. */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_demo_case_is_recognized_into_documents(): void
    {
        $book = Book::firstOrFail();
        $this->assertSame('Ф. Р-101, оп. 1, спр. 7', $book->archiveCode());
        $this->assertSame(4, Record::count());

        // лист на двох аркушах — один документ, текст другого аркуша дописано
        $letter = Record::where('record_no', '1')->firstOrFail();
        $this->assertSame(2, $letter->pages()->count());
        $this->assertStringContainsString('Доводжу до відома', $letter->full_text);
        $this->assertStringContainsString('Т. Гнатюк', $letter->full_text);
        $this->assertSame(2, Adaptation::where('status', 'adapted')->count());
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
            '/admin/records?search=Гнатюк', '/admin/records?filters[museum_id][value]='.$record->museum_id, "/admin/records/{$record->id}", "/admin/records/{$record->id}/review", "/admin/records/{$record->id}/edit",
            "/admin/books/{$book->id}", "/admin/books/{$book->id}/pages", "/admin/books/{$book->id}/edit",
            '/admin/adaptations/'.Adaptation::firstOrFail()->id,
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
