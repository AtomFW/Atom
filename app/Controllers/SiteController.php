<?php

namespace App\Controllers;

use Atom\Atom;
use Atom\Component\WebPush\WebPushAdapter;
use Atom\Controller;
use Atom\Middlewares\AuthMiddleware;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use App\Models\LoginForm;
use App\Models\User;

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
                Atom::$app->response->redirect(Atom::$ROOT_URI);
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
                Atom::$app->response->redirect(Atom::$ROOT_URI);
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
        $response->redirect(Atom::$ROOT_URI);
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

    public function uploads(Request $request) {
        return $this->render('upload');
    }
    
    public function uploadsMulti(Request $request) {
        return $this->render('uploadMulti');
    }

    public function upload(Request $request)
    {
        $uploader = new \Atom\FileSytem\BrowserUploadManager([
            'upload_dir' => __DIR__ . '/../../runtime/tempUpload/',
            'temp_dir' => __DIR__ . '/../../runtime/temp/',
            'max_file_size' => 20 * 1024 * 1024,
            'max_total_size' => 50 * 1024 * 1024,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf', 'zip'],
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf', 'application/zip'],
            'allow_multiple' => true,
            'max_files' => 5,
        ]);

        $result = $uploader->uploadMultipleFromField('file', [
            'title' => $_POST['title'] ?? 'No Title',
        ]);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    public function uploadMulti(Request $request)
    {
        $uploader = new \Atom\FileSytem\BrowserUploadManager([
            'upload_dir' => __DIR__ . '/../../runtime/tempUpload/',
            'temp_dir' => __DIR__ . '/../../runtime/temp/',
            'allow_chunked' => true,
            'chunk_size' => 100 * 1024,
        ]);

        $action = $_GET['action'] ?? '';

        header('Content-Type: application/json; charset=utf-8');

        switch ($action) {
            case 'start':
                echo json_encode(
                    $uploader->startChunkSession(
                        $_POST['filename'] ?? 'file.bin',
                        (int)($_POST['total_size'] ?? 0),
                        (int)($_POST['total_chunks'] ?? 0),
                        ['title' => $_POST['title'] ?? '']
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                );
                break;

            case 'chunk':
                echo json_encode(
                    $uploader->saveChunkFromRequest($_POST['upload_id'] ?? ''),
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                );
                break;

            case 'finalize':
                echo json_encode(
                    $uploader->finalizeChunk($_POST['upload_id'] ?? ''),
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                );
                break;

            case 'pause':
                echo json_encode($uploader->pause($_POST['upload_id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;

            case 'resume':
                echo json_encode($uploader->resume($_POST['upload_id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;

            case 'cancel':
                echo json_encode($uploader->cancel($_POST['upload_id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;

            case 'status':
                echo json_encode($uploader->status($_GET['upload_id'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;

            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown action.'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        exit();
    }

    public function subscribe () {
        header('Content-Type: application/json; charset=utf-8');

        $raw = file_get_contents('php://input');

        if (!$raw) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'error' => 'Empty body',
            ]);

            exit;
        }

        $data = json_decode($raw, true);

        if (!$data) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'error' => 'Invalid JSON',
            ]);

            exit;
        }

        $subscriptionsFile = __DIR__ . '/../../runtime/temp/subscriptions.json';

        $list = [];

        if (is_file($subscriptionsFile)) {
            $list = json_decode(
                file_get_contents($subscriptionsFile),
                true
            ) ?: [];
        }

        $exists = false;

        foreach ($list as $item) {
            if (($item['endpoint'] ?? null) === ($data['endpoint'] ?? null)) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $list[] = $data;

            file_put_contents(
                $subscriptionsFile,
                json_encode(
                    $list,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            );
        }

        echo json_encode([
            'success' => true,
        ]);

        exit();
    }

    public function push () {
        $subscriptions = json_decode(
            file_get_contents(__DIR__ . '/../../runtime/temp/subscriptions.json'),
            true
        );

        $webPush = new WebPushAdapter([
            'VAPID' => [
                'subject' => Atom::$app->config->get("app")['webPush']['mail'],
                'publicKey' => Atom::$app->config->get("app")['webPush']['publicKey'],
                'privateKey' => Atom::$app->config->get("app")['webPush']['privateKey'],
            ],
        ]);

        foreach ($subscriptions as $item) {

            $subscription = WebPushAdapter::subscription($item);

            $webPush->sendText (
                $subscription,
                'Push it works 🚀',
                'This is a test notification (web push).',
                ['url' => 'https://example.com']
            );
        }

        foreach ($webPush->flush() as $report) {

            if ($report->isSuccess()) {
                echo 'OK' . PHP_EOL;
            } else {
                echo $report->getReason() . PHP_EOL;
            }
        }

        exit();
    }

    public function webPush () {
        return $this->render('webPush');
    }
}
