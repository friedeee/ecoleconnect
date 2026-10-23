<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reçu {{ $payment->receipt_number }} · {{ $payment->student->full_name }}</title>
    @include('receipts.styles')
    <style>
        body { background: #f4f4f5; padding: 24px 16px; }
        .sheet { max-width: 148mm; margin: 0 auto; background: #fff; padding: 12mm; box-shadow: 0 4px 24px rgba(0, 0, 0, 0.08); }
        .toolbar { max-width: 148mm; margin: 0 auto 16px; display: flex; gap: 8px; flex-wrap: wrap; }
        .toolbar a, .toolbar button { font: 600 13px/1 system-ui, sans-serif; padding: 10px 14px; border-radius: 10px; border: 1px solid #d4d4d8; background: #fff; color: #18181b; text-decoration: none; cursor: pointer; }
        .toolbar .primary { background: #15803d; border-color: #15803d; color: #fff; }
        @media print {
            body { background: none; padding: 0; }
            .sheet { box-shadow: none; padding: 0; max-width: none; }
            .toolbar { display: none; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="primary" onclick="window.print()">Imprimer</button>
        <a href="{{ route('fees.receipt.pdf', $payment) }}">Télécharger le PDF</a>
        <a href="{{ route('students.show', $payment->student) }}">Retour à la fiche de l'élève</a>
    </div>
    <div class="sheet">
        @include('receipts.body')
    </div>
</body>
</html>
