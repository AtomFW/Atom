<?php

namespace Atom\controllers;

use Atom\core\Controller;

class AboutController extends Controller
{
    public function index()
    {
        return $this->render('about');
    }
}
