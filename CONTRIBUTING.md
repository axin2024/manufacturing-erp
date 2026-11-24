# 贡献指南

先查看当前代码中的测试和[部署说明](docs/deployment.md)。建议每个改动围绕一个能独立验证的业务场景，说明法人范围、经办岗位、原始单据、状态和数量或金额的预期结果。

## 开发验证

从空白 MySQL 8.4 数据库迁移，确认 `php bin/erp.php health` 就绪。使用可丢弃的测试库设置 `ERP_TEST_DATABASE=1`，按下列顺序验证。

```sh
find bin src templates public examples tests -name '*.php' -print0 | xargs -0 -n 1 php -l
for suite in planning-purchasing workshop delivery subcontract finance batch-jobs demo capacity extensions; do
  php "tests/$suite.php" || exit 1
done
```

HTTP 测试需要同一测试库的回环 Web 服务。启动 `php -S 127.0.0.1:8080 -t public` 后，运行 `ERP_HTTP_URL=http://127.0.0.1:8080 php tests/http-workspace.php`。这些测试会自己推动队列，请暂停此库的常驻工作进程。GitHub 的集成流水线使用 Compose 从空库运行同一组测试。

变更涉及数据库时新增迁移文件，并从上一可用版本的数据库验证对象、回执、金额与月结快照可读。变更涉及单位换算、库存、过账或权限时，请附最小复现和失败路径。分摊与质检规则扩展的提交同时给出内置规则和自定义版本的固定答案。[扩展契约](docs/extensions.md)

提交代码前，清除示例、日志、测试夹具中的真实业务凭据与个人资料。源代码和文档的贡献随项目遵循 [Apache-2.0](LICENSE)。
