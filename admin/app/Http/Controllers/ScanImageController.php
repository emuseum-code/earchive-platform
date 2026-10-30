<?php

namespace App\Http\Controllers;

use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Видача сканів лише за правами (ТЗ 12): сховище не відкривається публічно.
 * Мініатюри генеруються один раз і кешуються; оригінал ніколи не змінюється.
 */
class ScanImageController extends Controller
{
    public function image(Request $request, Scan $scan)
    {
        $this->authorizeScan($request, $scan);
        $path = $scan->absolutePath();
        abort_unless(is_file($path), 404, 'Скан недоступний');

        return response()->file($path, ['Cache-Control' => 'private, max-age=86400', 'Content-Type' => 'image/jpeg']);
    }

    public function thumb(Request $request, Scan $scan)
    {
        $this->authorizeScan($request, $scan);
        $rel = 'thumbs/'.intdiv($scan->id, 1000).'/'.$scan->id.'-'.($scan->file_mtime?->timestamp ?? 0).'.jpg';
        $disk = Storage::disk('local');
        if (! $disk->exists($rel)) {
            $src = $scan->absolutePath();
            abort_unless(is_file($src), 404);
            $img = @imagecreatefromjpeg($src);
            abort_unless($img, 415, 'Пошкоджений JPG');
            $w = imagesx($img);
            $h = imagesy($img);
            $tw = 320;
            $th = (int) round($h * $tw / max(1, $w));
            $t = imagecreatetruecolor($tw, $th);
            imagecopyresampled($t, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
            @mkdir(dirname($disk->path($rel)), 0775, true);
            imagejpeg($t, $disk->path($rel), 78);
        }

        return response()->file($disk->path($rel), ['Cache-Control' => 'private, max-age=604800', 'Content-Type' => 'image/jpeg']);
    }

    public function download(Request $request, \App\Models\Export $export)
    {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $export->user_id === $user->id), 403);
        abort_unless($export->file_path && Storage::disk('local')->exists($export->file_path), 404);

        return Storage::disk('local')->download($export->file_path);
    }

    private function authorizeScan(Request $request, Scan $scan): void
    {
        $user = $request->user();
        abort_unless($user && $user->is_active, 403);
        $ids = $user->bookScope();
        abort_unless($ids === null || in_array($scan->book_id, $ids, true), 403);
    }
}
