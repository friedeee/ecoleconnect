@php
    $format = fn (?float $value) => $value === null ? '—' : number_format($value * $scale / 20, 2, ',', ' ');
    $ordinal = fn (?int $rank) => $rank === null ? '—' : ($rank === 1 ? '1er' : $rank.'e').(in_array($rank, $sharedRanks, true) ? ' ex' : '');
    $student = $row['student'];
    $points = 0.0;
    $coefficients = 0;
@endphp

<div class="card">
    <table class="header">
        <tr>
            <td>
                <p class="school">{{ $school->name }}</p>
                @if ($school->motto)
                    <p class="motto">{{ $school->motto }}</p>
                @endif
                <p class="muted">{{ collect([$school->address, $school->city, $school->phone])->filter()->implode(' · ') }}</p>
            </td>
            <td class="right">
                <p><strong>Année scolaire {{ $schoolClass->academicYear->name }}</strong></p>
                <p class="muted">{{ $period->name }}</p>
            </td>
        </tr>
    </table>

    <p class="title">BULLETIN DE NOTES — {{ mb_strtoupper($period->name) }}</p>

    <table class="identity">
        <tr>
            <td class="label">Élève</td><td><strong>{{ $student->full_name }}</strong></td>
            <td class="label">Matricule</td><td>{{ $student->registration_number }}</td>
        </tr>
        <tr>
            <td class="label">Classe</td><td>{{ $schoolClass->name }} · effectif {{ count($results['students']) }}</td>
            <td class="label">Né(e) le</td><td>{{ $student->birth_date?->format('d/m/Y') ?? '—' }}{{ $student->birth_place ? ' à '.$student->birth_place : '' }}</td>
        </tr>
    </table>

    <table class="grades">
        <thead>
            <tr>
                <th style="text-align:left">Matière</th>
                <th>Coef.</th>
                <th>Moy. /{{ $scale }}</th>
                <th>Moy. × coef.</th>
                <th>Rang</th>
                <th>Moy. classe</th>
                <th style="text-align:left">Appréciation</th>
                <th style="text-align:left">Enseignant</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($results['subjects'] as $line)
                @php
                    $average = $row['averages'][$line['subject']->id] ?? null;
                    if ($average !== null) {
                        $points += $average * $scale / 20 * $line['coefficient'];
                        $coefficients += $line['coefficient'];
                    }
                @endphp
                <tr>
                    <td>{{ $line['subject']->name }}</td>
                    <td class="center">{{ $line['coefficient'] }}</td>
                    <td class="center"><strong>{{ $format($average) }}</strong></td>
                    <td class="center">{{ $average === null ? '—' : number_format($average * $scale / 20 * $line['coefficient'], 2, ',', ' ') }}</td>
                    <td class="center">{{ $ordinal($row['subject_ranks'][$line['subject']->id] ?? null) }}</td>
                    <td class="center muted">{{ $format($line['average']) }}</td>
                    <td>{{ $average === null ? 'Non évalué' : \App\Enums\Mention::fromAverage($average)->label() }}</td>
                    <td class="muted">{{ $line['teacher']?->name ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="center">{{ $coefficients }}</td>
                <td></td>
                <td class="center">{{ number_format($points, 2, ',', ' ') }}</td>
                <td colspan="4"></td>
            </tr>
        </tfoot>
    </table>

    <table class="summary">
        <tr>
            <td>
                <p class="muted">Moyenne générale</p>
                <p class="big">{{ $format($row['general']) }} / {{ $scale }}</p>
                <p>Rang : <strong>{{ $ordinal($row['rank']) }}</strong> sur {{ $results['graded'] }}</p>
                <p>Mention : <strong>{{ $row['mention']?->label() ?? '—' }}</strong></p>
                @php($presence = $attendance[$student->id] ?? ['absent' => 0, 'justified' => 0, 'late' => 0])
                <p>Absences : <strong>{{ $presence['absent'] }}</strong> demi-journée(s){{ $presence['justified'] > 0 ? ' dont '.$presence['justified'].' justifiée(s)' : '' }} · Retards : <strong>{{ $presence['late'] }}</strong></p>
            </td>
            <td>
                <p class="muted">Résultats de la classe</p>
                <p>Moyenne de la classe : <strong>{{ $format($results['class_average']) }}</strong></p>
                <p>Plus forte moyenne : {{ $format($results['highest']) }}</p>
                <p>Plus faible moyenne : {{ $format($results['lowest']) }}</p>
            </td>
        </tr>
    </table>

    <table class="signatures">
        <tr>
            <td>{{ $schoolClass->cycle->headTeacherTitle() }}<br><span class="muted">{{ $schoolClass->headTeacher?->name }}</span></td>
            <td>Le Directeur</td>
            <td>Les parents</td>
        </tr>
    </table>
</div>
