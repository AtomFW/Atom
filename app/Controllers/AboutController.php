<?php

namespace App\Controllers;

use Atom\Controller;

class AboutController extends Controller
{
    public function index()
    {
        return $this->render('about');
    }
}
