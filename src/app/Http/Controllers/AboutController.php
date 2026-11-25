<?php

namespace App\Http\Controllers;

use App\Support\Modules;
use Illuminate\View\View;

/** About nxtVM (user menu): what the product is, version, edition and how to reach support. */
class AboutController extends Controller
{
    public function __invoke(Modules $modules): View
    {
        return view('about', ['modules' => $modules]);
    }
}
