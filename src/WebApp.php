<?php

declare(strict_types=1);

namespace ManufacturingErp;

use Throwable;

final class WebApp
{
    private bool $json = false;
    private ?WebSession $session = null;
    private ?Scope $scope = null;
    private array $organizations = [];
    private array $menu = [];
    private string $page = 'home';
    private string $action = '';

    public function handle(): void
    {
        header('Cache-Control: no-store');
        header('Content-Security-Policy: default-src \'self\'; object-src \'none\'; base-uri \'none\'; frame-ancestors \'none\'; form-action \'self\'');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');

        try {
            $this->json = ($_GET['format'] ?? '') === 'json' || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
            $this->page = WebInput::stringParameter($_GET, 'page', 'home');
            $this->action = WebInput::stringParameter($_GET, 'action');
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $planId = null;
            if (preg_match('~^/network-plans/([1-9][0-9]*)/publish$~D', $path, $match)) {
                $this->json = true;
                header('Allow: POST');
                Value::assert($method==='POST','此接口只接受 POST。',405);
                $this->action = 'publish-network-plan';
                $planId = WebInput::integer($match[1],'情景编号');
            } else {
                Value::assert(in_array($path,['/','/index.php'],true),'找不到此页面。',404);
            }
            if (!in_array($method, ['GET','HEAD','POST'], true)) {
                header('Allow: GET, HEAD, POST');
                throw new BusinessError('请求方法不受支持。', 405);
            }
            $db = Database::connect();
            $this->session = new WebSession($db);
            if ($method === 'POST') {
                $input = WebInput::posted();
                if ($planId !== null) {
                    $input['scenario'] = $planId;
                    $input['request_key'] = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $input['request_key'] ?? '';
                }
                $this->post($db, $input);
            } else {
                $this->get($db);
            }
        } catch (BusinessError $error) {
            if ($error->status === 429) {
                header('Retry-After: ' . (900 - time() % 900));
            }
            $this->error($error->getMessage(), $error->status,$error->field);
        } catch (Throwable $error) {
            $ref = bin2hex(random_bytes(6));
            error_log('ERP request ' . $ref . ' failed (' . $error::class . ':' . $error->getCode() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . ')');
            $this->error('服务暂时无法处理请求。故障编号 ' . $ref . '。', 500);
        }
    }

    private function get(Database $db): void
    {
        if ($this->session->user() === null) {
            if ($this->page === 'login' || $this->page === 'session') {
                if ($this->json) {
                    $this->respond(['authenticated' => false, 'csrf' => $this->session->csrf()]);
                } else {
                    $this->render('login', ['title' => '登录']);
                }
            } elseif ($this->json) {
                throw new BusinessError('请先登录。', 401);
            } else {
                $this->redirect(WebView::url('login'));
            }
            return;
        }

        $workspace = $this->workspace($db);
        if ($this->page === 'login' || $this->page === 'session') {
            if ($this->json) {
                $this->respond($this->sessionData());
            } else {
                $this->redirect(WebView::url('home'));
            }
            return;
        }
        if ($this->page === 'receipt') {
            $receipt = (new WebOperations($db, $this->scope))->receipt(WebInput::stringParameter($_GET, 'operation'),
                WebInput::stringParameter($_GET, 'request_key'));
            $this->respond($receipt);
            return;
        }
        if ($this->page === 'export') {
            $this->download($db);
            return;
        }
        $screen = $workspace->screen($this->page, $_GET);
        if ($this->json) {
            $this->respond($screen);
        } else {
            $this->render('workspace', $screen + ['notification' => $this->session->flash()]);
        }
    }

    private function post(Database $db, array $input): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['_csrf'] ?? null);
        $this->session->checkCsrf($token);
        if ($this->action === 'login') {
            $this->session->signIn($_SERVER['REMOTE_ADDR'] ?? 'unknown', WebInput::stringParameter($input, 'email'),
                WebInput::stringParameter($input, 'password'));
            if ($this->json) {
                $this->workspace($db);
                $this->respond($this->sessionData());
            } else {
                $this->redirect(WebView::url('home'));
            }
            return;
        }

        $this->workspace($db);
        $this->session->checkCsrf($token);
        if ($this->action === 'logout') {
            $this->session->reset();
            if ($this->json) {
                $this->respond(['authenticated' => false, 'csrf' => $this->session->csrf()]);
            } else {
                $this->redirect(WebView::url('login'));
            }
            return;
        }
        if ($this->action === 'switch-org') {
            $this->scope = $this->session->switchOrganization(WebInput::integer($input['org_id'] ?? null, '法人账套'));
            if ($this->json) {
                $this->respond($this->sessionData());
            } else {
                $this->redirect(WebView::url('home'));
            }
            return;
        }
        $outcome = (new WebOperations($db, $this->scope))->execute($this->action, $input);
        if ($this->json) {
            $this->respond($outcome['result'], $outcome['status'] ?? 200);
        } else {
            $this->session->flash(['message' => $outcome['message'], 'details' => $outcome['details'] ?? []]);
            $this->redirect($outcome['target']);
        }
    }

    private function workspace(Database $db): Workspace
    {
        [$this->scope, $this->organizations] = $this->session->context();
        $workspace = new Workspace($db, $this->scope);
        $this->menu = $workspace->menu();
        return $workspace;
    }

    private function sessionData(): array
    {
        return ['authenticated' => true, 'csrf' => $this->session->csrf(), 'actor' => $this->scope->actor,
            'organization_id' => $this->scope->org, 'roles' => $this->scope->roles, 'organizations' => $this->organizations];
    }

    private function download(Database $db): void
    {
        $id = isset($_GET['id']) ? WebInput::integer($_GET['id'], '对象编号') : null;
        $filter = WebInput::stringParameter($_GET, 'filter');
        $export = (new Exports($db, $this->scope))->open(WebInput::stringParameter($_GET, 'kind'), $id, $filter === '' ? null : $filter);
        try {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                fpassthru($export['stream']);
            }
        } finally {
            fclose($export['stream']);
        }
    }

    private function error(string $message, int $status, ?string $field = null): void
    {
        if ($this->json) {
            $this->respond(['error' => $message, 'status' => $status]+($field===null?[]:['field'=>$field]), $status);
            return;
        }
        $template = $this->action === 'login' || ($status === 401 && $this->session !== null) ? 'login' : 'error';
        $this->render($template, ['title' => $template === 'login' ? '登录' : '请求未完成', 'error' => $message], $status);
    }

    private function render(string $template, array $screen, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            return;
        }
        $page = $this->page;
        $scope = $this->scope;
        $organizations = $this->organizations;
        $csrf = $this->session?->csrf() ?? '';
        $menu = $this->menu;
        require dirname(__DIR__) . '/templates/layout.php';
    }

    private function respond(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo Value::json($data);
        }
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url, true, 303);
    }
}
