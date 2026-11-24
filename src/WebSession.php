<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class WebSession
{
    public function __construct(private readonly Database $db)
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('ManufacturingERP');
        $secure = getenv('ERP_SECURE_COOKIES');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure === false ? ($_SERVER['HTTPS'] ?? '') === 'on' : $secure === '1',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_cache_limiter('');
        session_start();
        $now = time();
        if ($this->user() !== null) {
            if ((int) ($_SESSION['seen'] ?? 0) < $now - 1800 || (int) ($_SESSION['signed_in'] ?? 0) < $now - 28_800) {
                $this->reset();
            } else {
                $_SESSION['seen'] = $now;
            }
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function user(): ?int
    {
        return $_SESSION['user'] ?? null;
    }

    public function csrf(): string
    {
        return $_SESSION['csrf'];
    }

    public function checkCsrf(mixed $token): void
    {
        Value::assert(is_string($token) && hash_equals($this->csrf(), $token), '会话校验已过期，请刷新页面后再提交。', 403);
    }

    public function signIn(string $address, string $email, string $password): void
    {
        $window = intdiv(time(), 900) * 900;
        $key = hash('sha256', $address);
        $allowed = $this->db->transaction(function () use ($key, $window): bool {
            $this->db->run('INSERT INTO login_throttle (address_digest,window_start,attempts) VALUES (?,?,1) AS window_entry
                ON DUPLICATE KEY UPDATE attempts=IF(login_throttle.window_start=window_entry.window_start,LEAST(login_throttle.attempts+1,41),1),
                window_start=window_entry.window_start', [$key, $window]);
            $state = $this->db->row('SELECT attempts FROM login_throttle WHERE address_digest=?', [$key]);
            return (int) $state['attempts'] <= 40;
        });
        Value::assert($allowed, '登录请求过多，请在当前 15 分钟窗口结束后再试。', 429);

        $identity = new Identity($this->db);
        $user = $identity->authenticate($email, $password);
        $organizations = $identity->organizations($user);
        Value::assert($organizations !== [], '此账号没有启用的法人账套。', 403);
        $this->db->run('DELETE FROM login_throttle WHERE window_start<? ORDER BY window_start LIMIT 40', [$window - 86_400]);
        $this->reset();
        $_SESSION['user'] = $user;
        $_SESSION['org'] = (int) $organizations[0]['id'];
        $_SESSION['seen'] = $_SESSION['signed_in'] = time();
    }

    public function switchOrganization(int $org): Scope
    {
        $user = $this->user();
        Value::assert($user !== null, '请先登录。', 401);
        $scope = (new Identity($this->db))->scope($user, $org);
        $_SESSION['org'] = $org;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        session_regenerate_id(true);
        return $scope;
    }

    public function context(): array
    {
        $user = $this->user();
        Value::assert($user !== null, '请先登录。', 401);
        $identity = new Identity($this->db);
        $organizations = $identity->organizations($user);
        if ($organizations === []) {
            $this->reset();
            throw new BusinessError('账套访问权限已收回，请重新登录。', 401);
        }
        $registered = array_map('intval', array_column($organizations, 'id'));
        if (!in_array($_SESSION['org'] ?? 0, $registered, true)) {
            $this->switchOrganization($registered[0]);
        }
        return [$identity->scope($user, $_SESSION['org']), $organizations];
    }

    public function reset(): void
    {
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        session_regenerate_id(true);
    }

    public function flash(?array $notification = null): ?array
    {
        if ($notification !== null) {
            $_SESSION['notification'] = $notification;
            return null;
        }
        $current = $_SESSION['notification'] ?? null;
        unset($_SESSION['notification']);
        return $current;
    }
}
