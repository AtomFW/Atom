<?php

namespace App\controllers;

use Atom\Atom;
use Atom\Controller;
use Atom\middlewares\AuthMiddleware;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use App\models\LoginForm;
use App\models\User;

class SiteController extends Controller
{
    public function __construct()
    {
        $this->registerMiddleware(new AuthMiddleware(['profile']));
    }

    public function home()
    {
        return $this->render('home', [
            'name' => 'The Atom!',
            'title' => "The Atom Start!"
        ]);
    }

    public function login(Request $request)
    {
        echo '<pre>';
        var_dump($request->getBody(), $request->getRouteParam('id'));
        echo '</pre>';
        $loginForm = new LoginForm();
        if ($request->getMethod() === 'post') {
            $loginForm->loadData($request->getBody());
            if ($loginForm->validate() && $loginForm->login()) {
                Atom::$app->response->redirect('/newatom/public/');
                return;
            }
        }
        $this->setLayout('auth');
        return $this->render('login', [
            'model' => $loginForm
        ]);
    }

    public function register(Request $request)
    {
        $registerModel = new User();
        if ($request->getMethod() === 'post') {
            $registerModel->loadData($request->getBody());
            if ($registerModel->validate() && $registerModel->save()) {
                Atom::$app->session->setFlash('success', 'Thanks for registering');
                Atom::$app->response->redirect('/Atom/public/');
                return 'Show success page';
            }
        }
        $this->setLayout('auth');
        return $this->render('register', [
            'model' => $registerModel
        ]);
    }

    public function logout(Request $request, Response $response)
    {
        Atom::$app->account->logout();
        $response->redirect('/');
    }

    public function contact()
    {
        return $this->render('contact');
    }

    public function profile()
    {
        return $this->render('profile');
    }

    public function profileWithId(Request $request)
    {
        echo '<pre>';
        var_dump($request->getBody());
        echo '</pre>';
    }
}
