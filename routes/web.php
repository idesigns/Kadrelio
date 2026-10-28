<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\MondayController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WorkflowController;
use App\Http\Middleware\PrivateResponse;
use Illuminate\Support\Facades\Route;

Route::middleware(PrivateResponse::class)->group(function (): void {
    Route::get('/', LandingController::class)->name('home');
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [AuthController::class, 'login'])->name('login');
        Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:login');
        Route::get('/register', [AuthController::class, 'register'])->name('register');
        Route::post('/register', [AuthController::class, 'store'])->middleware('throttle:6,1');
    });
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
    Route::get('/f/dziekujemy', [PublicFormController::class, 'success'])->name('forms.success');
    Route::get('/f/{company:public_token}/profile', [PublicFormController::class, 'profile'])->name('forms.profile');
    Route::post('/f/{company:public_token}/profile', [PublicFormController::class, 'storeProfile'])->middleware('throttle:10,1');
    Route::get('/f/{company:public_token}/{kind}', [PublicFormController::class, 'additional'])->whereIn('kind', ['data_change', 'multisport'])->name('forms.additional');
    Route::post('/f/{company:public_token}/{kind}', [PublicFormController::class, 'storeAdditional'])->whereIn('kind', ['data_change', 'multisport'])->middleware('throttle:10,1');
    Route::get('/confirm/{record:signing_token}', [PublicFormController::class, 'confirmation'])->middleware('throttle:10,1')->name('forms.confirmation');
    Route::post('/confirm/{record:signing_token}', [PublicFormController::class, 'confirm'])->middleware('throttle:10,1');
    Route::get('/onboarding/{employee:form_token}', [PublicFormController::class, 'onboarding'])->name('onboarding.show');
    Route::post('/onboarding/{employee:form_token}', [PublicFormController::class, 'completeOnboarding'])->middleware('throttle:10,1');
    Route::get('/s/{record:signing_token}', [PublicFormController::class, 'signing'])->name('signing.show');
    Route::post('/s/{record:signing_token}', [PublicFormController::class, 'sign'])->middleware('throttle:10,1');
    Route::get('/s/{record:signing_token}/dokument', [DocumentController::class, 'public'])->middleware('throttle:10,1')->name('signing.document');
    Route::get('/invite/{invitation:token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invite/{invitation:token}', [InvitationController::class, 'accept'])->middleware('throttle:6,1');

    Route::middleware('auth')->group(function (): void {
        Route::get('/app', [BoardController::class, 'home'])->name('dashboard');
        Route::prefix('/monday')->name('monday.')->group(function (): void {
            Route::get('/', [MondayController::class, 'index'])->name('index');
            Route::prefix('/boards/{mondayBoard}')->scopeBindings()->group(function (): void {
                Route::get('/', [MondayController::class, 'board'])->name('boards.show');
                Route::get('/source', [MondayController::class, 'workbook'])->name('boards.source');
                Route::get('/export', [MondayController::class, 'export'])->name('boards.export');
                Route::get('/items/{item}', [MondayController::class, 'show'])->name('items.show');
                Route::patch('/items/{item}', [MondayController::class, 'update'])->name('items.update');
                Route::post('/items/{item}/relation', [MondayController::class, 'relation'])->name('items.relation');
                Route::post('/items/{item}/profile', [MondayController::class, 'profile'])->name('items.profile');
                Route::post('/items/{item}/review', [MondayController::class, 'review'])->name('items.review');
                Route::get('/items/{item}/assets/{asset}', [MondayController::class, 'asset'])->name('items.asset');
            });
        });
        Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::prefix('/companies/{company}')->name('companies.')->scopeBindings()->group(function (): void {
            Route::get('/settings', [CompanyController::class, 'edit'])->name('edit');
            Route::get('/forms/profile/preview', [PublicFormController::class, 'preview'])->name('profile-preview');
            Route::patch('/settings', [CompanyController::class, 'update'])->name('update');
            Route::get('/members', [InvitationController::class, 'index'])->name('members.index');
            Route::post('/members', [InvitationController::class, 'store'])->middleware('throttle:10,1')->name('members.store');
            Route::get('/boards/{board}', [BoardController::class, 'index'])->name('board');
            Route::get('/records/new/{kind}', [WorkflowController::class, 'create'])->whereIn('kind', ['contract', 'amendment', 'termination'])->name('records.create');
            Route::post('/records/new/{kind}', [WorkflowController::class, 'store'])->whereIn('kind', ['contract', 'amendment', 'termination'])->middleware('throttle:20,1')->name('records.store');
            Route::get('/records/{record}', [WorkflowController::class, 'show'])->name('records.show');
            Route::patch('/records/{record}', [WorkflowController::class, 'update'])->name('records.update');
            Route::post('/records/{record}/generate', [WorkflowController::class, 'generate'])->name('records.generate');
            Route::post('/records/{record}/send', [WorkflowController::class, 'send'])->middleware('throttle:5,1')->name('records.send');
            Route::get('/records/{record}/document', [DocumentController::class, 'show'])->middleware('throttle:20,1')->name('records.document');
            Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
            Route::get('/employees/{employee}/files/{file}', [EmployeeController::class, 'file'])->whereUuid('file')->name('employees.files');
            Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
            Route::post('/employees/{employee}/invite', [EmployeeController::class, 'invite'])->middleware('throttle:5,1')->name('employees.invite');
            Route::get('/templates', [TemplateController::class, 'index'])->name('templates.index');
            Route::get('/templates/create', [TemplateController::class, 'create'])->name('templates.create');
            Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
            Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
            Route::patch('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
            Route::post('/templates/{template}/preview', [TemplateController::class, 'preview'])->middleware('throttle:10,1')->name('templates.preview');
            Route::get('/templates/{template}/preview.pdf', [TemplateController::class, 'previewFile'])->name('templates.preview-file');
            Route::get('/templates/{template}/source', [TemplateController::class, 'source'])->name('templates.source');
            Route::post('/templates/{template}/activate', [TemplateController::class, 'activate'])->name('templates.activate');
            Route::post('/templates/{template}/archive', [TemplateController::class, 'archive'])->name('templates.archive');
        });
        Route::get('/admin/users', [AdminController::class, 'index'])->name('admin.users');
        Route::patch('/admin/users/{user}', [AdminController::class, 'update'])->name('admin.users.update');
    });
});
