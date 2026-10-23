<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\GradingPeriod;
use App\Models\Homework;
use App\Models\Payment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents a parent may download, for their own children only.
 */
class ParentDocumentController extends Controller
{
    public function receipt(Request $request, Payment $payment): Response
    {
        $this->ensureOwnChild($request, $payment->student_id);

        return app(ReceiptController::class)->download($payment);
    }

    /**
     * A report card, once the school has released the period by locking it.
     */
    public function reportCard(Request $request, Student $student, GradingPeriod $gradingPeriod): Response
    {
        $this->ensureOwnChild($request, $student->id);
        abort_unless($gradingPeriod->isLocked(), 404);

        $enrollment = Enrollment::with('schoolClass')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $gradingPeriod->academic_year_id)
            ->firstOrFail();

        return app(ReportCardController::class)->pdf($request, $enrollment->schoolClass, $gradingPeriod, $student->id);
    }

    public function homeworkFile(Request $request, Homework $homework): Response
    {
        abort_unless(
            $homework->attachment_path !== null
            && $request->user()->children()->contains(fn (Student $child) => $child->currentEnrollment?->school_class_id === $homework->school_class_id),
            404,
        );

        return Storage::disk('local')->download($homework->attachment_path, $homework->attachment_name);
    }

    protected function ensureOwnChild(Request $request, int $studentId): void
    {
        abort_unless($request->user()->children()->contains('id', $studentId), 404);
    }
}
