<?php

use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

Route::post('patients', [PatientController::class, 'store']);
Route::get('patients/{patient}', [PatientController::class, 'show'])->whereNumber('patient');
Route::get('patients/{patient}/orders', [OrderController::class, 'patientOrders'])->whereNumber('patient');
Route::post('departments', [DepartmentController::class, 'store']);
Route::get('departments', [DepartmentController::class, 'index']);
Route::patch('departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department');
Route::post('services', [ServiceController::class, 'store']);
Route::get('services', [ServiceController::class, 'index']);
Route::patch('services/{service}', [ServiceController::class, 'update'])->whereNumber('service');
Route::post('orders', [OrderController::class, 'store']);
Route::get('orders', [OrderController::class, 'index']);
Route::post('orders/{order}/pay', [PaymentController::class, 'store'])->whereNumber('order');
