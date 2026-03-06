<?php

Route::group(['middleware' => ['web']], function () {
    Route::get('bkash/callback', [
        'as' => 'bkash.callback',
        'uses' => 'AtiqurSafayat\Bkash\Http\Controllers\BkashController@callback',
    ]);
});
