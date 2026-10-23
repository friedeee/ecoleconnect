<?php

namespace Database\Seeders;

use App\Enums\AssessmentKind;
use App\Enums\AttendanceStatus;
use App\Enums\ClassLevel;
use App\Enums\Cycle;
use App\Enums\DaySession;
use App\Enums\GuardianRelationship;
use App\Enums\PaymentMethod;
use App\Enums\Section;
use App\Models\AcademicYear;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeeItem;
use App\Models\GradingPeriod;
use App\Models\Guardian;
use App\Models\Homework;
use App\Models\Payment;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A realistic demo school, used for local development and sales demos.
 */
class DemoSchoolSeeder extends Seeder
{
    /**
     * Classes of the demo school, from nursery to the final year: name => level.
     *
     * @var array<string, ClassLevel>
     */
    protected const CLASSES = [
        'Maternelle 1' => ClassLevel::Nursery1,
        'Maternelle 2' => ClassLevel::Nursery2,
        'CI' => ClassLevel::Ci,
        'CP' => ClassLevel::Cp,
        'CE1' => ClassLevel::Ce1,
        'CE2' => ClassLevel::Ce2,
        'CM1' => ClassLevel::Cm1,
        'CM2' => ClassLevel::Cm2,
        '6e A' => ClassLevel::Sixth,
        '6e B' => ClassLevel::Sixth,
        '5e A' => ClassLevel::Fifth,
        '4e A' => ClassLevel::Fourth,
        '3e A' => ClassLevel::Third,
        '2nde C' => ClassLevel::Second,
        '1ère D' => ClassLevel::First,
        'Tle D' => ClassLevel::Final,
    ];

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The platform operator, who opens and suspends client schools.
        User::factory()->superAdmin()->create(['name' => 'Opérateur ÉcoleConnect', 'email' => 'super@ecoleconnect.bj']);

        $school = School::factory()->create([
            'name' => 'Complexe Scolaire Les Lauriers',
            'slug' => 'les-lauriers',
            'city' => 'Cotonou',
            'address' => 'Quartier Fidjrossè, rue 12.045',
            'phone' => '+229 01 97 00 00 00',
            'email' => 'contact@leslauriers.bj',
            'motto' => 'Travail – Discipline – Succès',
        ]);

        User::factory()->admin()->for($school)->create(['name' => 'Mme Adjoa HOUNKPATIN', 'email' => 'admin@demo.bj']);
        $accountant = User::factory()->accountant()->for($school)->create(['name' => 'M. Rodrigue AGBO', 'email' => 'comptable@demo.bj']);
        User::factory()->admin()->for($school)->inSection(Section::Basic)->create(['name' => 'M. Firmin DOSSA', 'email' => 'primaire@demo.bj']);
        User::factory()->admin()->for($school)->inSection(Section::Secondary)->create(['name' => 'Mme Clarisse SOGLO', 'email' => 'secondaire@demo.bj']);
        $secondaryTeachers = User::factory()->for($school)->inSection(Section::Secondary)->count(7)->create();
        $secondaryTeachers->first()->update(['email' => 'prof@demo.bj']);

        $year = AcademicYear::factory()->for($school)->create();

        $subjectsByCycle = collect(Cycle::cases())->mapWithKeys(fn (Cycle $cycle) => [
            $cycle->value => collect($cycle->standardSubjects())->map(fn (array $subject, string $code) => Subject::factory()->for($school)->create([
                'code' => $code,
                'name' => $subject[0],
                'cycle' => $cycle,
            ])),
        ]);

        $secondaryClassIndex = 0;

        foreach (self::CLASSES as $name => $level) {
            $cycle = $level->cycle();

            $headTeacher = $cycle->hasSingleTeacher()
                ? User::factory()->for($school)->inSection($cycle->section())->create()
                : $secondaryTeachers[$secondaryClassIndex++ % $secondaryTeachers->count()];

            $class = SchoolClass::factory()->for($school)->for($year)->create([
                'name' => $name,
                'level' => $level,
                'head_teacher_id' => $headTeacher->id,
            ]);

            $this->attachSubjects($class, $subjectsByCycle[$cycle->value], $cycle->hasSingleTeacher() ? collect([$headTeacher]) : $secondaryTeachers);

            $this->enrollStudents($school, $year, $class, $cycle === Cycle::Nursery ? fake()->numberBetween(15, 20) : fake()->numberBetween(22, 30));
        }

        $this->seedFees($year, $accountant);
        $this->seedGrades($year);
        $this->seedAttendance($year);
        $this->seedTimetable($year);
        $this->seedHomework($year);
        $this->seedDemoParent($year);

        // A second school proves that schools never see each other's data.
        $otherSchool = School::factory()->teaching(Cycle::Nursery, Cycle::Primary)->create(['name' => 'École Primaire La Colombe', 'city' => 'Parakou']);
        User::factory()->admin()->for($otherSchool)->create(['email' => 'admin@colombe.bj']);
        AcademicYear::factory()->for($otherSchool)->create();
    }

    /**
     * A parent of two children (CM1 and 4e A) who signs in with 01 97 00 00 01 / password.
     */
    protected function seedDemoParent(AcademicYear $year): void
    {
        $guardian = Guardian::factory()->create([
            'school_id' => $year->school_id, 'first_name' => 'Bertin', 'last_name' => 'Houngbo', 'phone' => '+2290197000001', 'profession' => 'Commerçant',
        ]);

        foreach (['CM1', '4e A'] as $className) {
            $student = SchoolClass::withoutGlobalScopes()->whereBelongsTo($year)->where('name', $className)->firstOrFail()->students()->orderBy('id')->firstOrFail();
            $student->guardians()->updateExistingPivot($student->guardians()->pluck('guardians.id')->all(), ['is_primary_contact' => false]);
            $student->guardians()->attach($guardian->id, ['relationship' => GuardianRelationship::Father->value, 'is_primary_contact' => true]);
        }

        $guardian->openPortalAccount();
        $guardian->user->forceFill(['password' => 'password', 'must_change_password' => false])->save();
    }

    /**
     * Two or three recent exercises per class.
     */
    protected function seedHomework(AcademicYear $year): void
    {
        $titles = ['Exercices 1 à 5 page 34', 'Apprendre la leçon et faire le résumé', 'Problèmes 3 et 4 de la fiche', 'Lire le texte et répondre aux questions', 'Révision pour l\'interrogation'];

        foreach (SchoolClass::withoutGlobalScopes()->whereBelongsTo($year)->with('subjects')->get() as $class) {
            foreach ($class->subjects->random(min(3, $class->subjects->count())) as $subject) {
                $givenOn = today()->subDays(fake()->numberBetween(0, 6));
                $homework = new Homework([
                    'school_class_id' => $class->id,
                    'subject_id' => $subject->id,
                    'title' => fake()->randomElement($titles),
                    'instructions' => fake()->boolean(60) ? 'À faire dans le cahier d\'exercices, proprement.' : null,
                    'given_on' => $givenOn,
                    'due_on' => $givenOn->copy()->addDays(fake()->numberBetween(2, 7)),
                ]);
                $homework->forceFill(['school_id' => $year->school_id, 'created_by' => $subject->pivot->teacher_id])->save();
            }
        }
    }

    /**
     * Morning and afternoon roll calls of the last two weeks, with a few absent or late students.
     */
    protected function seedAttendance(AcademicYear $year): void
    {
        $days = collect(range(1, 14))
            ->map(fn (int $daysAgo) => today()->subDays($daysAgo))
            ->filter(fn ($day) => $day->isWeekday() && $day->gte($year->starts_on));

        foreach (SchoolClass::withoutGlobalScopes()->whereBelongsTo($year)->with('students')->get() as $class) {
            $teacherId = $class->head_teacher_id;
            $rollCalls = [];
            $absences = [];

            foreach ($days as $day) {
                foreach (DaySession::cases() as $session) {
                    $rollCalls[] = ['school_id' => $year->school_id, 'school_class_id' => $class->id, 'date' => $day->toDateString(), 'session' => $session->value, 'taken_by' => $teacherId, 'created_at' => now(), 'updated_at' => now()];

                    foreach ($class->students as $student) {
                        $status = match (true) {
                            fake()->boolean(4) => AttendanceStatus::Absent,
                            fake()->boolean(2) => AttendanceStatus::Late,
                            default => null,
                        };

                        if ($status !== null) {
                            $justified = $status === AttendanceStatus::Absent && fake()->boolean(40);
                            $absences[] = [
                                'school_id' => $year->school_id, 'student_id' => $student->id, 'school_class_id' => $class->id,
                                'date' => $day->toDateString(), 'session' => $session->value, 'status' => $status->value,
                                'is_justified' => $justified, 'reason' => $justified ? fake()->randomElement(['Maladie', 'Paludisme', 'Rendez-vous médical', 'Deuil familial']) : null,
                                'created_at' => now(), 'updated_at' => now(),
                            ];
                        }
                    }
                }
            }

            DB::table('roll_calls')->insert($rollCalls);
            DB::table('absences')->insert($absences);
        }
    }

    /**
     * A week of one-hour lessons per class, never giving a teacher two classes at once.
     */
    protected function seedTimetable(AcademicYear $year): void
    {
        $busyTeachers = [];

        foreach (SchoolClass::withoutGlobalScopes()->whereBelongsTo($year)->with('subjects')->get() as $class) {
            $secondary = $class->cycle === Cycle::Secondary;
            $hours = $secondary ? [7, 8, 9, 10, 11, 15, 16, 17] : [8, 9, 10, 11, 15, 16];
            $slots = collect(range(1, 5))->crossJoin($hours)->shuffle()->all();
            $busyClass = [];
            $entries = [];

            foreach ($class->subjects as $subject) {
                $weekly = $secondary ? [3 => 4, 2 => 3, 1 => 2][$subject->pivot->coefficient] ?? 2 : 3;
                $teacherId = $subject->pivot->teacher_id;

                foreach ($slots as [$day, $hour]) {
                    if ($weekly === 0) {
                        break;
                    }

                    if (isset($busyClass["{$day}-{$hour}"]) || ($teacherId && isset($busyTeachers["{$teacherId}-{$day}-{$hour}"]))) {
                        continue;
                    }

                    $busyClass["{$day}-{$hour}"] = true;
                    $busyTeachers["{$teacherId}-{$day}-{$hour}"] = true;
                    $weekly--;
                    $entries[] = [
                        'school_id' => $year->school_id, 'school_class_id' => $class->id, 'subject_id' => $subject->id, 'teacher_id' => $teacherId,
                        'day' => $day, 'starts_at' => sprintf('%02d:00', $hour), 'ends_at' => sprintf('%02d:00', $hour + 1),
                        'room' => 'Salle '.$class->id, 'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }

            DB::table('timetable_entries')->insert($entries);
        }
    }

    /**
     * Marks of the first period: two interrogations and a devoir per subject in the secondary
     * cycle, two evaluations out of 10 elsewhere. Each student keeps a level of their own.
     */
    protected function seedGrades(AcademicYear $year): void
    {
        $period = GradingPeriod::ensureFor($year)->first();
        $abilities = [];

        foreach (SchoolClass::withoutGlobalScopes()->whereBelongsTo($year)->with(['subjects', 'students'])->get() as $class) {
            $plan = $class->cycle === Cycle::Secondary
                ? [[AssessmentKind::Quiz, 20, 25], [AssessmentKind::Quiz, 20, 40], [AssessmentKind::Test, 20, 50]]
                : [[AssessmentKind::Evaluation, 10, 30], [AssessmentKind::Evaluation, 10, 50]];

            foreach ($class->subjects as $subject) {
                foreach ($plan as [$kind, $outOf, $day]) {
                    $assessment = new Assessment([
                        'grading_period_id' => $period->id,
                        'school_class_id' => $class->id,
                        'subject_id' => $subject->id,
                        'kind' => $kind,
                        'held_on' => $period->starts_on->copy()->addDays($day + fake()->numberBetween(0, 4)),
                        'out_of' => $outOf,
                    ]);
                    $assessment->forceFill(['school_id' => $year->school_id, 'created_by' => $subject->pivot->teacher_id])->save();

                    DB::table('marks')->insert($class->students->map(function (Student $student) use ($assessment, $outOf, &$abilities, $year) {
                        $abilities[$student->id] ??= fake()->numberBetween(70, 170) / 10;
                        $absent = fake()->boolean(3);
                        $score = max(0, min(20, $abilities[$student->id] + fake()->numberBetween(-40, 40) / 10));

                        return [
                            'school_id' => $year->school_id,
                            'assessment_id' => $assessment->id,
                            'student_id' => $student->id,
                            'score' => $absent ? null : round($score * $outOf / 20 * 2) / 2,
                            'is_absent' => $absent,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    })->all());
                }
            }
        }
    }

    /**
     * A fee schedule per level, paid in three instalments, and the payments families made since the start of the year.
     */
    protected function seedFees(AcademicYear $year, User $accountant): void
    {
        // Registration and three instalments per group of levels, in F CFA, plus an optional fee.
        $schedules = [
            [[ClassLevel::Nursery1, ClassLevel::Nursery2], [10000, 25000, 20000, 15000], ['Cantine', 30000]],
            [[ClassLevel::Ci, ClassLevel::Cp, ClassLevel::Ce1, ClassLevel::Ce2, ClassLevel::Cm1, ClassLevel::Cm2], [15000, 35000, 30000, 25000], ['Tenue scolaire', 8000]],
            [[ClassLevel::Sixth, ClassLevel::Fifth, ClassLevel::Fourth, ClassLevel::Third], [20000, 50000, 40000, 35000], ['Tenue scolaire', 10000]],
            [[ClassLevel::Second, ClassLevel::First, ClassLevel::Final], [25000, 60000, 50000, 40000], null],
        ];
        $startYear = (int) $year->starts_on->format('Y');
        $dueDates = [null, $startYear.'-10-15', ($startYear + 1).'-01-15', ($startYear + 1).'-04-15'];
        $labels = ['Inscription', '1re tranche', '2e tranche', '3e tranche'];

        foreach ($schedules as [$levels, $amounts, $optional]) {
            foreach ($levels as $level) {
                foreach ($amounts as $index => $amount) {
                    FeeItem::factory()->for($year)->create([
                        'school_id' => $year->school_id, 'level' => $level, 'label' => $labels[$index], 'amount' => $amount, 'due_on' => $dueDates[$index],
                    ]);
                }

                if ($optional !== null) {
                    FeeItem::factory()->for($year)->optional()->create([
                        'school_id' => $year->school_id, 'level' => $level, 'label' => $optional[0], 'amount' => $optional[1],
                    ]);
                }
            }
        }

        $payDays = max(0, (int) $year->starts_on->diffInDays(today()));
        $plannedPayments = [];

        foreach (Enrollment::withoutGlobalScopes()->whereBelongsTo($year)->with(['academicYear', 'schoolClass'])->get() as $enrollment) {
            $owed = (int) FeeItem::withoutGlobalScopes()->whereBelongsTo($year)->where('level', $enrollment->schoolClass->level)
                ->where('is_optional', false)->where(fn ($query) => $query->whereNull('due_on')->orWhere('due_on', '<=', today()))
                ->sum('amount');

            // Most families are up to date, some paid part of it, a few only the registration.
            $toPay = match (true) {
                fake()->boolean(60) => $owed,
                fake()->boolean(60) => (int) (round($owed * fake()->randomFloat(2, 0.3, 0.9) / 5000) * 5000),
                default => min($owed, 15000),
            };

            foreach ($toPay > 20000 && fake()->boolean() ? [intdiv($toPay, 2), $toPay - intdiv($toPay, 2)] : [$toPay] as $amount) {
                if ($amount <= 0) {
                    continue;
                }

                $method = fake()->randomElement([PaymentMethod::Cash, PaymentMethod::Cash, PaymentMethod::MobileMoney]);
                $plannedPayments[] = [$enrollment, [
                    'amount' => $amount,
                    'paid_on' => $year->starts_on->copy()->addDays(fake()->numberBetween(0, $payDays))->format('Y-m-d'),
                    'method' => $method,
                    'reference' => $method === PaymentMethod::MobileMoney ? 'MTN-'.fake()->numerify('########') : null,
                ]];
            }
        }

        // Receipt numbers follow the order in which the money came in.
        usort($plannedPayments, fn (array $a, array $b) => $a[1]['paid_on'] <=> $b[1]['paid_on']);

        foreach ($plannedPayments as [$enrollment, $attributes]) {
            Payment::record($enrollment, $attributes, $accountant);
        }
    }

    /**
     * Attach the given subjects to the class, spreading them over the given teachers.
     *
     * @param  Collection<string, Subject>  $subjects
     * @param  Collection<int, User>  $teachers
     */
    protected function attachSubjects(SchoolClass $class, Collection $subjects, Collection $teachers): void
    {
        $class->subjects()->attach($subjects->values()->mapWithKeys(fn (Subject $subject, int $position) => [
            $subject->id => [
                'coefficient' => $subject->cycle->standardSubjects()[$subject->code][1],
                'teacher_id' => $teachers[$position % $teachers->count()]->id,
            ],
        ]));
    }

    protected function enrollStudents(School $school, AcademicYear $year, SchoolClass $class, int $count): void
    {
        $siblingsGuardian = null;

        for ($i = 0; $i < $count; $i++) {
            $isRepeating = fake()->boolean(10);
            $age = $class->level->typicalAge() + ($isRepeating ? 1 : 0);

            $student = Student::factory()->for($school)->create([
                'registration_number' => sprintf('%s-%04d', $year->starts_on->format('Y'), Student::withoutGlobalScopes()->where('school_id', $school->id)->count() + 1),
                'birth_date' => fake()->dateTimeBetween($year->starts_on->copy()->subYears($age + 1), $year->starts_on->copy()->subYears($age))->format('Y-m-d'),
            ]);

            Enrollment::factory()->create([
                'school_id' => $school->id,
                'student_id' => $student->id,
                'academic_year_id' => $year->id,
                'school_class_id' => $class->id,
                'is_repeating' => $isRepeating,
            ]);

            // Roughly one family in six has a sibling already enrolled.
            $guardian = $siblingsGuardian !== null && fake()->boolean(15)
                ? $siblingsGuardian
                : Guardian::factory()->for($school)->create(['last_name' => $student->last_name]);

            $student->guardians()->attach($guardian->id, [
                'relationship' => fake()->randomElement([GuardianRelationship::Father, GuardianRelationship::Mother])->value,
                'is_primary_contact' => true,
            ]);

            $siblingsGuardian = $guardian;
        }
    }
}
