# PRD 功能核对

下表按 [业务与交互 PRD](requirements/02-业务与交互-prd.md) 的编号定位实现和回归。16 条编号用例、运行环境、容量及恢复记录见[业务验收记录](validation/README.md)。

| 编号 | 功能及实现 | 验证 |
| --- | --- | --- |
| F01 | 集团、独立法人账套与岗位授权 [身份](../src/Identity.php) · [主数据](../src/MasterData.php) | [工作台及跨法人隔离](../tests/http-workspace.php) |
| F02 | 物料、组织供应策略和单位换算 [主数据](../src/MasterData.php) | [主数据与采购](../tests/planning-purchasing.php) |
| F03 | 选择有效单位版本并保存交易快照 [主数据](../src/MasterData.php) · [采购](../src/Purchasing.php) | [AC-01、02](validation/README.md) |
| F04 | 客户订单及需求行 [销售](../src/Sales.php) | [主数据与采购](../tests/planning-purchasing.php) |
| F05 | 分批交期、批次分配、部分发货 [销售](../src/Sales.php) · [交付](../src/Shipping.php) | [AC-03、13](validation/README.md) |
| F06 | 冻结需求、取消及下游处置 [销售](../src/Sales.php) | [AC-04](validation/README.md) |
| F07 | 多级 BOM、生效版本、损耗和循环路径校验 [产品工程](../src/ProductEngineering.php) | [AC-05、06](validation/README.md) |
| F08 | 替代料及领用审批 [产品工程](../src/ProductEngineering.php) · [工单材料](../src/WorkMaterials.php) | [AC-12、15](validation/README.md) |
| F09 | 工艺路线、工序标准工时和工单版本快照 [产品工程](../src/ProductEngineering.php) · [工单](../src/WorkOrders.php) | [车间](../tests/workshop.php) · [交付](../tests/delivery.php) |
| F10 | 基于需用日的现存扣减和在途抵扣 [净需求](../src/PlanningNetter.php) | [AC-07、08](validation/README.md) |
| F11 | 供需追溯、重算差异和冻结供给 [计划](../src/Planning.php) · [承诺](../src/PlanningCommitments.php) | [主数据与采购](../tests/planning-purchasing.php) |
| F12 | 采购、制造和委外建议关联后续单据 [计划](../src/Planning.php) | [主数据与采购](../tests/planning-purchasing.php) · [委外](../tests/subcontract.php) |
| F13 | 询价、报价、定标和采购单 [询价](../src/Sourcing.php) · [采购](../src/Purchasing.php) | [主数据与采购](../tests/planning-purchasing.php) · [工作台](../tests/http-workspace.php) |
| F14 | 分批收货、待检与收货幂等 [采购](../src/Purchasing.php) | [AC-09、10](validation/README.md) |
| F15 | 库位交易和正反批次谱系 [库存流水](../src/StockLedger.php) · [批次谱系](../src/Genealogy.php) | [AC-14](validation/README.md) |
| F16 | 班次、机台争用、实际工时 [排程](../src/Scheduling.php) · [工序执行](../src/OperationExecution.php) | [AC-11](validation/README.md) · [交付](../tests/delivery.php) |
| F17 | 领退料、超领授权和关闭检查 [工单材料](../src/WorkMaterials.php) · [工单结案](../src/WorkClosing.php) | [AC-12](validation/README.md) · [车间](../tests/workshop.php) |
| F18 | 委外发料、回收、留存差异及加工费匹配 [委外](../src/Subcontracting.php) · [委外材料](../src/SubcontractMaterials.php) | [AC-12](validation/README.md) · [委外](../tests/subcontract.php) |
| F19 | 完工报检和版本化质量规则 [完工](../src/Completion.php) · [质检](../src/Inspection.php) | [交付](../tests/delivery.php) · [扩展点](../tests/extensions.php) |
| F20 | 部分放行、不合格品及关联返工 [完工](../src/Completion.php) · [返工](../src/ReworkLedger.php) | [AC-13](validation/README.md) |
| F21 | 成品预留、发货及问题批次客户回溯 [交付](../src/Shipping.php) · [批次谱系](../src/Genealogy.php) | [AC-13、14](validation/README.md) |
| F22 | 实际用料、人工、费用和委外加工归集 [工单成本](../src/WorkCosts.php) · [工序执行](../src/OperationExecution.php) | [AC-15](validation/README.md) · [委外](../tests/subcontract.php) |
| F23 | 发票匹配、应收应付及核销 [应收应付](../src/ReceivablesPayables.php) | [财务](../tests/finance.php) |
| F24 | 跨法人配对凭证、关期与差异 [法人往来](../src/Intercompany.php) · [总账](../src/GeneralLedger.php) · [关期](../src/FiscalClose.php) | [AC-16](validation/README.md) |

## 工作台操作

- 计划员可就当前算例或草稿工单指定限时审批代办，单据详情显示有效委托。代办仍需有原对象的查看权限，审批后记录代办人和委托人。授予、撤销、过期、跨法人拒绝及执行审计见 [审批委托](../src/ApprovalDelegations.php) 和 [HTTP 回归](../tests/http-workspace.php)。
- 批量任务可按原输入重试选中的失败行，处理回执保留选集、被拒行和原因。操作回执可在网络响应丢失时重新读取，同一请求键不会重复执行。修正单行后重试及原有全量重试入口均保留。见 [批量任务](../src/BatchJobs.php) 和 [HTTP 回归](../tests/http-workspace.php)。
- 外部收货回执通过合成供应商与持久化任务验证等待、到账匹配、拒绝、取消和审计。见 [运行与故障恢复](deployment.md)。
