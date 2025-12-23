<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return  'welcome';
});

Route::get( '/about', function () {
    return 'about page';
});

route('/contact', function () {
    return 'contact page';
}); 