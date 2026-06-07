<?php

declare(strict_types=1);

namespace Atom;

class View
{
    public string $title = '';

    public function renderView($view, array $params)
    {
        $layoutName = Atom::$app->layout;
        if (Atom::$app->controller) {
            $layoutName = Atom::$app->controller->layout;
        }
        $viewContent = $this->renderViewOnly($view, $params);
        ob_start();
        include_once rtrim(Atom::$ROOT_DIR, "/") . "/resources/Views/Layouts/$layoutName.php";
        $layoutContent = ob_get_clean();
        return str_replace('{{content}}', $viewContent, $layoutContent);
    }

    public function renderViewOnly($view, array $params)
    {
        foreach ($params as $key => $value) {
            $$key = $value;
        }
        ob_start();
        include_once rtrim(Atom::$ROOT_DIR, "/") . "/resources/Views/$view.php";
        return ob_get_clean();
    }
}
