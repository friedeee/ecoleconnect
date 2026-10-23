@php
    $school = $payment->student->school;
    $money = fn (int $amount): string => number_format($amount, 0, ',', "\u{00A0}")."\u{00A0}F\u{00A0}CFA";
@endphp

<div class="receipt">
    @if ($payment->isCancelled())
        <div class="stamp">ANNULÉ</div>
    @endif

    <table class="header">
        <tr>
            <td>
                <p class="school">{{ $school->name }}</p>
                <p class="muted">{{ collect([$school->address, $school->city])->filter()->implode(', ') }}</p>
                <p class="muted">{{ collect([$school->phone, $school->email])->filter()->implode(' · ') }}</p>
            </td>
            <td class="right">
                <p class="title">REÇU DE PAIEMENT</p>
                <p class="number">N° {{ $payment->receipt_number }}</p>
                <p class="muted">du {{ $payment->paid_on->format('d/m/Y') }}</p>
            </td>
        </tr>
    </table>

    <table class="details">
        <tr><th>Élève</th><td><strong>{{ $payment->student->full_name }}</strong></td></tr>
        <tr><th>Matricule</th><td>{{ $payment->student->registration_number }}</td></tr>
        <tr><th>Classe</th><td>{{ $enrollment?->schoolClass->name ?? '—' }} · {{ $payment->academicYear->name }}</td></tr>
        <tr><th>Mode de paiement</th><td>{{ $payment->method->label() }}{{ $payment->reference ? ' · Réf. '.$payment->reference : '' }}</td></tr>
        @if ($payment->notes)
            <tr><th>Objet</th><td>{{ $payment->notes }}</td></tr>
        @endif
    </table>

    <div class="amount">
        <p class="muted">Montant reçu</p>
        <p class="figure">{{ $money($payment->amount) }}</p>
    </div>

    <p class="words">
        Arrêté le présent reçu à la somme de <strong>{{ \App\Models\Payment::amountInWords($payment->amount) }} francs CFA</strong>.
    </p>

    @if ($balanceAfter !== null && ! $payment->isCancelled())
        <p class="balance">
            @if ($balanceAfter > 0)
                Reste à payer pour l'année après ce versement : <strong>{{ $money($balanceAfter) }}</strong>
            @elseif ($balanceAfter === 0)
                Scolarité de l'année <strong>entièrement réglée</strong>.
            @else
                Avance sur l'année : <strong>{{ $money(-$balanceAfter) }}</strong>
            @endif
        </p>
    @endif

    @if ($payment->isCancelled())
        <p class="cancelled">
            Reçu annulé le {{ $payment->cancelled_at->format('d/m/Y') }} par {{ $payment->canceller?->name }} : {{ $payment->cancellation_reason }}
        </p>
    @endif

    <table class="signatures">
        <tr>
            <td>Le parent</td>
            <td class="right">Reçu par {{ $payment->receiver->name }}<br><span class="muted">Signature et cachet</span></td>
        </tr>
    </table>
</div>
