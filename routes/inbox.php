<?php

use Bizzsol\ApprovalMatrix\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;

// Approver inbox: every route is further restricted to the assigned approver inside the engine.
// Loaded by the admin UI (erp-main) and, on its own, by apps that only host the inbox (config approvalmatrix.inbox_ui).
Route::group(['prefix' => 'approval-matrix/inbox', 'as' => 'approval-matrix.inbox.', 'middleware' => ['auth', 'permission:approval-inbox']], function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');
    Route::get('{id}', [InboxController::class, 'show'])->name('show');
    Route::post('{id}/approve', [InboxController::class, 'approve'])->name('approve');
    Route::post('{id}/reject', [InboxController::class, 'reject'])->name('reject');
    Route::post('{id}/recall', [InboxController::class, 'recall'])->name('recall');
});
