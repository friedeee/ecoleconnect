<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReceiptController extends Controller
{
    /**
     * The receipt as a page to print from the browser.
     */
    public function show(Payment $payment): View
    {
        return view('receipts.show', $this->receipt($payment));
    }

    /**
     * The same receipt as an A5 PDF file, to save or send to the parents.
     */
    public function download(Payment $payment): Response
    {
        return Pdf::loadView('receipts.pdf', $this->receipt($payment))
            ->setPaper('a5')
            ->setOption('enable_font_subsetting', true)
            ->download('recu-'.$payment->receipt_number.'.pdf');
    }

    /**
     * @return array{payment: Payment, enrollment: ?Enrollment, balanceAfter: ?int}
     */
    protected function receipt(Payment $payment): array
    {
        $payment->load(['student', 'academicYear', 'receiver', 'canceller']);

        $enrollment = Enrollment::with(['schoolClass', 'academicYear'])
            ->where('student_id', $payment->student_id)
            ->where('academic_year_id', $payment->academic_year_id)
            ->first();

        // What remained to pay for the year once this payment was received.
        $paidSoFar = (int) Payment::valid()
            ->where('student_id', $payment->student_id)
            ->where('academic_year_id', $payment->academic_year_id)
            ->where('id', '<=', $payment->id)
            ->sum('amount');

        return [
            'payment' => $payment,
            'enrollment' => $enrollment,
            'balanceAfter' => $enrollment ? $enrollment->feeStatement()['due'] - $paidSoFar : null,
        ];
    }
}
