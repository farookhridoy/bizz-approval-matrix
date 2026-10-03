<?php

use Illuminate\Support\Facades\Route;
use Bizzsol\ApprovalMatrix\Http\Controllers\InboxController;
use Bizzsol\ApprovalMatrix\Http\Controllers\OrgController;
use Bizzsol\ApprovalMatrix\Http\Controllers\SimulatorController;
use Bizzsol\ApprovalMatrix\Http\Controllers\WorkflowController;

Route::group(['prefix' => 'approval-matrix', 'as' => 'approval-matrix.', 'middleware' => 'auth'], function () {
    // Workflow builder (ACL > Approval Matrix)
    Route::resource('workflows', WorkflowController::class)->except(['show'])
        ->middleware('permission:approval-matrix-index|approval-matrix-create|approval-matrix-edit|approval-matrix-delete');
    Route::post('workflows/{id}/activate', [WorkflowController::class, 'activate'])->name('workflows.activate')
        ->middleware('permission:approval-matrix-edit');
    Route::post('workflows/{id}/archive', [WorkflowController::class, 'archive'])->name('workflows.archive')
        ->middleware('permission:approval-matrix-edit');

    // Cascade feeds: Company > Unit > Master department (shared by builder, custom rows and simulator)
    Route::get('org/units', [OrgController::class, 'units'])->name('org.units')
        ->middleware('permission:approval-matrix-index|approval-matrix-create|approval-matrix-edit|approval-matrix-simulator');
    Route::get('org/master-departments', [OrgController::class, 'masterDepartments'])->name('org.master-departments')
        ->middleware('permission:approval-matrix-index|approval-matrix-create|approval-matrix-edit|approval-matrix-simulator');

    // "Who would approve this?"
    Route::get('simulator', [SimulatorController::class, 'index'])->name('simulator.index')
        ->middleware('permission:approval-matrix-simulator');

    // Approver inbox: every route is further restricted to the assigned approver inside the engine.
    Route::group(['prefix' => 'inbox', 'as' => 'inbox.', 'middleware' => 'permission:approval-inbox'], function () {
        Route::get('/', [InboxController::class, 'index'])->name('index');
        Route::get('{id}', [InboxController::class, 'show'])->name('show');
        Route::post('{id}/approve', [InboxController::class, 'approve'])->name('approve');
        Route::post('{id}/reject', [InboxController::class, 'reject'])->name('reject');
        Route::post('{id}/recall', [InboxController::class, 'recall'])->name('recall');
    });
});
