<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bulletins {{ $schoolClass->name }} · {{ $period->name }}</title>
    @include('report-cards.styles')
    <style>
        body { background: #f4f4f5; padding: 24px 16px; }
        .card { max-width: 210mm; margin: 0 auto 24px; background: #fff; padding: 12mm; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08); }
        .toolbar { max-width: 210mm; margin: 0 auto 16px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .toolbar a, .toolbar button { font: 600 13px/1 system-ui, sans-serif; padding: 10px 14px; border-radius: 10px; border: 1px solid #d4d4d8; background: #fff; color: #18181b; text-decoration: none; cursor: pointer; }
        .toolbar .primary { background: #15803d; border-color: #15803d; color: #fff; }
        .toolbar .warning { font: 13px system-ui, sans-serif; color: #92400e; }
        @media print {
            body { background: none; padding: 0; }
            .card { box-shadow: none; padding: 0; margin: 0; max-width: none; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">Imprimer {{ count($rows) > 1 ? 'les '.count($rows).' bulletins' : 'le bulletin' }}</button>
        <a href="{{ route('grades.report-cards.pdf', [$schoolClass, $period, ...(count($rows) === 1 && request('eleve') ? ['eleve' => request('eleve')] : [])]) }}">Télécharger le PDF</a>
        @unless ($period->isLocked())
            <span class="warning">Période non verrouillée : les notes peuvent encore changer.</span>
        @endunless
    </div>
    @foreach ($rows as $row)
        @include('report-cards.card')
    @endforeach
</body>
</html>
