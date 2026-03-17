<?php

Route::group(['middleware' => ['web', 'auth']], function () {
    Route::post('/hexaweb/ai/compose',          'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@compose')
        ->name('hexaweb.ai.compose');
    Route::post('/hexaweb/ai/template',         'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@template')
        ->name('hexaweb.ai.template');
    Route::post('/hexaweb/ai/polish',           'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@polish')
        ->name('hexaweb.ai.polish');
    Route::get('/hexaweb/ai/templates',         'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@templates')
        ->name('hexaweb.ai.templates');
    Route::post('/hexaweb/ai/templates/save',   'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@templateSave')
        ->name('hexaweb.ai.templates.save');
    Route::post('/hexaweb/ai/templates/delete', 'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@templateDelete')
        ->name('hexaweb.ai.templates.delete');
    Route::get('/hexaweb/ai/logs',              'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@logs')
        ->name('hexaweb.ai.logs');
    Route::post('/hexaweb/ai/logs/clear',       'Modules\HexawebAIAssist\Http\Controllers\AIAssistController@logsClear')
        ->name('hexaweb.ai.logs.clear');
});
