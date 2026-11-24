# 依赖与素材权利

应用许可证为 Apache-2.0。源码发行包含原生 PHP 模块、SQL 迁移、工作台模板、部署文件、文档和合成演示数据。运行时没有额外的 Composer 或 JavaScript 包。

| 组件 | 用途 | 许可与来源 |
| --- | --- | --- |
| PHP 与 PDO、PDO MySQL | PHP 8.2+ 应用运行时和 MySQL 驱动。容器构建使用 `php:8.4-apache`。 | PHP 8.4 分支为 PHP License 3.01，分发包中的组件以各自声明为准。[PHP 8.4 许可证](https://github.com/php/php-src/blob/PHP-8.4/LICENSE) · [PHP 官方镜像](https://hub.docker.com/_/php) |
| MySQL 8.4 Community | 数据库服务。Compose 通过上游 `mysql:8.4` 镜像安装。 | GPLv2 与上游许可证列明的附加授权。[MySQL 8.4 许可声明](https://github.com/mysql/mysql-server/blob/8.4/LICENSE) · [MySQL 官方镜像](https://hub.docker.com/_/mysql) |
| Apache HTTP Server | 容器内的 HTTP 服务。 | Apache-2.0。[Apache 软件基金会许可](https://www.apache.org/licenses/) |
| GitHub Actions Checkout v6 | 持续集成检出源码。 | MIT。[Checkout v6 许可证](https://github.com/actions/checkout/blob/v6/LICENSE) |

| 素材 | 来源与用途 | 权利 |
| --- | --- | --- |
| 演示客户、供应商、设备、订单与成本数据 | 项目内合成，用于本地复现实物和财务流程 | 随应用按 Apache-2.0 发行 |
| 工作台 CSS 和界面字符 | 项目源码与 Unicode 文字符号 | 源码按 Apache-2.0 发行 |
| 字体 | 浏览器与宿主环境提供的系统字体 | 由宿主字体许可约束，源码包不含字体文件 |
| 图像、视频、音频和模型参数 | 源码发行无此类素材 | 无捆绑素材 |

Compose 在部署环境拉取上游镜像并本地构建。派生容器的发行需保留其所含组件的许可证和声明。
