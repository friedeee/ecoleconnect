<?php

namespace Tests\Feature;

use App\Enums\ClassLevel;
use App\Enums\FeeLineStatus;
use App\Enums\Role;
use App\Enums\Section;
use App\Models\Enrollment;
use App\Models\FeeItem;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class FeesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $accountant;

    private Enrollment $enrollment;

    private FeeItem $uniform;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-16 10:00');

        $this->admin = $this->schoolAdmin();
        $school = $this->admin->school;
        $year = $school->currentAcademicYear;
        $this->accountant = User::factory()->accountant()->for($school)->create();

        $class = SchoolClass::factory()->for($school)->for($year)->create(['name' => 'CM1', 'level' => ClassLevel::Cm1]);
        $this->enrollment = Enrollment::factory()->create(['school_class_id' => $class->id]);

        $schedule = FeeItem::factory()->for($year)->state(['school_id' => $school->id, 'level' => ClassLevel::Cm1]);
        $schedule->create(['label' => 'Inscription', 'amount' => 15000, 'due_on' => null]);
        $schedule->create(['label' => '1re tranche', 'amount' => 30000, 'due_on' => '2026-10-01']);
        $schedule->create(['label' => '2e tranche', 'amount' => 30000, 'due_on' => '2027-01-15']);
        $this->uniform = $schedule->optional()->create(['label' => 'Tenue', 'amount' => 8000]);
    }

    private function collect(User $cashier, array $fields): Testable
    {
        return Livewire::actingAs($cashier)
            ->test('student-fees', ['enrollment' => $this->enrollment])
            ->call('openForm')
            ->set(array_merge(['method' => 'cash', 'reference' => '', 'notes' => ''], $fields))
            ->call('record');
    }

    public function test_the_statement_shows_what_is_late_and_what_is_to_come(): void
    {
        $this->actingAs($this->admin);

        $statement = $this->enrollment->feeStatement();

        $this->assertSame(75000, $statement['due']);
        $this->assertSame(45000, $statement['overdue']);
        $this->assertSame(
            [FeeLineStatus::Overdue, FeeLineStatus::Overdue, FeeLineStatus::Upcoming],
            array_column($statement['lines'], 'status'),
        );
    }

    public function test_the_accountant_collects_payments_with_numbered_receipts(): void
    {
        $this->collect($this->accountant, ['amount' => '20000'])->assertHasNoErrors()->assertSee('Paiement enregistré.');
        $this->collect($this->accountant, ['amount' => '5000'])->assertHasNoErrors();

        $this->assertSame(['2026-00001', '2026-00002'], Payment::orderBy('id')->pluck('receipt_number')->all());

        $this->actingAs($this->accountant);
        $statement = $this->enrollment->feeStatement();
        $this->assertSame(25000, $statement['paid']);
        $this->assertSame(20000, $statement['overdue']);
        $this->assertSame([15000, 10000, 0], array_column($statement['lines'], 'paid'));
        $this->assertSame(FeeLineStatus::Paid, $statement['lines'][0]['status']);
    }

    public function test_the_form_suggests_the_overdue_amount(): void
    {
        Livewire::actingAs($this->accountant)
            ->test('student-fees', ['enrollment' => $this->enrollment])
            ->call('openForm')
            ->assertSet('amount', '45000');
    }

    public function test_mobile_money_needs_a_transaction_reference(): void
    {
        $this->collect($this->accountant, ['amount' => '10000', 'method' => 'mobile_money'])->assertHasErrors(['reference' => 'required']);
        $this->collect($this->accountant, ['amount' => '10000', 'method' => 'mobile_money', 'reference' => 'MTN-88412'])->assertHasNoErrors();
    }

    public function test_a_payment_cannot_be_dated_in_the_future(): void
    {
        $this->collect($this->accountant, ['amount' => '10000', 'paid_on' => '2026-10-17'])->assertHasErrors(['paid_on']);
    }

    public function test_an_optional_fee_is_added_when_the_student_takes_it(): void
    {
        Livewire::actingAs($this->accountant)
            ->test('student-fees', ['enrollment' => $this->enrollment])
            ->call('toggleOptionalFee', $this->uniform->id);

        $this->actingAs($this->accountant);
        $this->assertSame(83000, $this->enrollment->feeStatement()['due']);
    }

    public function test_a_cancelled_payment_keeps_its_trace_and_no_longer_counts(): void
    {
        $this->collect($this->accountant, ['amount' => '20000']);
        $payment = Payment::sole();

        $component = Livewire::actingAs($this->admin)
            ->test('student-fees', ['enrollment' => $this->enrollment])
            ->call('startCancelling', $payment->id)
            ->call('cancelPayment')
            ->assertHasErrors(['cancellation_reason' => 'required'])
            ->set('cancellation_reason', 'Erreur de saisie du montant')
            ->call('cancelPayment')
            ->assertHasNoErrors();

        $payment->refresh();
        $this->assertTrue($payment->isCancelled());
        $this->assertSame($this->admin->id, $payment->cancelled_by);
        $this->assertSame(0, $this->enrollment->feeStatement()['paid']);
        $component->assertSee('Erreur de saisie du montant');
    }

    public function test_a_section_director_follows_fees_but_does_not_handle_money(): void
    {
        $director = User::factory()->admin()->for($this->admin->school)->inSection(Section::Basic)->create();

        $this->actingAs($director)->get(route('fees.index'))->assertOk();
        $this->get(route('students.show', $this->enrollment->student))->assertSee('Frais de scolarité 2026-2027')->assertDontSee('Encaisser');
        $this->get(route('fees.schedule'))->assertForbidden();

        Livewire::actingAs($director)
            ->test('student-fees', ['enrollment' => $this->enrollment])
            ->call('openForm')
            ->assertForbidden();
    }

    public function test_teachers_do_not_see_fees(): void
    {
        $teacher = User::factory()->for($this->admin->school)->create(['role' => Role::Teacher]);

        $this->actingAs($teacher)->get(route('fees.index'))->assertForbidden();
        $this->get(route('students.show', $this->enrollment->student))->assertOk()->assertDontSee('Frais de scolarité 2026-2027');
    }

    public function test_the_fees_page_lists_late_students_and_year_totals(): void
    {
        $this->collect($this->accountant, ['amount' => '20000']);
        $upToDate = Enrollment::factory()->create(['school_class_id' => $this->enrollment->school_class_id]);
        Payment::factory()->create(['enrollment_id' => $upToDate->id, 'amount' => 45000]);

        $component = Livewire::actingAs($this->accountant)->test('pages::fees.index');

        $this->assertSame(['due' => 150000, 'paid' => 65000, 'overdue' => 25000, 'late_students' => 1, 'today' => 20000], $component->instance()->totals);
        $this->assertSame([$this->enrollment->id], $component->instance()->unpaid->pluck('id')->all());

        $component->set('tab', 'encaissements')->assertSee('2026-00001')->assertSee($upToDate->student->full_name);
    }

    public function test_the_receipt_prints_and_downloads_as_pdf(): void
    {
        $this->collect($this->accountant, ['amount' => '45000', 'notes' => '1re tranche']);
        $payment = Payment::sole();

        $this->actingAs($this->accountant)
            ->get(route('fees.receipt', $payment))
            ->assertOk()
            ->assertSee('N° 2026-00001')
            ->assertSee('quarante-cinq mille francs CFA')
            ->assertSeeText('Reste à payer pour l\'année après ce versement');

        $pdf = $this->get(route('fees.receipt.pdf', $payment))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_a_receipt_of_another_school_is_not_found(): void
    {
        $this->collect($this->accountant, ['amount' => '10000']);
        $payment = Payment::sole();

        $this->actingAs($this->schoolAdmin())->get(route('fees.receipt', $payment))->assertNotFound();
        $this->get(route('fees.receipt.pdf', $payment))->assertNotFound();
    }

    public function test_the_accountant_builds_and_copies_a_fee_schedule(): void
    {
        $component = Livewire::actingAs($this->accountant)
            ->test('pages::fees.schedule')
            ->call('selectLevel', 'CE2')
            ->set(['label' => 'Inscription', 'amount' => '12000', 'due_on' => ''])
            ->call('save')
            ->assertHasNoErrors()
            ->set('copyTo', ['CM2'])
            ->call('copy')
            ->assertHasNoErrors();

        $this->assertSame(1, FeeItem::where('level', 'CM2')->where('label', 'Inscription')->where('amount', 12000)->count());

        $component->set('copyTo', ['CM1'])->call('copy')->assertHasErrors(['copyTo.0']);
    }

    public function test_sql_totals_match_the_statement(): void
    {
        $this->collect($this->accountant, ['amount' => '20000']);
        $this->actingAs($this->accountant);

        $account = Enrollment::withFeeTotals()->whereKey($this->enrollment->id)->sole();
        $statement = $this->enrollment->feeStatement();

        $this->assertSame($statement['due'], (int) $account->fees_due);
        $this->assertSame($statement['paid'], (int) $account->fees_paid);
        $this->assertSame($statement['overdue'], (int) $account->fees_due_to_date - (int) $account->fees_paid);
    }
}
