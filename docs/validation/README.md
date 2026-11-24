# 业务验收记录

在 Windows x64、PHP 8.2.30、MySQL Community 8.4.13 上从空白数据库执行 13 个迁移。使用回环 PHP 服务运行 HTML 表单、JSON、会话与 CSV 回归。实际 MySQL 事务参与每个业务套件。集成执行共通过 571 项断言，其中 HTTP 225 项，版本扩展 16 项。

在 Ubuntu 24.04 的干净运行器上，Compose 另行构建 PHP 8.4 与 Apache 镜像，等待 MySQL 8.4、迁移、HTTP 健康检查和后台工作进程就绪，再运行同一套回归。[容器验证记录](https://github.com/axin2024/manufacturing-erp/actions/runs/45421982090)

| 用例 | 实测结果 | 回归代码 |
| --- | --- | --- |
| AC-01 | 两箱按 10 件每箱入库。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-02 | 发布未来换算率后，旧批次仍引用原版本，结存为 20 件。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-03 | 同一销售单拆成两次交付，冻结需求合计 100 台。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-04 | 取消已投产的交期后，处置项仍指向原工单及领料单。 | [车间](../../tests/workshop.php) |
| AC-05 | 每台耗用 2 件，100 台展开为 200 件。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-06 | 循环 BOM 被拒绝，并返回产品、框架、接头到产品的完整路径。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-07 | 需求 200 件，扣减 70 件现货及 30 件按期在途，净缺 100 件。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-08 | 晚到和未确认供给都不会冲抵本期短缺。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-09 | 分批收货合计 100 件，95 件放行，5 件仍列待检。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-10 | 相同收货请求返回原回执，采购单仍只有两次收货，放行数量保持 35 件。 | [主数据与采购](../../tests/planning-purchasing.php) |
| AC-11 | 第二张工单争用同一机台时间段时返回冲突并附原工单号。 | [车间](../../tests/workshop.php) |
| AC-12 | 超领需审批。委外留存的 5 件分列为结案差异，能追到委外发料单。 | [车间](../../tests/workshop.php) · [委外](../../tests/subcontract.php) |
| AC-13 | 100 台完工，2 台转返工，首批只能发出 98 台。 | [交付](../../tests/delivery.php) |
| AC-14 | 问题原料下游查询定位到对应成品批次、发货单与客户。 | [交付](../../tests/delivery.php) |
| AC-15 | 独立成本用例替代料差额 200 元并保留领料编号。综合演示另用每件差 2 元、替代 10 件的输入，差额为 20 元。 | [车间](../../tests/workshop.php) · [综合演示](../../tests/demo.php) |
| AC-16 | 构造历史单边凭证后，关账报告能定位未配对调拨。补回对方凭证后双方各自关账。 | [总账](../../tests/finance.php) |

浏览器接口回归提交了含两个不连续表单行的收货、带替代物料的 BOM 版本、入库与领退料、工单日历、跨法人成对调拨、核销及关期。换账套后旧 CSRF 令牌无效，撤销最后一个岗位会让下一次请求退出会话。其他法人尝试读取单据、回执或下载追溯 CSV 时，接口不返回对应对象。工作进程回归模拟过账中断，库存、会计凭证和行回执一起回滚，随后按原任务重试。[HTTP](../../tests/http-workspace.php) · [批量任务](../../tests/batch-jobs.php)

## 容量

使用单工作进程，向一个法人录入 20 张销售单、合计 1,000 条需求和三级 BOM。试算含一次预热、三次正式样本。实测 MySQL 缓冲池 128 MiB，PHP 内存上限 128 MiB。正式样本共输出 4,000 条需求行、3,650 条建议和 350 条供需关联，每次结果一致。测量时间、实际峰值、数量和差异见原始文件。[MRP 容量实测](mrp-capacity.json)

## 升级和恢复

以前一应用提交从空库建 12 个迁移，导入演示并关账。在同一库应用第 13 个迁移，使用当前应用读取原账套、供需、实际发货、批次追溯、应收应付、请求回执和关账快照。再用 `mysqldump --single-transaction` 导出到文件并恢复到另一个空库。三次读取的关键字段和关账 SHA-256 完全一致。[恢复原始记录](recovery.json)
