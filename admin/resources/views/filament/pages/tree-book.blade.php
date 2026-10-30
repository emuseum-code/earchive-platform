<li wire:key="b{{ $b->id }}" class="bk-tree__book">
    <a class="bk-link" href="{{ \App\Filament\Resources\BookResource::getUrl('view', ['record' => $b]) }}">📖 {{ $b->title }}</a>
    <span class="bk-muted">· сканів {{ $b->scans_count }} · розпізнано {{ $b->recognitionProgress() }}% · записів {{ $b->records_count }} · перевірено {{ $b->verificationProgress() }}%</span>
    @if ($b->scans_failed + $b->scans_missing)<span class="bk-badge bk-badge--danger">помилок {{ $b->scans_failed + $b->scans_missing }}</span>@endif
</li>
