<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Reçu {{ $payment->receipt_number }}</title>
    @include('receipts.styles')
</head>
<body>
    @include('receipts.body')
</body>
</html>
