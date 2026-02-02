<?php

namespace App\controllers;

use Atom\Controller;

class AboutController extends Controller
{
    public function index()
    {
        return $this->render('about');
    }
}
