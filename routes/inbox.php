<?php

use Bizzsol\ApprovalMatrix\Http\Controllers\DelegationController;
use Bizzsol\ApprovalMatrix\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;

// Approver inbox: every route is further restricted to the assigned approver inside the engine.
// Loaded by the admin UI (erp-main) and, on its own, by apps that only host the inbox (config approvalmatrix.inbox_ui).
Route::group(['prefix' => 'approval-matrix/inbox', 'as' => 'approval-matrix.inbox.', 'middleware' => ['auth', 'permission:approval-inbox']], function () {
    Route::get('/', [InboxController::class, 'index'])->name('index');

    // out-of-office delegation (defined before {id} so 'delegations' is not read as a request id)
    Route::get('delegations', [DelegationController::class, 'index'])->name('delegations.index');
    Route::post('delegations', [DelegationController::class, 'store'])->name('delegations.store');
    Route::delete('delegations/{id}', [DelegationController::class, 'destroy'])->name('delegations.destroy');

    Route::get('{id}', [InboxController::class, 'show'])->name('show');
    Route::post('{id}/approve', [InboxController::class, 'approve'])->name('approve');
    Route::post('{id}/reject', [InboxController::class, 'reject'])->name('reject');
    Route::post('{id}/recall', [InboxController::class, 'recall'])->name('recall');
});
