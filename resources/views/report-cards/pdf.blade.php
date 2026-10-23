<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bulletins {{ $schoolClass->name }} · {{ $period->name }}</title>
    @include('report-cards.styles')
</head>
<body>
    @foreach ($rows as $row)
        @include('report-cards.card')
    @endforeach
</body>
</html>
