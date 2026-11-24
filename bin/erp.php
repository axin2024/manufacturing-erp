<?php

declare(strict_types=1);

use ManufacturingErp\BusinessError;
use ManufacturingErp\BatchWorker;
use ManufacturingErp\Database;
use ManufacturingErp\Identity;
use ManufacturingErp\Migrations;
use ManufacturingErp\Value;

require dirname(__DIR__) . '/src/bootstrap.php';

$command = $argv[1] ?? 'help';
if ($command === 'help') {
    echo <<<'HELP'
    php bin/erp.php migrate
    php bin/erp.php health
    php bin/erp.php init <集团名称> <法人编码> <法人名称> <本位币> <管理员名称> <邮箱>
    php bin/erp.php worker [--once|--drain]

    初始化密码通过 ERP_ADMIN_PASSWORD 环境变量传入。

    HELP;
    exit(0);
}

try {
    $db = Database::connect();
    $migrations = new Migrations($db, dirname(__DIR__) . '/database/migrations');
    switch ($command) {
        case 'migrate':
            $applied = $migrations->migrate();
            echo $applied === [] ? "Schema is current.\n" : 'Applied: ' . implode(', ', $applied) . "\n";
            break;
        case 'health':
            if (!$migrations->ready()) {
                throw new RuntimeException('Schema is not current.');
            }
            echo "Ready.\n";
            break;
        case 'init':
            Value::assert(count($argv) === 8, '请按 help 中的参数创建集团和管理员。');
            $result = (new Identity($db))->initialize($argv[2], $argv[3], $argv[4], $argv[5], $argv[6], $argv[7],
                (string) getenv('ERP_ADMIN_PASSWORD'));
            echo Value::json($result) . "\n";
            break;
        case 'worker':
            $mode = $argv[2] ?? '';
            Value::assert(count($argv) <= 3 && in_array($mode, ['', '--once', '--drain'], true), 'Worker 参数只支持 --once 或 --drain。');
            Value::assert($migrations->ready(), '请先运行数据库迁移。', 503);
            $worker = new BatchWorker($db);
            do {
                $row = $worker->next();
                if ($row !== null) {
                    echo Value::json($row) . "\n";
                } elseif ($mode === '') {
                    sleep(2);
                }
            } while ($mode === '' || ($mode === '--drain' && $row !== null));
            break;
        default:
            throw new BusinessError('未知命令。请运行 php bin/erp.php help。');
    }
} catch (BusinessError $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, ($command === 'health' ? 'Unavailable.' : $error->getMessage()) . "\n");
    exit(1);
}
