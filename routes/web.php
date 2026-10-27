<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\HomeworkFileController;
use App\Http\Controllers\ParentDocumentController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\ReportCardController;
use App\Http\Controllers\SwitchSectionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

Route::middleware('guest')->group(function () {
    Route::livewire('/connexion', 'pages::auth.login')->name('login');
});

Route::post('/deconnexion', LogoutController::class)->middleware('auth')->name('logout');
Route::livewire('/mot-de-passe', 'pages::auth.password')->middleware('auth')->name('password.edit');
Route::livewire('/profil', 'pages::profile')->middleware(['auth', 'password.fresh'])->name('profile');

Route::middleware(['auth', 'password.fresh', 'parent'])->prefix('parent')->name('parent.')->group(function () {
    Route::livewire('/', 'pages::parent.home')->name('home');
    Route::livewire('/enfants/{student}', 'pages::parent.child')->name('child');
    Route::get('/recus/{payment}', [ParentDocumentController::class, 'receipt'])->name('receipt');
    Route::get('/bulletins/{student}/{gradingPeriod}', [ParentDocumentController::class, 'reportCard'])->name('report-card');
    Route::get('/exercices/{homework}/fichier', [ParentDocumentController::class, 'homeworkFile'])->name('homework-file');
});

Route::middleware(['auth', 'password.fresh', 'platform'])->prefix('plateforme')->name('platform.')->group(function () {
    Route::livewire('/ecoles', 'pages::platform.schools.index')->name('schools.index');
    Route::livewire('/ecoles/nouvelle', 'pages::platform.schools.create')->name('schools.create');
});

Route::middleware(['auth', 'password.fresh', 'school'])->group(function () {
    Route::livewire('/tableau-de-bord', 'pages::dashboard')->name('dashboard');
    Route::post('/section', SwitchSectionController::class)->name('section.switch');

    Route::middleware('can:view-students')->group(function () {
        Route::livewire('/eleves', 'pages::students.index')->name('students.index');
        Route::livewire('/eleves/nouveau', 'pages::students.form')->middleware('can:manage-students')->name('students.create');
        Route::livewire('/eleves/{student}', 'pages::students.show')->name('students.show');
        Route::livewire('/eleves/{student}/modifier', 'pages::students.form')->middleware('can:manage-students')->name('students.edit');

        Route::livewire('/classes', 'pages::classes.index')->name('classes.index');
        Route::livewire('/classes/{schoolClass}', 'pages::classes.show')->name('classes.show');
    });

    Route::middleware('can:view-grades')->group(function () {
        Route::livewire('/notes', 'pages::grades.index')->name('grades.index');
        Route::livewire('/exercices', 'pages::homework')->name('homework');
        Route::get('/exercices/{homework}/fichier', HomeworkFileController::class)->name('homework.file');
        Route::livewire('/notes/{schoolClass}/{subject}', 'pages::grades.sheet')->name('grades.sheet');

        Route::middleware('can:manage-report-cards')->group(function () {
            Route::livewire('/bulletins', 'pages::grades.reports')->name('grades.reports');
            Route::get('/bulletins/{schoolClass}/{gradingPeriod}', [ReportCardController::class, 'show'])->name('grades.report-cards');
            Route::get('/bulletins/{schoolClass}/{gradingPeriod}/pdf', [ReportCardController::class, 'download'])->name('grades.report-cards.pdf');
        });
    });

    Route::middleware('can:view-attendance')->group(function () {
        Route::livewire('/absences', 'pages::attendance.index')->name('attendance.index');
        Route::livewire('/absences/appel/{schoolClass}', 'pages::attendance.roll-call')->name('attendance.roll-call');
    });

    Route::livewire('/emploi-du-temps', 'pages::timetable')->middleware('can:view-timetable')->name('timetable');

    Route::middleware('can:view-fees')->group(function () {
        Route::livewire('/frais', 'pages::fees.index')->name('fees.index');
        Route::livewire('/frais/bareme', 'pages::fees.schedule')->middleware('can:manage-fee-schedule')->name('fees.schedule');
        Route::get('/recus/{payment}', [ReceiptController::class, 'show'])->name('fees.receipt');
        Route::get('/recus/{payment}/pdf', [ReceiptController::class, 'download'])->name('fees.receipt.pdf');
    });

    Route::middleware('can:manage-school')->group(function () {
        Route::livewire('/matieres', 'pages::subjects.index')->name('subjects.index');
        Route::livewire('/personnel', 'pages::staff.index')->name('staff.index');
        Route::livewire('/parametres', 'pages::settings')->middleware('can:manage-whole-school')->name('settings');
        Route::livewire('/journal', 'pages::activity')->middleware('can:manage-whole-school')->name('activity');
    });
});
