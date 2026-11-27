<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LoginForm;
use App\Models\User;
use Atom\Atom;
use Atom\Component\WebPush\WebPushAdapter;
use Atom\Controller;
use Atom\FileSytem\BrowserUploadManager;
use Atom\HttpFoundation\Request;
use Atom\HttpFoundation\Response;
use Atom\Modules\UserAuth\Middlewares\AuthMiddleware;

final class SiteController extends Controller
{
    public function __construct()
    {
        $this->registerMiddleware(new AuthMiddleware(['profile']));
    }

    public function home(): string
    {
        return $this->render('home', [
            'name' => 'The Atom!',
            'title' => 'The Atom Start!',
        ]);
    }

    public function login(Request $request, Response $response): string
    {
        $loginForm = new LoginForm();
        if ($request->isPost()) {
            $request->fillModel($loginForm);
            if ($loginForm->validate() && $loginForm->login()) {
                $response->redirect(Atom::$ROOT_URI);
                return '';
            }
        }

        $this->setLayout('auth');
        return $this->render('login', [
            'model' => $loginForm,
        ]);
    }

    public function register(Request $request, Response $response): string
    {
        $registerModel = new User();
        if ($request->isPost()) {
            $request->fillModel($registerModel);
            if ($registerModel->validate() && $registerModel->save()) {
                Atom::$app->session->setFlash('success', 'Thanks for registering');
                $response->redirect(Atom::$ROOT_URI);
                return 'Show success page';
            }
        }

        $this->setLayout('auth');
        return $this->render('register', [
            'model' => $registerModel,
        ]);
    }

    public function logout(Request $request, Response $response): string
    {
        Atom::$app->account?->logout();
        $response->redirect(Atom::$ROOT_URI);
        return '';
    }

    public function contact(): string
    {
        return $this->render('contact');
    }

    public function profile(): string
    {
        return $this->render('profile');
    }

    public function profileWithId(Request $request, Response $response): string
    {
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return (string) \json_encode([
            'body' => $request->getBody(),
            'routeParams' => $request->getRouteParams(),
        ], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    public function uploads(Request $request): string
    {
        return $this->render('upload');
    }

    public function uploadsMulti(Request $request): string
    {
        return $this->render('uploadMulti');
    }

    public function upload(Request $request, Response $response): string
    {
        $uploader = new BrowserUploadManager([
            'upload_dir' => \dirname(__DIR__, 2) . '/runtime/tempUpload',
            'temp_dir' => \dirname(__DIR__, 2) . '/runtime/temp',
            'max_file_size' => 20 * 1024 * 1024,
            'max_total_size' => 50 * 1024 * 1024,
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf', 'zip'],
            'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf', 'application/zip'],
            'allow_multiple' => true,
            'max_files' => 5,
        ]);

        $body = $request->getBody();
        $result = $uploader->uploadMultipleFromField('file', [
            'title' => (string) ($body['title'] ?? 'No Title'),
        ], $request->getFiles());

        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return (string) \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    public function uploadMulti(Request $request, Response $response): string
    {
        $uploader = new BrowserUploadManager([
            'upload_dir' => \dirname(__DIR__, 2) . '/runtime/tempUpload',
            'temp_dir' => \dirname(__DIR__, 2) . '/runtime/temp',
            'allow_chunked' => true,
            'chunk_size' => 100 * 1024,
        ]);

        $query = $request->getQueryParams();
        $post = $request->getBody();
        $action = (string) ($query['action'] ?? '');

        $payload = match ($action) {
            'start' => $uploader->startChunkSession(
                (string) ($post['filename'] ?? 'file.bin'),
                (int) ($post['total_size'] ?? 0),
                (int) ($post['total_chunks'] ?? 0),
                ['title' => (string) ($post['title'] ?? '')],
            ),
            'chunk' => $uploader->saveChunkFromRequest(
                (string) ($post['upload_id'] ?? ''),
                $post,
                $request->getFiles(),
            ),
            'finalize' => $uploader->finalizeChunk((string) ($post['upload_id'] ?? '')),
            'pause' => $uploader->pause((string) ($post['upload_id'] ?? '')),
            'resume' => $uploader->resume((string) ($post['upload_id'] ?? '')),
            'cancel' => $uploader->cancel((string) ($post['upload_id'] ?? '')),
            'status' => $uploader->status((string) ($query['upload_id'] ?? '')),
            default => ['ok' => false, 'error' => 'Unknown action.'],
        };

        $response->setHeader('Content-Type', 'application/json; charset=utf-8');
        return (string) \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT);
    }

    public function subscribe(Request $request, Response $response): string
    {
        $response->setHeader('Content-Type', 'application/json; charset=utf-8');

        $raw = $request->getRawBody();
        if ($raw === '') {
            $response->statusCode(400);
            return (string) \json_encode(['success' => false, 'error' => 'Empty body']);
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            $response->statusCode(400);
            return (string) \json_encode(['success' => false, 'error' => 'Invalid JSON']);
        }

        $subscriptionsFile = \dirname(__DIR__, 2) . '/runtime/temp/subscriptions.json';
        $subscriptionsDir = \dirname($subscriptionsFile);
        if (!\is_dir($subscriptionsDir)) {
            \mkdir($subscriptionsDir, 0775, true);
        }

        $list = [];
        if (\is_file($subscriptionsFile)) {
            $existing = \file_get_contents($subscriptionsFile);
            $decoded = $existing !== false ? \json_decode($existing, true) : null;
            $list = \is_array($decoded) ? $decoded : [];
        }

        $endpoint = $data['endpoint'] ?? null;
        $exists = \array_any(
            $list,
            static fn(mixed $item): bool => \is_array($item) && ($item['endpoint'] ?? null) === $endpoint,
        );

        if (!$exists) {
            $list[] = $data;
            \file_put_contents(
                $subscriptionsFile,
                (string) \json_encode($list, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                \LOCK_EX,
            );
        }

        return (string) \json_encode(['success' => true]);
    }

    public function push(Request $request, Response $response): string
    {
        $subscriptionsFile = \dirname(__DIR__, 2) . '/runtime/temp/subscriptions.json';
        if (!\is_file($subscriptionsFile)) {
            return 'No subscriptions registered.';
        }

        $raw = \file_get_contents($subscriptionsFile);
        $subscriptions = $raw !== false ? \json_decode($raw, true) : null;
        if (!\is_array($subscriptions)) {
            return 'Invalid subscriptions store.';
        }

        $appCfg = Atom::$app->config->get('app');
        $webPushCfg = \is_array($appCfg['webPush'] ?? null) ? $appCfg['webPush'] : [];

        $webPush = new WebPushAdapter([
            'VAPID' => [
                'subject' => (string) ($webPushCfg['mail'] ?? ''),
                'publicKey' => (string) ($webPushCfg['publicKey'] ?? ''),
                'privateKey' => (string) ($webPushCfg['privateKey'] ?? ''),
            ],
        ]);

        foreach ($subscriptions as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $subscription = WebPushAdapter::subscription($item);
            $webPush->queueText(
                $subscription,
                'Push it works 🚀',
                'This is a test notification (web push).',
                ['url' => 'https://example.com'],
            );
        }

        $lines = [];
        foreach ($webPush->flush() as $report) {
            $lines[] = $report->isSuccess() ? 'OK' : $report->getReason();
        }

        return \implode(\PHP_EOL, $lines);
    }

    public function webPush(): string
    {
        return $this->render('webPush');
    }
}
