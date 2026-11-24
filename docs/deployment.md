# 安装与维护

## 本机安装

需要 64 位 PHP 8.2 或更高版本、`pdo_mysql` 和 MySQL 8.4。Composer 可用于开发工具和依赖检查。应用自带自动加载器，运行时不需要第三方 PHP 包。

创建一个独立的空库和专用账号。以下示例从 MySQL 管理员会话执行，实际密码由部署方生成。

```sql
CREATE DATABASE manufacturing_erp CHARACTER SET utf8mb4;
CREATE USER 'erp'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-generated-password';
GRANT ALL PRIVILEGES ON manufacturing_erp.* TO 'erp'@'127.0.0.1';
```

把以下环境变量交给 PHP 进程。Windows PowerShell 使用 `$env:ERP_DSN = '...'` 方式设置。原生命令读取进程环境，Compose 默认从目录中的 `.env` 读取变量。

| 变量 | 用途 |
| --- | --- |
| `ERP_DSN` | 原生 PHP 的 MySQL DSN。例如 `mysql:host=127.0.0.1;port=3306;dbname=manufacturing_erp;charset=utf8mb4`。Compose 自带容器内 DSN。 |
| `ERP_DB_USER` | 专用数据库账号。Compose 使用 `erp`。 |
| `ERP_DB_PASSWORD` | 专用数据库账号密码。 |
| `ERP_MYSQL_ROOT_PASSWORD` | Compose 初始化容器数据库使用的管理员密码。 |
| `ERP_HTTP_PORT` | Compose 在宿主机的回环监听端口，默认为 `8080`。 |
| `ERP_SECURE_COOKIES` | 回环 HTTP 演示用 `0`。启用 HTTPS 时设置为 `1`。 |
| `ERP_ADMIN_PASSWORD` | 执行 `init` 时传入的初始管理员密码，12 至 72 字节。 |
| `ERP_DEMO_PASSWORD` | 执行 `demo` 时传入的演示密码，12 至 72 字节。 |
| `ERP_EXTENSIONS_FILE` | 可选。部署方安装的 PHP 扩展文件绝对路径。 |

```sh
php bin/erp.php migrate
php bin/erp.php health
php -d post_max_size=32M -d max_input_vars=22000 -S 127.0.0.1:8080 -t public
```

在另一个进程执行 `php bin/erp.php worker`。开发调试可用 `worker --once` 处理一行，或用 `worker --drain` 处理完当前待执行行。生产部署将 Web 文档根目录设置为 `public/`，通过 HTTPS 终止代理提供访问，计划进程由服务管理器监控。开发服务器绑定回环地址。

创建初始账套和管理员时，先以临时进程变量设置密码，再运行下面的命令。Compose 里在 `docker compose exec web sh` 内执行同样的 PHP 命令。

```sh
php bin/erp.php init "示例制造集团" PLANT "示例装配法人" CNY "系统管理员" admin@example.test
```

为主数据维护、销售、采购、仓储、车间、质检、财务分别建立业务账号。权限按当前法人账套发放，浏览器上方的账套菜单只显示已授权法人。需要使用合成数据时，在单独的测试账套执行 `php bin/erp.php demo`。

## HTTP、工作进程与诊断

- `GET /health.php` 返回数据库、迁移与启用扩展的就绪状态。未就绪时返回 HTTP 503。
- 工作进程处理每一行后输出一条 JSON 日志，包含任务号、行号、执行次数和结果状态。工作台「批量任务」保留输入、执行人和行回执。
- 启用较大的多行表单时，PHP 的 `max_input_vars` 至少设置为 22000，`post_max_size` 至少设置为 32M。Compose 自带这两项配置。JSON 接口的请求体最多 16 MiB。
- HTTP 操作的异常日志以故障编号关联到服务端错误。反向代理的访问日志和备用数据库应设为内部可读，排障时使用合成数据。
- 扩展文件需由 Web 和工作进程读取。使用 Compose 时，可用本地覆盖文件给这两个服务挂载同一个只读文件，并设置容器内的绝对路径。

## 备份与恢复

同时保存数据库、应用版本、部署参数和扩展文件。密码通过部署环境的秘密存储恢复。数据库时间和应用展示时戳使用 UTC。

MySQL 客户端可用仅备份账号可读的配置文件登录。配置文件通过部署环境生成，权限由本机操作系统限制。

```sh
mysqldump --defaults-extra-file=/secure/erp-backup.cnf --single-transaction --quick \
  --no-tablespaces --set-gtid-purged=OFF --skip-add-drop-table manufacturing_erp > erp-backup.sql
sha256sum erp-backup.sql
```

默认 Compose 数据库可在 Linux 或 Bash 终端备份。目标文件位于接收命令输出的宿主机。

```sh
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" exec mysqldump -u"$MYSQL_USER" \
  --single-transaction --quick --no-tablespaces --set-gtid-purged=OFF --skip-add-drop-table "$MYSQL_DATABASE"' > erp-backup.sql
```

恢复前创建空的验证库，使用具有该库权限的账号导入。把应用 DSN 指到验证库后启动同版本应用和规则文件，检查健康状态，再核对一条销售、库存、凭证和关账快照。

```sh
mysql --defaults-extra-file=/secure/erp-restore.cnf manufacturing_erp_restore < erp-backup.sql
php bin/erp.php health
```

## 升级

安排变更窗口，停止 Web 写入和工作进程，制作备份并在独立库演练。切换代码版本后执行迁移和健康检查。迁移以文件名和 SHA-256 识别已执行版本，安装过的 SQL 文件要保持原样。确认业务对象与异步行回执可读后恢复服务。

```sh
docker compose stop web worker
docker compose build web
docker compose run --rm migrate
docker compose up -d web worker
```

保留升级前的应用镜像、架构兼容的数据库备份和原扩展文件。若确认须回退，先在独立库恢复旧版备份和旧版应用，核对账套与批次流水，再安排流量切换。MySQL 架构迁移支持向前版本，旧应用遇到更新的数据库历史会拒绝就绪。

## 当前使用范围

应收应付记录业务发票与核销，实际资金收付由外部系统办理。跨法人调拨支持同币种，并生成双方成对的库存与往来记录。月结后按后续开放期间的调整分录处理更正。工作中心班次按具体时间维护。此版本的账套角色控制适用于同一应用实例，Web 会话使用单实例 PHP 会话存储。需要多副本部署时，应先配置共享会话存储并验证会话轮换与撤销。
