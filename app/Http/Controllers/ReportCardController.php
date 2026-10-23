<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\GradingPeriod;
use App\Models\SchoolClass;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ReportCardController extends Controller
{
    /**
     * The class's report cards (or one student's, with ?eleve=) as pages to print from the browser.
     */
    public function show(Request $request, SchoolClass $schoolClass, GradingPeriod $gradingPeriod): View
    {
        return view('report-cards.show', $this->reportCards($request, $schoolClass, $gradingPeriod, $request->integer('eleve') ?: null));
    }

    /**
     * The same report cards as an A4 PDF, one page per student.
     */
    public function download(Request $request, SchoolClass $schoolClass, GradingPeriod $gradingPeriod): Response
    {
        return $this->pdf($request, $schoolClass, $gradingPeriod, $request->integer('eleve') ?: null);
    }

    /**
     * Report cards of a class, or of one of its students, as an A4 PDF download.
     */
    public function pdf(Request $request, SchoolClass $schoolClass, GradingPeriod $gradingPeriod, ?int $studentId): Response
    {
        $data = $this->reportCards($request, $schoolClass, $gradingPeriod, $studentId);
        $name = count($data['rows']) === 1 ? $data['rows'][0]['student']->full_name : $schoolClass->name;

        return Pdf::loadView('report-cards.pdf', $data)
            ->setPaper('a4')
            ->setOption('enable_font_subsetting', true)
            ->download('bulletins-'.Str::slug($name.' '.$gradingPeriod->name).'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    protected function reportCards(Request $request, SchoolClass $schoolClass, GradingPeriod $gradingPeriod, ?int $studentId): array
    {
        abort_unless($gradingPeriod->academic_year_id === $schoolClass->academic_year_id, 404);

        $results = $gradingPeriod->classResults($schoolClass);
        $rows = $results['students'];

        if ($studentId !== null) {
            $rows = array_values(array_filter($rows, fn (array $row) => $row['student']->id === $studentId));
            abort_if($rows === [], 404);
        }

        $schoolClass->load(['academicYear', 'headTeacher']);

        return [
            'school' => $request->user()->school,
            'schoolClass' => $schoolClass,
            'period' => $gradingPeriod,
            'results' => $results,
            'rows' => $rows,
            'scale' => $request->user()->school->scaleFor($schoolClass->cycle),
            'attendance' => Absence::countsFor(
                array_map(fn (array $row) => $row['student']->id, $rows),
                $gradingPeriod->starts_on,
                $gradingPeriod->ends_on,
            ),
            // Ranks held by more than one student are marked "ex aequo".
            'sharedRanks' => array_keys(array_filter(array_count_values(array_filter(array_column($results['students'], 'rank'))), fn (int $count) => $count > 1)),
        ];
    }
}
